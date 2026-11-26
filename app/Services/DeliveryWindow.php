<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\EventDateChange;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\Vehicle;

/**
 * The การจัดส่ง window's data (the project page's _delivery and Planning's _delivery-window): the project's cabinets with
 * where each already is, the vehicles to pick from, and a round's starting values. ProjectDeliveryController saves it.
 */
class DeliveryWindow
{
    /**
     * The window's own data: the project's cabinets (each with where it already is: arrived / in another round) and the
     * kinds of vehicle to ask for.
     */
    public static function data(Project $project): array
    {
        $rounds = $project->deliveries()->get();
        $cabinets = $project->cabinets()->orderBy('sequence')->orderBy('id')->get(['id', 'mo_no', 'cabinet_name', 'progress']);

        return [
            'project' => $project,
            'cabinets' => $cabinets->map(function (Cabinet $c) use ($rounds) {
                $in = $rounds->first(fn ($d) => in_array($c->id, $d->cabinet_ids ?? [], true));

                return ['id' => $c->id, 'name' => $c->mo_no ?: $c->cabinet_name, 'progress' => (int) round((float) $c->progress),
                    'round' => $in?->id, 'where' => $in ? ($in->status === ProjectDelivery::DELIVERED ? 'ส่งแล้ว '.$in->delivered_at?->format('d/m') : 'อยู่ในรอบที่ '.$in->round) : null];
            })->values()->all(),
            'vehicles' => self::vehicles(),
            'types' => Vehicle::types(),
        ];
    }

    /** The vehicles to pick from: the active ones, and any of $keep (a round already on one that was switched off). */
    public static function vehicles(array $keep = []): array
    {
        return Vehicle::where('is_active', true)->orWhereIn('id', $keep)->orderBy('sequence')->orderBy('id')->get()
            ->map(fn (Vehicle $v) => ['id' => $v->id, 'label' => $v->label(), 'name' => $v->name, 'type' => (string) $v->type, 'capacity' => $v->capacity, 'equipment' => (string) $v->equipment,
                'driver' => (string) $v->driver_name, 'active' => $v->is_active])->values()->all();
    }

    /** The window's starting values: edit round $d, or book a new one. */
    public static function form(Project $project, ?ProjectDelivery $d): array
    {
        $next = (int) $project->deliveries()->max('round') + 1;
        $last = $d ?? $project->deliveries()->reorder('round', 'desc')->first();   // a new round starts at the same site

        // a draft kept for every cabinet (from the new-project page): the cabinets free to go are ticked, to check before sending
        $cabinets = array_map('intval', $d?->cabinet_ids ?? []);
        if ($d?->status === ProjectDelivery::DRAFT && ! $cabinets) {
            $taken = $project->deliveries()->whereKeyNot($d->id)->get()->flatMap(fn ($r) => $r->cabinet_ids ?? [])->all();
            $cabinets = $project->cabinets()->whereNotIn('id', $taken)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        return [
            'id' => $d?->id, 'status' => $d?->status ?? ProjectDelivery::WAITING, 'draft' => $d?->status === ProjectDelivery::DRAFT, 'all' => $d && ! $d->cabinet_ids,
            'url' => $d ? route('project-deliveries.update', $d) : route('projects.deliveries.store', $project), 'verb' => $d ? 'PUT' : 'POST',
            'title' => $d ? ($d->status === ProjectDelivery::DRAFT ? 'ร่างการจัดส่งรอบที่ ' : 'แก้การจัดส่งรอบที่ ').$d->round : 'นัดส่งรอบที่ '.$next,
            'delivery_date' => $d?->delivery_date?->format('Y-m-d') ?? '', 'delivery_end_date' => $d && $d->lastDay()->gt($d->firstDay()) ? $d->lastDay()->format('Y-m-d') : '',
            'delivery_time' => (string) $d?->delivery_time, 'was' => $d && $d->status !== ProjectDelivery::DRAFT ? $d->firstDay()->format('Y-m-d').'|'.$d->lastDay()->format('Y-m-d') : '', 'was_text' => $d ? $d->when() : '',
            'cabinet_ids' => $cabinets, 'method' => $d?->method ?? ProjectDelivery::COMPANY,
            'vehicle_types' => $d?->vehicle_types ?? [], 'crew_size' => $d?->crew_size ? (string) $d->crew_size : '', 'carrier' => (string) $d?->carrier,
            'types' => Vehicle::types($d?->vehicle_types ?? []),
            'site' => (string) $last?->site, 'map_url' => (string) $last?->map_url, 'contact_name' => (string) $last?->contact_name, 'contact_phone' => (string) $last?->contact_phone, 'note' => (string) $d?->note,
            // what the delivery department did with it: the vehicles and people put on it, or why it was sent back
            'picked' => $d && $d->status === ProjectDelivery::SCHEDULED ? ($d->transport() ?? '-').($d->people() ? ' · '.$d->peopleText() : '') : '',
            'billed' => (bool) $d?->billed_at,
            'rejected' => $d?->status === ProjectDelivery::REJECTED ? ['reason' => $d->rejected_reason, 'note' => (string) $d->rejected_note,
                'by' => (string) $d->rejecter?->name, 'at' => $d->rejected_at?->format('d/m H:i')] : null,
            'locked' => $d && in_array($d->status, [ProjectDelivery::DEPARTED, ProjectDelivery::DELIVERED], true),
            'deleteUrl' => $d ? route('project-deliveries.destroy', $d) : null,
            // a move of a sent round's day: postpone / correct, why (ประวัติการเลื่อน: EventDates)
            'was_time' => (string) $d?->delivery_time,
            'moves' => $d ? EventDateChange::where('event', 'delivery')->where('event_id', $d->id)->where('kind', EventDateChange::POSTPONE)->count() : 0,
            'move_kind' => EventDateChange::POSTPONE, 'move_reason_id' => 0, 'move_note' => '',
        ];
    }
}
