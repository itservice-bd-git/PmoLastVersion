<?php

namespace App\Services\Concerns;

use App\Exceptions\AssignmentException;
use App\Models\CabinetSubtask;
use App\Models\Project;
use App\Models\User;
use App\Services\ProjectLock;
use Illuminate\Support\Facades\DB;

/** SubtaskAssignmentService: handing a Sub Task (or several) to a department, or taking it back. */
trait DispatchesWork
{
    /**
     * Returns true when the department actually changed.
     */
    public function changeDepartment(CabinetSubtask $subtask, ?int $departmentId, User $actor): bool
    {
        // Only Admin/PM may assign or reassign a department - checked here (not
        // just hidden in the UI) so the dedicated endpoint can't be driven directly.
        if (! $actor->canDispatchWork()) {
            throw new AssignmentException('เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่มีสิทธิ์เปลี่ยนแผนก', 403);
        }
        $this->assertCanTakeWork($departmentId);

        return DB::transaction(function () use ($subtask, $departmentId, $actor) {
            $locked = CabinetSubtask::whereKey($subtask->id)->lockForUpdate()->firstOrFail();
            ProjectLock::assertOpen($locked->cabinetTask->cabinet->project_id);

            // Re-submitting the current department is a no-op, not an error.
            if ($locked->department_id === $departmentId) {
                $this->syncInstance($subtask, $locked);

                return false;
            }

            if ($locked->is_department_locked) {
                throw new AssignmentException('ไม่สามารถเปลี่ยนแผนกได้ เนื่องจากงานนี้ถูกรับแล้ว');
            }

            $oldDepartmentId = $locked->department_id;

            $locked->forceFill([
                'department_id' => $departmentId,
                'assignment_status' => $departmentId
                    ? CabinetSubtask::ASSIGNMENT_ASSIGNED
                    : CabinetSubtask::ASSIGNMENT_UNASSIGNED,
                'assigned_at' => $departmentId ? now() : null,   // the "waiting since" of the รอรับเกิน n วัน reminder
            ] + ($departmentId ? ['rejected_department_id' => null, 'rejected_by' => null, 'rejected_at' => null, 'rejected_reason' => null] : []))->saveQuietly();   // handed out again: the old "ไม่รับงาน" is history (the log keeps it)

            $oldName = $this->departmentName($oldDepartmentId);
            $newName = $this->departmentName($departmentId);

            $description = match (true) {
                $oldDepartmentId === null => "จ่ายงาน {$this->label($locked)} ให้แผนก {$newName}",
                $departmentId === null => "ยกเลิกการจ่ายงาน {$this->label($locked)} (เดิมแผนก {$oldName})",
                default => "เปลี่ยนแผนกของ {$this->label($locked)} {$oldName} → {$newName}",
            };

            $this->log($locked, $actor, $oldDepartmentId === null ? 'assigned' : 'department_changed', $description, [
                'department_id' => ['label' => 'แผนก', 'old' => $oldName, 'new' => $newName],
            ]);

            $this->syncInstance($subtask, $locked);

            if ($departmentId !== null) {
                $this->notifications->assigned($locked, $actor, moved: $oldDepartmentId !== null);
            }

            return true;
        });
    }

    /**
     * Dispatch many sub tasks at once (a whole step across the project's cabinets, or rows ticked on a cabinet page).
     * Each one goes through changeDepartment() - same lock, same rules, same log line - and the departments get one
     * notice per project instead of one per sub task. Work a department already accepted is skipped and counted,
     * never changed. With $dueDate, every sub task sent (or already with that department) gets it as its due date;
     * the caller checks Settings > Automation's date limits first.
     *
     * @param  list<int>  $subtaskIds
     * @return array{changed: list<CabinetSubtask>, unchanged: int, locked: int, dated: int}
     */
    public function changeDepartments(array $subtaskIds, ?int $departmentId, User $actor, ?string $dueDate = null): array
    {
        if (! $actor->canDispatchWork()) {
            throw new AssignmentException('เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่มีสิทธิ์จ่ายงาน', 403);
        }
        $this->assertCanTakeWork($departmentId);

        $redated = collect();   // already this department's: their date moved, which the "new work" notice does not cover
        $out = $this->notifications->batch(function () use ($subtaskIds, $departmentId, $actor, $dueDate, $redated) {
            $out = ['changed' => [], 'unchanged' => 0, 'locked' => 0, 'dated' => 0];
            foreach (CabinetSubtask::whereKey(array_unique($subtaskIds))->orderBy('id')->get() as $subtask) {
                if ($subtask->is_department_locked && $subtask->department_id !== $departmentId) {
                    $out['locked']++;

                    continue;
                }
                try {
                    $changed = $this->changeDepartment($subtask, $departmentId, $actor);
                } catch (AssignmentException) {
                    $out['locked']++;   // accepted by the department between loading and locking the row

                    continue;
                }
                $changed ? $out['changed'][] = $subtask : $out['unchanged']++;

                if ($dueDate && $departmentId && $subtask->fresh()->due_date?->format('Y-m-d') !== $dueDate) {
                    $subtask->refresh()->update(['due_date' => $dueDate]);   // a normal save: the change log and the closed-project guard apply
                    $out['dated']++;
                    $changed ?: $redated->push($subtask->fresh('cabinetTask.cabinet.project'));
                }
            }

            return $out;
        });
        if ($redated->isNotEmpty()) {
            $this->notifications->dueChanged($redated, $actor);
        }

        return $out;
    }
}
