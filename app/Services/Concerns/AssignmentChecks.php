<?php

namespace App\Services\Concerns;

use App\Exceptions\AssignmentException;
use App\Models\AppSetting;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\User;

/** SubtaskAssignmentService: who may do what (the buttons offered, acting for a department) and the words of a refusal. */
trait AssignmentChecks
{
    /**
     * Which workflow buttons this user may currently use on the sub task.
     */
    public function actionsFor(CabinetSubtask $subtask, ?User $user): array
    {
        $inDepartment = $this->canActFor($subtask, $user);

        return [
            'can_accept' => $inDepartment && $subtask->assignment_status === CabinetSubtask::ASSIGNMENT_ASSIGNED,
            'can_reject' => $inDepartment && $subtask->assignment_status === CabinetSubtask::ASSIGNMENT_ASSIGNED,   // "ไม่รับงาน" with a reason
            'can_start' => $inDepartment && $subtask->assignment_status === CabinetSubtask::ASSIGNMENT_ACCEPTED,
            'can_complete' => $inDepartment && $subtask->assignment_status === CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
        ];
    }

    /**
     * State returned to async callers so the UI can re-render the row.
     */
    public function payload(CabinetSubtask $subtask, ?User $user): array
    {
        $subtask->load('department', 'acceptedBy', 'startedBy', 'completedBy');

        return [
            'id' => $subtask->id,
            'department_id' => $subtask->department_id,
            'department_name' => $subtask->department?->name,
            'assignment_status' => $subtask->assignment_status,
            'assignment_status_label' => $subtask->assignment_status_label,
            'is_department_locked' => $subtask->is_department_locked,
            'accepted_by_name' => $subtask->acceptedBy?->name,
            'accepted_at' => $subtask->accepted_at?->thaiDateTime(),
            'started_by_name' => $subtask->startedBy?->name,
            'started_at' => $subtask->started_at?->thaiDateTime(),
            'completed_by_name' => $subtask->completedBy?->name,
            'completed_at' => $subtask->completed_at?->thaiDateTime(),
        ] + $this->actionsFor($subtask, $user);
    }

    /** A department set "ดูได้อย่างเดียว" (Settings > สิทธิ์การใช้งาน, edit.work off) does no work: nothing is handed to it. */
    private function assertCanTakeWork(?int $departmentId): void
    {
        if ($departmentId && ! \App\Support\Access::departmentAllows($departmentId, 'edit.work')) {
            throw new AssignmentException('แผนก '.$this->departmentName($departmentId).' ตั้งเป็น "ดูได้อย่างเดียว" — จ่ายงานให้แผนกนี้ไม่ได้ (ตั้งค่า → สิทธิ์การใช้งาน)', 422);
        }
    }

    /** The department's own members - or Admin / PM when Settings > กฎการทำงาน lets them act for departments. */
    public function canActFor(CabinetSubtask $subtask, ?User $user): bool
    {
        if ($user?->isViewOnly()) {
            return false;
        }

        return $this->isInAssignedDepartment($subtask, $user)
            || ($user !== null && $subtask->department_id !== null && $user->canDispatchWork() && AppSetting::get('pmo_acts_for_departments'));
    }

    private function isInAssignedDepartment(CabinetSubtask $subtask, ?User $user): bool
    {
        return $user !== null
            && $user->inDepartment($subtask->department_id);
    }

    private function invalidTransitionMessage(CabinetSubtask $subtask, string $to): string
    {
        return match (true) {
            $subtask->assignment_status === CabinetSubtask::ASSIGNMENT_UNASSIGNED => 'งานนี้ยังไม่ได้จ่ายให้แผนก',
            $to === CabinetSubtask::ASSIGNMENT_ACCEPTED => 'งานนี้ถูกรับแล้ว',
            $to === CabinetSubtask::ASSIGNMENT_IN_PROGRESS => 'ไม่สามารถเริ่มงานได้ ต้องรับงานก่อน หรืองานนี้เริ่มไปแล้ว',
            default => 'ไม่สามารถปิดงานได้ ต้องเริ่มงานก่อน หรืองานนี้เสร็จไปแล้ว',
        };
    }

    private function label(CabinetSubtask $subtask): string
    {
        $subtask->loadMissing('cabinetTask.cabinet');

        return "\"{$subtask->name}\" ({$subtask->cabinetTask->cabinet->mo_no} / {$subtask->cabinetTask->name})";
    }

    private function departmentName(?int $id): string
    {
        return $id ? (Department::find($id)?->name ?? "#{$id}") : '-';
    }
}
