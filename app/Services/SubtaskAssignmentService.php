<?php

namespace App\Services;

use App\Exceptions\AssignmentException;
use App\Models\ActivityLog;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Department-level assignment workflow for a cabinet Sub Task:
 * UNASSIGNED -> ASSIGNED -> ACCEPTED -> IN_PROGRESS -> COMPLETED.
 *
 * Core rule: the assigned department may only be changed while the status is
 * UNASSIGNED or ASSIGNED. The moment the department accepts, it is locked.
 * Every transition re-reads the row under a lock so two concurrent requests
 * (e.g. "change department" racing "accept") cannot both succeed.
 */
class SubtaskAssignmentService
{
    use Concerns\AssignmentChecks;
    use Concerns\DispatchesWork;

    public function __construct(private NotificationService $notifications) {}

    public function accept(CabinetSubtask $subtask, User $actor): CabinetSubtask
    {
        return $this->transition($subtask, $actor, CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, 'accepted', 'accepted_by', 'accepted_at',
            fn ($s) => "แผนก {$s->department->name} รับงาน {$this->label($s)} แล้ว");
    }

    /** A department member ticked an item of work still waiting for them (Settings > กฎการทำงาน): that accepts it first. */
    public function acceptByTick(CabinetSubtask $subtask, User $actor): bool
    {
        if (! $subtask->tickWouldAccept($actor)) {
            return false;
        }
        $this->transition($subtask, $actor, CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, 'accepted', 'accepted_by', 'accepted_at',
            fn ($s) => "แผนก {$s->department->name} รับงาน {$this->label($s)} แล้ว (จากการติ๊ก Checklist)");

        return true;
    }

    /**
     * "ไม่รับงาน": the department sends work still waiting for it back to PMO with a reason. It becomes unassigned again
     * (with who sent it back and why, until it is handed out again) and whoever handed it out hears it.
     */
    public function reject(CabinetSubtask $subtask, User $actor, string $reason): CabinetSubtask
    {
        $reason = mb_substr(trim($reason), 0, 500);
        if ($reason === '') {
            throw new AssignmentException('ใส่เหตุผลที่ไม่รับงาน');
        }

        return DB::transaction(function () use ($subtask, $actor, $reason) {
            $locked = CabinetSubtask::whereKey($subtask->id)->lockForUpdate()->firstOrFail();
            ProjectLock::assertOpen($locked->cabinetTask->cabinet->project_id);
            if (! $this->canActFor($locked, $actor)) {
                throw new AssignmentException('คุณไม่ใช่สมาชิกแผนกที่ได้รับมอบหมายงานนี้', 403);
            }
            if ($locked->assignment_status !== CabinetSubtask::ASSIGNMENT_ASSIGNED) {
                throw new AssignmentException($locked->assignment_status === CabinetSubtask::ASSIGNMENT_UNASSIGNED
                    ? 'งานนี้ยังไม่ได้จ่ายให้แผนก' : 'ไม่รับงานได้เฉพาะงานที่ยังรอรับ — งานนี้แผนกรับไปแล้ว');
            }
            $department = $locked->department;

            $locked->forceFill([
                'department_id' => null,
                'assignment_status' => CabinetSubtask::ASSIGNMENT_UNASSIGNED,
                'assigned_at' => null,
                'rejected_department_id' => $department?->id,
                'rejected_by' => $actor->id,
                'rejected_at' => now(),
                'rejected_reason' => $reason,
            ])->saveQuietly();

            $this->log($locked, $actor, 'rejected', "แผนก {$department?->name} ไม่รับงาน {$this->label($locked)} — เหตุผล: {$reason}"
                .($this->isInAssignedDepartment($subtask, $actor) ? '' : " (ทำแทนโดย {$actor->name})"), [
                    'department_id' => ['label' => 'แผนก', 'old' => (string) $department?->name, 'new' => '-'],
                ]);
            $this->notifications->rejected($locked, (string) $department?->name, $actor, $reason);
            $this->syncInstance($subtask, $locked);

            return $subtask;
        });
    }

