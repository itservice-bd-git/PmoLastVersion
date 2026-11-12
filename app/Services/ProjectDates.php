<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\ProjectFat;
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
 *
 * A project's dates may also be put off with no new ones yet ("pending", รอฝ่ายขายแจ้งกำหนดส่งอีกครั้ง): nothing is counted
 * or chased meanwhile and it is not late anywhere; its FAT and delivery rounds still to come wait with it. When sales has
 * the dates, the postponement that brings them gives the project AND every waiting round its new day, and counts once.
 * A delivery round sent to the delivery department is taken back as a draft while it waits (its vehicles, people and
 * packing cleared; one already billed stays theirs) and PM sends it again with its new day, the usual way.
 * A plain postponement can take the rounds still to come along too (events: keep / shift by the same days / a day of
 * their own / wait); each move is kept in that round's own history (EventDates).
 */
class ProjectDates
{
    use Concerns\MovesLinkedDates;
    use Concerns\DescribesDateChanges;

    public function __construct(private NotificationService $notifications) {}

    /**
     * @param  Project|Cabinet  $subject
     * @param  array{kind: string, due_date: ?string, start_date?: ?string, reason_id?: ?int, note?: ?string, notify?: bool}  $data  validated by the caller
     */
    public function change(Project|Cabinet $subject, User $actor, array $data): ProjectDateChange
    {
        $project = $this->projectOf($subject);
        ProjectLock::assertOpen($project->id);
        if ($data['kind'] === ProjectDateChange::PENDING) {
            return $this->pending($project, $actor, $data);
        }
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

        // the wait this postponement ends (a project's own dates only): every round waiting with it gets its day now
        $waited = $postpone && $subject instanceof Project && $subject->due_pending_at;
        if ($waited) {
            $this->assertWaitingRoundsDated($subject, $data['events'] ?? []);
        }
        $note = trim((string) ($data['note'] ?? '')) ?: null;
        $moves = [];
        $change = DB::transaction(function () use ($subject, $project, $actor, $data, $postpone, $reason, $oldStart, $oldDue, $newStart, $newDue, $waited, $note, &$moves) {
            // the model's own auto log would say the same again without the reason: this change logs it once, below
            $subject::withoutEvents(fn () => $subject->forceFill(['start_date' => $newStart?->format('Y-m-d'), 'due_date' => $newDue?->format('Y-m-d')]
                + ($subject instanceof Project ? ['updated_by' => $actor->id] : [])
                + ($waited ? self::NOT_PENDING : []))->save());

            $change = ProjectDateChange::create([
                'project_id' => $project->id, 'cabinet_id' => $subject instanceof Cabinet ? $subject->id : null,
                'kind' => $postpone ? ProjectDateChange::POSTPONE : ProjectDateChange::CORRECT,
                'old_start_date' => $oldStart, 'new_start_date' => $newStart, 'old_due_date' => $oldDue, 'new_due_date' => $newDue,
                'reason_id' => $reason?->id, 'reason_name' => $reason?->name,
                'note' => $waited ? trim(($note ? $note.' · ' : '').'ได้วันจากฝ่ายขายแล้ว') : $note,
                'changed_by' => $actor->id,
            ]);
            if ($postpone && $subject instanceof Project) {
                $moves = $this->moveEvents($subject, $actor, $data['events'] ?? [], $oldDue && $newDue ? (int) $oldDue->diffInDays($newDue, false) : 0, $reason, $note);
            }

            return $change;
        });

        $rounds = $this->summary($subject)['rounds'];
        ActivityLog::record($project->id, $actor->id, $subject, 'rescheduled', $this->describe($change, $subject, $rounds).$this->movesText($moves), $this->diff($change));
        PlanningBoard::touch();
        if ($postpone && ($data['notify'] ?? true)) {
            $this->notifications->projectRescheduled($project, $actor, $change, $rounds, $subject instanceof Cabinet ? $subject : null);
        }
        $this->tellDelivery($moves, $actor);

        return $change;
    }

    /** What clears a project's wait. */
    public const NOT_PENDING = ['due_pending_at' => null];

