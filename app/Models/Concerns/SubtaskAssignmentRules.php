<?php

namespace App\Models\Concerns;

use App\Models\AppSetting;
use App\Models\Department;
use App\Models\User;

/** CabinetSubtask: the assignment status in words, the department lock, and who may tick or edit its checklist. */
trait SubtaskAssignmentRules
{
    /**
     * The department can only be changed until the department accepts the
     * work - from ACCEPTED onwards it is locked.
     */
    public function getIsDepartmentLockedAttribute(): bool
    {
        return ! in_array($this->assignment_status, self::DEPARTMENT_EDITABLE_STATUSES, true);
    }

    public function getAssignmentStatusLabelAttribute(): string
    {
        return self::$assignmentStatuses[$this->assignment_status] ?? (string) $this->assignment_status;
    }

    /**
     * May tick its items: whoever may edit the list, and (Settings > กฎการทำงาน "ติ๊กแล้วรับงานให้") the department's own
     * members while the work still waits for them - the tick accepts it (SubtaskAssignmentService::acceptByTick).
     */
    public function canTickBy(?User $user): bool
    {
        return $this->isChecklistEditableBy($user) || $this->tickWouldAccept($user);
    }

    /** Admin / PM count too when Settings > กฎการทำงาน lets them act for departments (else their tick left the work "รอรับ"). */
    public function tickWouldAccept(?User $user): bool
    {
        $actsFor = $user !== null && ($user->inDepartment($this->department_id)
            || ($this->department_id !== null && $user->canDispatchWork() && (bool) AppSetting::get('pmo_acts_for_departments')));

        return $actsFor && ! $user->isViewOnly()
            && $this->assignment_status === self::ASSIGNMENT_ASSIGNED && (bool) AppSetting::get('tick_accepts');
    }

    /**
     * Only the department this Sub Task is assigned to may add/tick/remove
     * its checklist items, and only once that department has accepted the
     * work (still ASSIGNED = not accepted yet, so nothing to do yet) -
     * except Admin/PM, who may manage any Sub Task's checklist regardless of
     * department or workflow stage (same administrative-oversight precedent
     * as SubtaskAssignmentService::changeDepartment(), which is likewise
     * gated by canDispatchWork()/canViewOtherDepartments() rather than an
     * exact department match).
     * Single source of truth for this rule - used both to expose the
     * `checklist_editable` flag to the frontend (My Department) and to
     * enforce it server-side (CabinetChecklistController).
     */
    public function isChecklistEditableBy(?User $user): bool
    {
        if ($user === null || $user->isViewOnly()) {
            return false;
        }

        if ($user->canViewOtherDepartments()) {
            return true;
        }

        return $user->inDepartment($this->department_id)   // their own department, or one they look after too
            && in_array($this->assignment_status, self::CHECKLIST_EDITABLE_STATUSES, true);
    }

    /**
     * Initial assignment workflow columns for a freshly created sub task
     * (template apply / cabinet copy): ASSIGNED when a department is given,
     * otherwise UNASSIGNED, with the accept/start/complete trail cleared.
     */
    public static function initialAssignment(?int $departmentId): array
    {
        return [
            'department_id' => $departmentId,
            'assignment_status' => $departmentId ? self::ASSIGNMENT_ASSIGNED : self::ASSIGNMENT_UNASSIGNED,
            'assigned_at' => $departmentId ? now() : null,
            'accepted_by' => null,
            'accepted_at' => null,
            'started_by' => null,
            'started_at' => null,
            'completed_by' => null,
            'completed_at' => null,
        ];
    }
}