    public function start(CabinetSubtask $subtask, User $actor): CabinetSubtask
    {
        return $this->transition($subtask, $actor, CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS, 'started', 'started_by', 'started_at',
            fn ($s) => "แผนก {$s->department->name} เริ่มงาน {$this->label($s)}");
    }

    public function complete(CabinetSubtask $subtask, User $actor): CabinetSubtask
    {
        // the checklist decides (followChecklist): work with items left unticked is not done, whatever the button says
        if ($subtask->checklists()->where('is_completed', false)->exists()) {
            throw new AssignmentException('ปิดงานไม่ได้ — เช็คลิสต์ยังติ๊กไม่ครบ (งานจะเสร็จเองเมื่อติ๊กครบทุกข้อ)');
        }

        return $this->transition($subtask, $actor, CabinetSubtask::ASSIGNMENT_IN_PROGRESS, CabinetSubtask::ASSIGNMENT_COMPLETED, 'completed', 'completed_by', 'completed_at',
            fn ($s) => "แผนก {$s->department->name} ทำงาน {$this->label($s)} เสร็จแล้ว");
    }

    /**
     * Work whose checklist is all ticked but whose workflow never moved (ticked by someone the tick did not accept it for):
     * walked through รับงาน → เริ่มงาน → เสร็จงาน for its department, each step logged as usual ("ทำแทนโดย" for Admin / PM).
     */
    public function finishTicked(CabinetSubtask $subtask, User $actor): CabinetSubtask
    {
        foreach (['ASSIGNED' => 'accept', 'ACCEPTED' => 'start', 'IN_PROGRESS' => 'complete'] as $from => $step) {
            if ($subtask->fresh()->assignment_status === $from) {
                $this->{$step}($subtask->fresh(), $actor);
            }
        }

        return $subtask->fresh();
    }

    private function transition(CabinetSubtask $subtask, User $actor, string $from, string $to, string $action, string $byColumn, string $atColumn, \Closure $describe): CabinetSubtask
    {
        return DB::transaction(function () use ($subtask, $actor, $from, $to, $action, $byColumn, $atColumn, $describe) {
            $locked = CabinetSubtask::whereKey($subtask->id)->lockForUpdate()->firstOrFail();
            ProjectLock::assertOpen($locked->cabinetTask->cabinet->project_id);

            if (! $this->canActFor($locked, $actor)) {
                throw new AssignmentException('คุณไม่ใช่สมาชิกแผนกที่ได้รับมอบหมายงานนี้', 403);
            }

            if ($locked->assignment_status !== $from) {
                throw new AssignmentException($this->invalidTransitionMessage($locked, $to));
            }

            $locked->forceFill([
                'assignment_status' => $to,
                $byColumn => $actor->id,
                $atColumn => now(),
            ])->saveQuietly();

            $locked->load('department');
            // Admin / PM doing it for the department (Settings > กฎการทำงาน): said so in the log
            $this->log($locked, $actor, $action, $describe($locked).($this->isInAssignedDepartment($locked, $actor) ? '' : " (ทำแทนโดย {$actor->name})"));
            $this->notifications->progressed($locked, $actor, $action);
            if (in_array($to, [CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS], true)) {
                $this->startProject($locked, $actor);
            }

            $this->syncInstance($subtask, $locked);

            return $subtask;
        });
    }