    /**
     * "รอฝ่ายขายแจ้งกำหนดส่งอีกครั้ง": the project's dates are put off with no new ones yet - nothing to fill in but why.
     * Not counted, not chased, not late; every FAT and delivery round still to come waits with it. Not a round: the
     * postponement that brings the dates is.
     *
     * @param  array{reason_id: ?int, note: ?string, notify?: bool}  $data
     */
    private function pending(Project $project, User $actor, array $data): ProjectDateChange
    {
        if (! $project->due_date) {
            throw ValidationException::withMessages(['kind' => 'โครงการยังไม่มีวันครบกำหนด · ใส่วันก่อน']);
        }
        if ($project->due_pending_at) {
            throw ValidationException::withMessages(['kind' => 'โครงการนี้รอฝ่ายขายแจ้งกำหนดส่งอยู่แล้ว']);
        }
        $reason = ProjectDateReason::where('is_active', true)->find($data['reason_id'] ?? null);
        if (! $reason) {
            throw ValidationException::withMessages(['reason_id' => 'เลือกสาเหตุ']);
        }
        $note = trim((string) ($data['note'] ?? '')) ?: null;

        $moves = [];
        $change = DB::transaction(function () use ($project, $actor, $reason, $note, &$moves) {
            Project::withoutEvents(fn () => $project->forceFill(['due_pending_at' => now(), 'updated_by' => $actor->id])->save());
            // every round still to come waits too - but a delivery round already billed stays with the delivery department
            $all = self::linkedEvents($project)->reject(fn ($e) => $e['billed'])->map(fn ($e) => ['type' => $e['type'], 'id' => $e['id'], 'action' => 'pending'])->all();
            $moves = $this->moveEvents($project, $actor, $all, 0, $reason, $note, waitOnly: true);

            return ProjectDateChange::create([
                'project_id' => $project->id, 'kind' => ProjectDateChange::PENDING,
                'old_start_date' => $project->start_date, 'new_start_date' => $project->start_date,
                'old_due_date' => $project->due_date, 'new_due_date' => null,
                'reason_id' => $reason->id, 'reason_name' => $reason->name, 'note' => $note, 'changed_by' => $actor->id,
            ]);
        });

        $text = Project::PENDING_LABEL.'อีกครั้ง · '.'วันครบกำหนดเดิม '.$project->due_date->thaiDate().' · สาเหตุ: '.$reason->name.($note ? ' — '.$note : '');
        ActivityLog::record($project->id, $actor->id, $project, 'rescheduled', $text.$this->movesText($moves));
        PlanningBoard::touch();
        if ($data['notify'] ?? true) {
            $this->notifications->projectDatePending($project, $actor, $text);
        }
        $this->tellDelivery($moves, $actor);

        return $change;
    }

    /**
     * Ends a wait: the project and every waiting round keep their old days. Logged, not a round. A delivery round taken back
     * stays a draft: PM sends it again (the usual way).
     */
    public function cancelPending(Project $project, User $actor): void
    {
        ProjectLock::assertOpen($project->id);
        if (! $project->due_pending_at) {
            return;
        }
        DB::transaction(function () use ($project, $actor) {
            Project::withoutEvents(fn () => $project->forceFill(self::NOT_PENDING + ['updated_by' => $actor->id])->save());
            ProjectFat::where('project_id', $project->id)->whereNotNull('date_pending_at')->update(['date_pending_at' => null]);
            ProjectDelivery::where('project_id', $project->id)->whereNotNull('date_pending_at')->update(['date_pending_at' => null]);
        });
        ActivityLog::record($project->id, $actor->id, $project, 'rescheduled', 'ยกเลิกการ'.Project::PENDING_LABEL.' · ใช้วันเดิมทั้งหมด (วันครบกำหนด '.$project->due_date?->thaiDate().')');
        PlanningBoard::touch();
    }

    /** The dates come in: every round waiting with the project needs its day (a day of its own, or the project's days). */
    private function assertWaitingRoundsDated(Project $project, array $choices): void
    {
        $given = collect($choices)->keyBy(fn ($c) => ($c['type'] ?? '').':'.($c['id'] ?? ''));
        $missing = self::linkedEvents($project)->filter(fn ($e) => $e['pending'])->reject(function ($e) use ($given) {
            $c = $given->get($e['type'].':'.$e['id']);

            return $c && (($c['action'] ?? '') === 'shift' || (($c['action'] ?? '') === 'date' && ! empty($c['date'])));
        });
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['events' => 'ใส่วันใหม่ของ '.$missing->pluck('label')->implode(', ').' ด้วย']);
        }
    }

    private function projectOf(Project|Cabinet $subject): Project
    {
        return $subject instanceof Project ? $subject : $subject->project;
    }

}
