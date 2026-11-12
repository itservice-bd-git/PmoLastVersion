<?php

namespace App\Services\Concerns;

use App\Models\EventDateChange;
use App\Models\Project;
use App\Models\ProjectDateReason;
use App\Models\ProjectDelivery;
use App\Models\ProjectFat;
use App\Models\User;
use App\Services\DeliveryChecklist;
use App\Services\EventDates;
use Illuminate\Support\Carbon;

/** ProjectDates: the FAT / delivery rounds and Sub Tasks that move with a project's dates, taking them back, and telling the delivery department. */
trait MovesLinkedDates
{
    /**
     * The FAT and delivery rounds a project's date can take along: a FAT round not held yet (no result), a delivery round
     * not on the road yet - whatever their day, so one whose day has passed waits with the project too (state says เลยกำหนด).
     *
     * @return \Illuminate\Support\Collection<int, array{type: string, id: int, label: string, when: string, state: string, date: string, pending: bool}>
     */
    public static function linkedEvents(Project $project): \Illuminate\Support\Collection
    {
        $today = today();
        $fats = ProjectFat::where('project_id', $project->id)->where('status', ProjectFat::SCHEDULED)->orderBy('round')->get()
            ->map(fn (ProjectFat $f) => ['type' => EventDates::FAT, 'id' => $f->id, 'label' => 'FAT รอบ '.$f->round, 'when' => $f->when(),
                'state' => $f->date_pending_at ? Project::PENDING_LABEL : $f->statusLabel().($f->fat_date->lte($today) ? ' · เลยกำหนด' : ''), 'date' => $f->fat_date->format('Y-m-d'), 'pending' => (bool) $f->date_pending_at, 'billed' => false]);
        $deliveries = ProjectDelivery::where('project_id', $project->id)
            ->whereIn('status', [ProjectDelivery::DRAFT, ProjectDelivery::WAITING, ProjectDelivery::REJECTED, ProjectDelivery::SCHEDULED])->orderBy('round')->get()
            ->map(fn (ProjectDelivery $d) => ['type' => EventDates::DELIVERY, 'id' => $d->id, 'label' => 'ส่งมอบ รอบ '.$d->round, 'when' => $d->when(),
                'state' => $d->date_pending_at ? Project::PENDING_LABEL : ProjectDelivery::statusName($d->status).($d->firstDay()->lte($today) ? ' · เลยกำหนด' : ''), 'date' => $d->firstDay()->format('Y-m-d'), 'pending' => (bool) $d->date_pending_at,
                'billed' => (bool) $d->billed_at]);

        return $fats->concat($deliveries)->values();
    }

