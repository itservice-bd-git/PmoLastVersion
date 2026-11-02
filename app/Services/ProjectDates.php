<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectDateChange;
use App\Models\ProjectDateReason;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A project's or a cabinet's start / due date once it has them: moved only here, with a reason (Admin / PM: หน้าโครงการ /
 * หน้าตู้ › "เลื่อน / แก้วัน"). "postpone" moves the due date and counts as a round (เลื่อนครั้งที่ n); "correct" fixes a date
 * typed wrong and does not count. Both are kept (project_date_changes) and logged; a round tells the project's people in
 * charge and its followers.
 */
class ProjectDates
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * @param  Project|Cabinet  $subject
     * @param  array{kind: string, due_date: ?string, start_date?: ?string, reason_id?: ?int, note?: ?string, notify?: bool}  $data  validated by the caller
     */
    public function change(Project|Cabinet $subject, User $actor, array $data): ProjectDateChange
    {
        $project = $this->projectOf($subject);
        ProjectLock::assertOpen($project->id);
        $postpone = $data['kind'] === ProjectDateChange::POSTPONE;
        $oldStart = $subject->start_date?->copy();
        $oldDue = $subject->due_date?->copy();
        $newStart = array_key_exists('start_date', $data) ? ($data['start_date'] ? Carbon::parse($data['start_date']) : null) : $oldStart;
        $newDue = $data['due_date'] ? Carbon::parse($data['due_date']) : null;

        $same = fn (?Carbon $a, ?Carbon $b) => $a?->format('Y-m-d') === $b?->format('Y-m-d');
        if ($postpone && ($newDue === null || $same($oldDue, $newDue))) {
            throw ValidationException::withMessages(['due_date' => 'เลื่อนวัน: ใส่วันครบกำหนดใหม่ที่ไม่ใช่วันเดิม']);
        }
        if ($same($oldStart, $newStart) && $same($oldDue, $newDue)) {
            throw ValidationException::withMessages(['due_date' => 'วันที่ไม่ได้เปลี่ยน']);
        }
        if ($newStart && $newDue && $newDue->lt($newStart)) {
            throw ValidationException::withMessages(['due_date' => 'วันครบกำหนดต้องไม่ก่อนวันเริ่ม']);
        }
        if ($subject instanceof Cabinet && $newDue && $subject->expected_completion_date?->gt($newDue)) {
            throw ValidationException::withMessages(['due_date' => 'วันคาดว่าจะเสร็จของตู้ ('.$subject->expected_completion_date->thaiDate().') เกินกำหนดเสร็จใหม่ — แก้วันคาดว่าจะเสร็จก่อน']);
        }
        $reason = $postpone ? ProjectDateReason::where('is_active', true)->find($data['reason_id'] ?? null) : null;
        if ($postpone && ! $reason) {
            throw ValidationException::withMessages(['reason_id' => 'เลือกสาเหตุการเลื่อน']);
        }

        $change = DB::transaction(function () use ($subject, $project, $actor, $data, $postpone, $reason, $oldStart, $oldDue, $newStart, $newDue) {
            // the model's own auto log would say the same again without the reason: this change logs it once, below
            $subject::withoutEvents(fn () => $subject->forceFill(['start_date' => $newStart?->format('Y-m-d'), 'due_date' => $newDue?->format('Y-m-d')]
                + ($subject instanceof Project ? ['updated_by' => $actor->id] : []))->save());

            return ProjectDateChange::create([
                'project_id' => $project->id, 'cabinet_id' => $subject instanceof Cabinet ? $subject->id : null,
                'kind' => $postpone ? ProjectDateChange::POSTPONE : ProjectDateChange::CORRECT,
                'old_start_date' => $oldStart, 'new_start_date' => $newStart, 'old_due_date' => $oldDue, 'new_due_date' => $newDue,
                'reason_id' => $reason?->id, 'reason_name' => $reason?->name, 'note' => trim((string) ($data['note'] ?? '')) ?: null,
                'changed_by' => $actor->id,
            ]);
        });

        $rounds = $this->summary($subject)['rounds'];
        ActivityLog::record($project->id, $actor->id, $subject, 'rescheduled', $this->describe($change, $subject, $rounds), $this->diff($change));
        PlanningBoard::touch();
        if ($postpone && ($data['notify'] ?? true)) {
            $this->notifications->projectRescheduled($project, $actor, $change, $rounds, $subject instanceof Cabinet ? $subject : null);
        }

        return $change;
    }

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

    private function projectOf(Project|Cabinet $subject): Project
    {
        return $subject instanceof Project ? $subject : $subject->project;
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
