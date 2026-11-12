<?php

namespace App\Services;

use App\Exceptions\AssignmentException;
use App\Models\ActivityLog;
use App\Models\AppSetting;
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
    use Concerns\DispatchesWork;
    use Concerns\AssignmentChecks;

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
        // Settings > กฎการทำงาน: "งานกดเสร็จไม่ได้ ถ้าเช็คลิสต์ยังไม่ครบ" (only once the work really has a checklist)
        if (AppSetting::get('block_done_needs_checklist') && $subtask->checklists()->where('is_completed', false)->exists()) {
            throw new AssignmentException('ปิดงานไม่ได้ — เช็คลิสต์ยังติ๊กไม่ครบ (ตั้งค่าไว้ที่ ตั้งค่า › กฎการทำงาน)');
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
     * Work marked เสร็จงาน whose checklist is no longer all ticked (an item unticked or added afterwards): back to
     * กำลังดำเนินงาน, so the status always matches the list. It follows an edit the caller was already allowed to make,
     * so there is no actor check here; ticking everything again completes it again (Settings > Automation).
     */
    public function reopenIfUnfinished(CabinetSubtask $subtask, ?User $actor): bool
    {
        if ($subtask->assignment_status !== CabinetSubtask::ASSIGNMENT_COMPLETED) {
            return false;
        }

        return DB::transaction(function () use ($subtask, $actor) {
            $locked = CabinetSubtask::whereKey($subtask->id)->lockForUpdate()->first();
            $total = $locked?->checklists()->count() ?? 0;
            $done = $total ? $locked->checklists()->where('is_completed', true)->count() : 0;
            if (! $locked || $locked->assignment_status !== CabinetSubtask::ASSIGNMENT_COMPLETED || $total === 0 || $done === $total) {
                return false;
            }

            $locked->forceFill(['assignment_status' => CabinetSubtask::ASSIGNMENT_IN_PROGRESS, 'completed_by' => null, 'completed_at' => null])->saveQuietly();
            $locked->load('department', 'cabinetTask.cabinet');
            ActivityLog::record($locked->cabinetTask->cabinet->project_id, $actor?->id, $locked, 'reopened',
                "งาน {$this->label($locked)} กลับเป็นกำลังดำเนินงาน — Checklist ไม่ครบแล้ว ({$done}/{$total})");
            PlanningBoard::touch();
            $this->syncInstance($subtask, $locked);

            return true;
        });
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
            "สถานะโครงการ {$old} → In Progress อัตโนมัติ (แผนก {$subtask->department?->name} รับงานแรก)",
            ['status' => ['label' => 'สถานะ', 'old' => $old, 'new' => 'In Progress']]);
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