    /**
     * Applies the rounds' choices: keep / shift (the project's days) / date (a day of their own) / pending (wait too). Only
     * a round linkedEvents() offers moves; a moved round no longer waits, and its move is kept in its own history.
     *
     * @param  array<int, array{type?: string, id?: int|string, action?: string, date?: ?string}>  $choices
     * @return array<int, array{event: ProjectFat|ProjectDelivery, text: string, pending: bool, before: string}>
     */
    private function moveEvents(Project $project, User $actor, array $choices, int $shift, ?ProjectDateReason $reason, ?string $note, bool $waitOnly = false): array
    {
        $offered = self::linkedEvents($project)->keyBy(fn ($e) => $e['type'].':'.$e['id']);
        $moves = [];
        foreach ($choices as $c) {
            $key = ($c['type'] ?? '').':'.($c['id'] ?? '');
            $action = $c['action'] ?? 'keep';
            if (! $offered->has($key) || $action === 'keep' || ($waitOnly && $action !== 'pending')) {
                continue;
            }
            $e = $c['type'] === EventDates::FAT ? ProjectFat::find($c['id']) : ProjectDelivery::find($c['id']);
            $before = EventDates::snapshot($e);
            $when = $e->when();
            if ($action === 'pending') {
                $was = $e instanceof ProjectDelivery ? $e->status : null;
                if (! $e->date_pending_at) {
                    $e::withoutEvents(fn () => $e->forceFill(['date_pending_at' => now()])->save());
                }
                if ($e instanceof ProjectDelivery && $was !== ProjectDelivery::DRAFT) {
                    $this->takeBack($e, $actor);
                }
                $moves[] = ['event' => $e, 'text' => $offered[$key]['label'].' รอด้วย'.($was && $was !== ProjectDelivery::DRAFT ? ' (ดึงกลับเป็นร่าง)' : ''), 'pending' => true, 'before' => $when, 'was' => $was];

                continue;
            }
            [$startField, $endField] = $e instanceof ProjectFat ? ['fat_date', 'fat_end_date'] : ['delivery_date', 'delivery_end_date'];
            $days = match ($action) {
                'shift' => $shift,
                'date' => ! empty($c['date']) ? (int) $e->{$startField}->diffInDays(Carbon::parse($c['date']), false) : 0,
                default => 0,
            };
            if ($days === 0 && ! $e->date_pending_at) {
                continue;
            }
            $e::withoutEvents(fn () => $e->forceFill([
                $startField => $e->{$startField}->copy()->addDays($days)->format('Y-m-d'),
                $endField => $e->{$endField}?->copy()->addDays($days)->format('Y-m-d'),
                'date_pending_at' => null,
            ])->save());
            app(EventDates::class)->record($e, $before, $actor, ['move_kind' => EventDateChange::POSTPONE, 'move_reason_id' => $reason?->id,
                'move_note' => 'เลื่อนพร้อมวันโครงการ'.($note ? ' — '.$note : '')]);
            $moves[] = ['event' => $e->refresh(), 'text' => $offered[$key]['label'].' '.$when.' → '.$e->when(), 'pending' => false, 'before' => $when];
        }

        return $moves;
    }

    /**
     * A delivery round sent to the delivery department, taken back while its project waits for sales: a draft again, as
     * before PM sent it - its vehicles and people, its packing (and the pack ticks of the cabinets' checklist) and its
     * refusal cleared. PM sends it again with its new day, the usual way (ProjectDeliveryController).
     */
    private function takeBack(ProjectDelivery $d, User $actor): void
    {
        $cabinets = array_map('intval', $d->cabinet_ids ?? []);
        $packed = (bool) $d->packed;
        $d->vehicles()->delete();
        ProjectDelivery::withoutEvents(fn () => $d->forceFill([
            'status' => ProjectDelivery::DRAFT, 'packed' => null, 'scheduled_by' => null, 'scheduled_at' => null,
            'rejected_by' => null, 'rejected_at' => null, 'rejected_reason' => null, 'rejected_note' => null,
        ])->save());
        if ($packed && $cabinets) {
            app(DeliveryChecklist::class)->mark($cabinets, DeliveryChecklist::PACK, false, $actor);
        }
    }

    private function movesText(array $moves): string
    {
        return $moves ? ' · '.implode(' · ', array_column($moves, 'text')) : '';
    }

    /** The delivery department hears of the rounds sent to it that moved or now wait (a draft is not theirs yet). */
    private function tellDelivery(array $moves, User $actor): void
    {
        foreach ($moves as $m) {
            $d = $m['event'];
            if (! $d instanceof ProjectDelivery) {
                continue;
            }
            if ($m['pending']) {   // taken back: no longer theirs until PM sends it again
                if (($m['was'] ?? ProjectDelivery::DRAFT) !== ProjectDelivery::DRAFT) {
                    $this->notifications->deliveryForDepartment($d, $actor, 'ดึงรอบนี้กลับ · '.Project::PENDING_LABEL, todo: false);
                }

                continue;
            }
            if ($d->status === ProjectDelivery::DRAFT) {
                continue;
            }
            $scheduled = $d->status === ProjectDelivery::SCHEDULED;
            $this->notifications->deliveryForDepartment($d, $actor, $scheduled ? 'PM เลื่อนวันส่ง · ตรวจรถ / คนอีกครั้ง' : 'PM เลื่อนวันส่ง', todo: $scheduled);
        }
    }
}
