<?php

namespace App\Services\Concerns;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectDateChange;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WorkScope;
use Illuminate\Support\Carbon;

/** ProjectDates: a change said in words (summary, brief, how far it shifted, which departments it touches). */
trait DescribesDateChanges
{
    /**
     * What the page shows: rounds, days moved since the first due date, that first due date, and every change (newest
     * first) with its round number.
     *
     * @return array{rounds: int, days: int, firstDue: ?Carbon, changes: \Illuminate\Support\Collection}
     */
    public function summary(Project|Cabinet $subject): array
    {
        $changes = $subject->dateChanges()->with('changedBy:id,name', 'reason:id,color')->get();
        $round = 0;
        $changes->each(function (ProjectDateChange $c) use (&$round) {
            $c->round = $c->kind === ProjectDateChange::POSTPONE ? ++$round : null;
        });
        $firstDue = $changes->first(fn ($c) => $c->old_due_date)?->old_due_date ?? $subject->due_date;

        return [
            'rounds' => $round,
            'days' => $firstDue && $subject->due_date ? (int) $firstDue->diffInDays($subject->due_date, false) : 0,
            'firstDue' => $firstDue,
            'changes' => $changes->reverse()->values(),
        ];
    }

    /**
     * Planning's "เลื่อน n" chip: the same count as summary() from the subject's changes (oldest first), with each round's
     * reason, newest first. Null until it was postponed at least once.
     */
    public static function brief(\Illuminate\Support\Collection $changes, ?Carbon $due, string $url): ?array
    {
        $rounds = $changes->where('kind', ProjectDateChange::POSTPONE)->values();
        if ($rounds->isEmpty()) {
            return null;
        }
        $firstDue = $changes->first(fn ($c) => $c->old_due_date)?->old_due_date ?? $due;

        return [
            'rounds' => $rounds->count(),
            'days' => $firstDue && $due ? (int) $firstDue->diffInDays($due, false) : 0,
            'firstDue' => $firstDue?->format('Y-m-d'),
            'url' => $url,
            'list' => $rounds->map(fn (ProjectDateChange $c, int $i) => [
                'n' => $i + 1, 'reason' => (string) $c->reason_name, 'note' => (string) $c->note,
                'from' => $c->old_due_date?->format('Y-m-d'), 'to' => $c->new_due_date?->format('Y-m-d'), 'days' => $c->dueDays(),
            ])->reverse()->values()->all(),
        ];
    }

    /** Planning's "เลื่อนทั้งโครงการ" (n days, no reason asked there yet): kept as a round of the project / a cabinet too, so the count stays true. */
    public function shifted(Project|Cabinet $subject, User $actor, ?Carbon $oldStart, ?Carbon $oldDue, int $days): void
    {
        if (! $oldDue && ! $oldStart) {
            return;
        }
        ProjectDateChange::create([
            'project_id' => $this->projectOf($subject)->id, 'cabinet_id' => $subject instanceof Cabinet ? $subject->id : null,
            'kind' => ProjectDateChange::POSTPONE,
            'old_start_date' => $oldStart, 'new_start_date' => $oldStart?->copy()->addDays($days),
            'old_due_date' => $oldDue, 'new_due_date' => $oldDue?->copy()->addDays($days),
            'reason_name' => 'ไม่ระบุ (เลื่อนทั้งโครงการจาก Planning)', 'changed_by' => $actor->id,
        ]);
    }

    /**
     * The departments with work in the project, or in the cabinet (a Sub Task sent to them, as WorkScope: work not yet sent
     * is not theirs to see): who hears of a postponement besides the project's own people
     * (NotificationService::projectRescheduled), named on the form's "แจ้ง" box.
     *
     * @return \Illuminate\Support\Collection<int, Department>
     */
    public static function departments(Project|Cabinet $subject): \Illuminate\Support\Collection
    {
        $ids = CabinetSubtask::whereNotNull('department_id')->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES)
            ->whereHas('cabinetTask.cabinet', fn ($q) => $subject instanceof Cabinet ? $q->whereKey($subject->id) : $q->where('project_id', $subject->id))
            ->distinct()->pluck('department_id');

        return Department::whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }

    private function describe(ProjectDateChange $c, Project|Cabinet $subject, int $rounds): string
    {
        $due = $subject instanceof Cabinet ? 'กำหนดเสร็จ' : 'วันครบกำหนด';
        $parts = [];
        if ($c->old_due_date?->format('Y-m-d') !== $c->new_due_date?->format('Y-m-d')) {
            $days = $c->dueDays();
            $parts[] = $due.' '.($c->old_due_date?->thaiDate() ?? '-').' → '.($c->new_due_date?->thaiDate() ?? '-').($days ? ' ('.($days > 0 ? '+' : '').$days.' วัน)' : '');
        }
        if ($c->old_start_date?->format('Y-m-d') !== $c->new_start_date?->format('Y-m-d')) {
            $parts[] = 'วันเริ่ม '.($c->old_start_date?->thaiDate() ?? '-').' → '.($c->new_start_date?->thaiDate() ?? '-');
        }
        $what = $subject instanceof Cabinet ? 'เลื่อนวันตู้ '.($subject->mo_no ?: $subject->cabinet_name) : 'เลื่อนวันโครงการ';
        $head = $c->kind === ProjectDateChange::POSTPONE ? $what.' ครั้งที่ '.$rounds
            : ($subject instanceof Cabinet ? 'แก้ไขวันที่ตู้ '.($subject->mo_no ?: $subject->cabinet_name).' ที่ลงผิด' : 'แก้ไขวันที่ลงผิด').' (ไม่นับเป็นการเลื่อน)';

        return $head.': '.implode(' · ', $parts).($c->reason_name ? ' · สาเหตุ: '.$c->reason_name : '').($c->note ? ' — '.$c->note : '');
    }

    private function diff(ProjectDateChange $c): array
    {
        $out = [];
        foreach (['start_date' => 'วันเริ่ม', 'due_date' => 'วันครบกำหนด'] as $f => $label) {
            $old = $c->{'old_'.$f}?->thaiDate();
            $new = $c->{'new_'.$f}?->thaiDate();
            if ($old !== $new) {
                $out[$f] = ['label' => $label, 'old' => $old ?? '-', 'new' => $new ?? '-'];
            }
        }

        return $out;
    }
}
