<?php

namespace App\Services;

use App\Models\EventDateChange;
use App\Models\Project;
use App\Models\ProjectDateReason;
use App\Models\ProjectDelivery;
use App\Models\ProjectFat;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * The history of a round's วัน FAT / วันส่ง, as ProjectDates keeps the project's own dates: every move of a round already
 * set is a row of event_date_changes (who, when, from - to, why). "postpone" counts (เลื่อนครั้งที่ n of that round),
 * "correct" (a date typed wrong) does not. The reason is the list of Settings > สาเหตุการเลื่อน, optional here.
 * Saved by ProjectFatController::update and DeliveryFlow::revise; shown in the project page's FAT / delivery panels.
 */
class EventDates
{
    public const FAT = 'fat';

    public const DELIVERY = 'delivery';

    /** The window's "เลื่อนวัน" fields (move_*), next to the round's own. */
    public static function rules(): array
    {
        return [
            'move_kind' => ['nullable', Rule::in([EventDateChange::POSTPONE, EventDateChange::CORRECT])],
            'move_reason_id' => ['nullable', 'integer'],
            'move_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** A round's days and time as they are now: [first day, last day or null, time or null] ('Y-m-d'). */
    public static function snapshot(ProjectFat|ProjectDelivery $e): array
    {
        return $e instanceof ProjectFat
            ? [$e->fat_date?->format('Y-m-d'), $e->fat_end_date?->format('Y-m-d'), $e->fat_time ?: null]
            : [$e->delivery_date?->format('Y-m-d'), $e->delivery_end_date?->format('Y-m-d'), $e->delivery_time ?: null];
    }

    /** Keeps the move from $before (snapshot) to the round's days now; nothing when they did not change or were not set. */
    public function record(ProjectFat|ProjectDelivery $e, array $before, User $actor, array $move): ?EventDateChange
    {
        $after = self::snapshot($e);
        if ($before === $after || ! $before[0]) {
            return null;
        }
        $correct = ($move['move_kind'] ?? null) === EventDateChange::CORRECT;
        $reason = $correct ? null : ProjectDateReason::find($move['move_reason_id'] ?? null);

        return EventDateChange::create([
            'project_id' => $e->project_id, 'event' => $e instanceof ProjectFat ? self::FAT : self::DELIVERY, 'event_id' => $e->id, 'round' => $e->round,
            'kind' => $correct ? EventDateChange::CORRECT : EventDateChange::POSTPONE,
            'old_date' => $before[0], 'old_end_date' => $before[1], 'old_time' => $before[2],
            'new_date' => $after[0], 'new_end_date' => $after[1], 'new_time' => $after[2],
            'reason_id' => $reason?->id, 'reason_name' => $reason?->name, 'note' => trim((string) ($move['move_note'] ?? '')) ?: null,
            'changed_by' => $actor->id,
        ]);
    }

    /** What the activity log adds after "เลื่อนจาก … เป็น …". */
    public static function why(?EventDateChange $c): string
    {
        if (! $c) {
            return '';
        }

        return ($c->kind === EventDateChange::CORRECT ? ' (แก้วันที่ลงผิด ไม่นับเป็นการเลื่อน)' : '')
            .($c->reason_name ? ' · สาเหตุ: '.$c->reason_name : '').($c->note ? ' — '.$c->note : '');
    }

    /**
     * A project's moves of one kind of event by round id (event_id), newest first, each with its เลื่อนครั้งที่ n.
     *
     * @return Collection<int, Collection<int, EventDateChange>>
     */
    public static function history(Project $project, string $event): Collection
    {
        return EventDateChange::where('project_id', $project->id)->where('event', $event)
            ->with('changedBy:id,name', 'reason:id,color')->orderBy('id')->get()
            ->groupBy('event_id')
            ->map(function (Collection $changes) {
                $n = 0;
                $changes->each(function (EventDateChange $c) use (&$n) {
                    $c->n = $c->kind === EventDateChange::POSTPONE ? ++$n : null;
                });

                return $changes->reverse()->values();
            });
    }

    /** The reasons to pick from (Settings > สาเหตุการเลื่อน). */
    public static function reasons(): Collection
    {
        return ProjectDateReason::where('is_active', true)->orderBy('sequence')->orderBy('id')->get(['id', 'name']);
    }
}