    /**
     * The checklist decides the work's status (ProgressService::recalculateSubtask, after every tick / untick / item added
     * or removed), so the two never disagree:
     *  - every item ticked: เสร็จงานแล้ว, whatever step it was at (handed out or not, accepted or not)
     *  - some ticked while รับงานแล้ว: กำลังดำเนินงาน
     *  - เสร็จงานแล้ว but no longer all ticked: back to the step it really is at - ยังไม่จ่ายงาน without a department,
     *    รอรับงาน if never accepted, else กำลังดำเนินงาน
     * It follows an edit the caller was already allowed to make (canTickBy), so there is no actor check here.
     * Work without a checklist is left to the buttons.
     *
     * @return bool true when the status changed
     */
    public function followChecklist(CabinetSubtask $subtask, ?User $actor): bool
    {
        return DB::transaction(function () use ($subtask, $actor) {
            $locked = CabinetSubtask::whereKey($subtask->id)->lockForUpdate()->first();
            $total = $locked?->checklists()->count() ?? 0;
            if ($total === 0) {
                return false;
            }
            $done = $locked->checklists()->where('is_completed', true)->count();
            $from = $locked->assignment_status;

            [$to, $fill, $action, $words] = match (true) {
                $done === $total && $from !== CabinetSubtask::ASSIGNMENT_COMPLETED => [
                    CabinetSubtask::ASSIGNMENT_COMPLETED,
                    ['completed_by' => $actor?->id, 'completed_at' => now()],
                    'completed', "เสร็จงาน — Checklist ครบ ({$done}/{$total})",
                ],
                $done > 0 && $done < $total && $from === CabinetSubtask::ASSIGNMENT_ACCEPTED => [
                    CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
                    ['started_by' => $actor?->id, 'started_at' => now()],
                    'started', "เริ่มงาน — ติ๊ก Checklist แล้ว ({$done}/{$total})",
                ],
                $done < $total && $from === CabinetSubtask::ASSIGNMENT_COMPLETED => [
                    $this->stepReached($locked),
                    ['completed_by' => null, 'completed_at' => null],
                    'reopened', null,
                ],
                default => [null, [], null, null],
            };
            if ($to === null) {
                return false;
            }

            $locked->forceFill(['assignment_status' => $to] + $fill)->saveQuietly();
            $locked->load('department', 'cabinetTask.cabinet');
            $words ??= 'กลับเป็น'.CabinetSubtask::$assignmentStatuses[$to]." — Checklist ไม่ครบแล้ว ({$done}/{$total})";
            ActivityLog::record($locked->cabinetTask->cabinet->project_id, $actor?->id, $locked, $action, "งาน {$this->label($locked)} {$words}");
            if ($actor && $locked->department_id && $action !== 'reopened') {
                $this->notifications->progressed($locked, $actor, $action);
                $this->startProject($locked, $actor);
            }
            PlanningBoard::touch();
            $this->syncInstance($subtask, $locked);

            return true;
        });
    }

    /** Where work no longer done really is: never handed out, handed out but never accepted, or being worked on. */
    private function stepReached(CabinetSubtask $subtask): string
    {
        return match (true) {
            $subtask->department_id === null => CabinetSubtask::ASSIGNMENT_UNASSIGNED,
            $subtask->accepted_at === null => CabinetSubtask::ASSIGNMENT_ASSIGNED,
            default => CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
        };
    }

    /** The first department taking work: a project still Draft / Planning becomes In Progress (logged; closing stays the PM's call). */
    private function startProject(CabinetSubtask $subtask, User $actor): void
    {
        $project = $subtask->cabinetTask->cabinet->project()->first();
        if (! $project || ! in_array($project->status, [Project::STATUS_DRAFT, Project::STATUS_PLANNING], true)) {
            return;
        }
        $old = Project::$statuses[$project->status];
        Project::withoutEvents(fn () => $project->forceFill(['status' => Project::STATUS_IN_PROGRESS])->save());
        ActivityLog::record($project->id, $actor->id, $project, 'updated',
            "สถานะโครงการ {$old} → ".Project::$statuses[Project::STATUS_IN_PROGRESS]." อัตโนมัติ (แผนก {$subtask->department?->name} รับงานแรก)",
            ['status' => ['label' => 'สถานะ', 'old' => $old, 'new' => Project::$statuses[Project::STATUS_IN_PROGRESS]]]);
        PlanningBoard::touch();
    }

    private function log(CabinetSubtask $subtask, User $actor, string $action, string $description, array $changes = []): void
    {
        ActivityLog::record(
            $subtask->cabinetTask->cabinet->project_id,
            $actor->id,
            $subtask,
            $action,
            $description,
            $changes
        );
    }

    /**
     * Copy the freshly written state back onto the model instance the caller holds.
     */
    private function syncInstance(CabinetSubtask $subtask, CabinetSubtask $locked): void
    {
        $subtask->setRawAttributes($locked->getAttributes(), true);
        $subtask->unsetRelations();
    }
}
