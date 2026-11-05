<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\Vehicle;
use App\Services\DeliveryFlow;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * การจัดส่ง on the project page (and Planning's project window): Admin / PM keep a round as a draft or send it to the
 * delivery department (which cabinets, the kind of vehicle and how many people, the window of days it may go in, where),
 * fix it when it is sent back, or take it back. The steps after
 * that are the delivery department's (DeliveryController). A cabinet belongs to one round at a time; a round that arrives
 * keeps only the cabinets that did, the rest are free to book again.
 */
class ProjectDeliveryController extends Controller
{
    public function __construct(private DeliveryFlow $flow) {}

    /** The window on its own, for Planning's project window (it saves through store() / update() as JSON). */
    public function form(Request $request, Project $project)
    {
        $this->authorizeEdit($request);
        $d = $request->filled('delivery') ? $project->deliveries()->findOrFail($request->integer('delivery')) : null;   // ?delivery=id: that round (Planning's จัดส่ง cards)

        return view('projects.partials._delivery-window', self::windowData($project) + ['f' => self::formData($project, $d)]);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorizeEdit($request);
        $send = $this->sending($request);
        $data = $this->validated($request, $project, null, $send);
        $send ? $this->flow->send($project, $data, $request->user()) : $this->flow->draft($project, $data, $request->user());

        return $this->done($request, $send ? 'ส่งให้แผนกจัดส่งแล้ว' : 'บันทึกร่างการจัดส่งแล้ว');
    }

    public function update(Request $request, ProjectDelivery $delivery)
    {
        $this->authorizeEdit($request);
        $draft = $delivery->status === ProjectDelivery::DRAFT;
        $send = ! $draft || $this->sending($request);   // a round already sent stays sent
        $this->flow->revise($delivery, $this->validated($request, $delivery->project, $delivery, $send), $request->user(), $draft && $send);

        return $this->done($request, match (true) {
            $draft => $send ? 'ส่งให้แผนกจัดส่งแล้ว' : 'บันทึกร่างการจัดส่งแล้ว',
            $delivery->getOriginal('status') === ProjectDelivery::REJECTED => 'ส่งให้แผนกจัดส่งอีกครั้งแล้ว',
            default => 'บันทึกการจัดส่งแล้ว',
        });
    }

    public function destroy(Request $request, ProjectDelivery $delivery)
    {
        $this->authorizeEdit($request);
        $this->flow->cancel($delivery, $request->user());

        return $this->done($request, 'ยกเลิกรอบส่งแล้ว');
    }

    /**
     * The window's own data: the project's cabinets (each with where it already is: arrived / in another round) and the
     * kinds of vehicle to ask for.
     */
    public static function windowData(Project $project): array
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
            'vehicles' => self::vehicles($rounds->pluck('vehicle_id')->filter()->all()),
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
    public static function formData(Project $project, ?ProjectDelivery $d): array
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
            'window_from' => $d?->window_from->format('Y-m-d') ?? '', 'window_to' => $d?->window_to->format('Y-m-d') ?? '',
            'cabinet_ids' => $cabinets, 'method' => $d?->method ?? ProjectDelivery::COMPANY,
            'vehicle_type' => (string) $d?->vehicle_type, 'crew_size' => $d?->crew_size ? (string) $d->crew_size : '', 'carrier' => (string) $d?->carrier,
            'types' => Vehicle::types($d?->vehicle_type),
            'site' => (string) $last?->site, 'contact_name' => (string) $last?->contact_name, 'contact_phone' => (string) $last?->contact_phone, 'note' => (string) $d?->note,
            // what the delivery department did with it: the picked day, or why it was sent back
            'picked' => $d?->delivery_date ? $d->when().' · '.($d->transport() ?? '-').($d->people() ? ' · '.$d->peopleText() : '') : '', 'picked_date' => $d?->delivery_date?->format('Y-m-d') ?? '',
            'rejected' => $d?->status === ProjectDelivery::REJECTED ? ['reason' => $d->rejected_reason, 'note' => (string) $d->rejected_note,
                'by' => (string) $d->rejecter?->name, 'at' => $d->rejected_at?->format('d/m H:i')] : null,
            'locked' => $d && in_array($d->status, [ProjectDelivery::DEPARTED, ProjectDelivery::DELIVERED], true),
            'deleteUrl' => $d ? route('project-deliveries.destroy', $d) : null,
        ];
    }

    private function authorizeEdit(Request $request): void
    {
        abort_unless(DeliveryFlow::canBook($request->user()), 403, 'จัดการการจัดส่งได้เฉพาะ Admin / PM');
    }

    /** The button pressed: "ส่งให้แผนกจัดส่ง" (send=1) or "บันทึกร่าง" (send=0); none = send, as before drafts. */
    private function sending(Request $request): bool
    {
        return ! $request->has('send') || $request->boolean('send');
    }

    /**
     * The fields PM sets. A draft may have no cabinets (= every cabinet) and no kind of vehicle yet; sending needs both
     * (the kind only once Settings > รถขนส่ง has any).
     */
    public static function rules(Project $project, ?ProjectDelivery $current, bool $send, ?string $method): array
    {
        $types = Vehicle::types($current?->vehicle_type);

        return [
            'window_from' => ['required', 'date'],
            'window_to' => ['required', 'date', 'after_or_equal:window_from'],
            'cabinet_ids' => [$send ? 'required' : 'nullable', 'array', $send ? 'min:1' : 'min:0'],
            'cabinet_ids.*' => ['integer', Rule::exists('cabinets', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'method' => ['required', Rule::in(array_keys(ProjectDelivery::METHOD_LABELS))],
            'vehicle_type' => [Rule::requiredIf($send && $method === ProjectDelivery::COMPANY && $types), 'nullable', 'string', Rule::in($types)],
            'crew_size' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }

    public const MESSAGES = [
        'window_from.required' => 'เลือกวันแรกที่ส่งได้',
        'window_to.required' => 'เลือกวันสุดท้ายที่ส่งได้',
        'window_to.after_or_equal' => 'วันสุดท้ายที่ส่งได้ต้องไม่ก่อนวันแรก',
        'cabinet_ids.required' => 'เลือกตู้ที่ส่งรอบนี้อย่างน้อย 1 ตู้',
        'vehicle_type.required' => 'เลือกประเภทรถ',
        'vehicle_type.in' => 'ไม่มีรถประเภทนี้ · เพิ่มได้ที่ ตั้งค่า › รถขนส่ง',
        'crew_size.min' => 'จำนวนคนอย่างน้อย 1 คน',
        'crew_size.max' => 'จำนวนคนไม่เกิน 20 คน',
    ];

    private function validated(Request $request, Project $project, ?ProjectDelivery $current, bool $send): array
    {
        $data = $request->validate(self::rules($project, $current, $send, $request->input('method')) + [
            'carrier' => ['nullable', 'string', 'max:150'],
            'site' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:500'],
        ], self::MESSAGES);

        $ids = array_values(array_unique(array_map('intval', $data['cabinet_ids'] ?? [])));
        // a cabinet goes in one round at a time
        $taken = $project->deliveries()->when($current, fn ($q) => $q->whereKeyNot($current->id))->get()
            ->flatMap(fn ($d) => collect($d->cabinet_ids ?? [])->intersect($ids)->map(fn ($id) => [$id, $d]));
        if ($taken->isNotEmpty()) {
            [$id, $d] = $taken->first();
            $name = Cabinet::whereKey($id)->value('mo_no') ?: 'ตู้';
            throw ValidationException::withMessages(['cabinet_ids' => "{$name} อยู่ในรอบที่ {$d->round} แล้ว".($d->status === ProjectDelivery::DELIVERED ? ' (ส่งถึงแล้ว)' : ' · แก้รอบนั้นก่อน')]);
        }

        $method = $data['method'];
        $text = fn (string $k) => trim((string) ($data[$k] ?? '')) ?: null;
        $company = $method === ProjectDelivery::COMPANY;

        // the vehicle and the people are the delivery department's; a round taken off the company's vehicles drops them
        return [
            'window_from' => $data['window_from'], 'window_to' => $data['window_to'],
            'cabinet_ids' => $ids,
            'method' => $method,
            'vehicle_type' => $company ? $text('vehicle_type') : null,
            'crew_size' => $company && ! empty($data['crew_size']) ? (int) $data['crew_size'] : null,
        ] + ($company ? [] : ['vehicle_id' => null]) + ($method === ProjectDelivery::PICKUP ? ['driver_name' => null, 'crew' => null] : []) + [
            'carrier' => $method === ProjectDelivery::EXTERNAL ? $text('carrier') : null,
            'site' => $text('site'), 'contact_name' => $text('contact_name'), 'contact_phone' => $text('contact_phone'), 'note' => $text('note'),
        ];
    }

    private function done(Request $request, string $message)
    {
        return $request->wantsJson() ? response()->json(['message' => $message]) : back()->with('success', $message);   // JSON: Planning
    }
}
