<?php

namespace App\Policies;

use App\Models\CabinetSubtask;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may change a Sub Task's checklist on the cabinet page (CabinetChecklistController). The rules themselves are the
 * model's (CabinetSubtask::isChecklistEditableBy / canTickBy, also used for the page's buttons); this adds the Thai
 * refusal for each kind of change. Use: Gate::authorize('editChecklist', $subtask).
 */
class CabinetSubtaskPolicy
{
    private const NOT_ACCEPTED = '(ต้องเป็นแผนกที่รับงานแล้วเท่านั้น)';

    public function addChecklist(User $user, CabinetSubtask $subtask): Response
    {
        return $this->editable($user, $subtask, 'คุณไม่มีสิทธิ์เพิ่ม Checklist ของงานนี้ ');
    }

    public function editChecklist(User $user, CabinetSubtask $subtask): Response
    {
        return $this->editable($user, $subtask, 'คุณไม่มีสิทธิ์แก้ไข Checklist นี้ ');
    }

    public function deleteChecklist(User $user, CabinetSubtask $subtask): Response
    {
        return $this->editable($user, $subtask, 'คุณไม่มีสิทธิ์ลบ Checklist นี้ ');
    }

    public function tickChecklist(User $user, CabinetSubtask $subtask): Response
    {
        return $subtask->canTickBy($user) ? Response::allow() : Response::deny('คุณไม่มีสิทธิ์แก้ไข Checklist นี้ '.self::NOT_ACCEPTED);
    }

    private function editable(User $user, CabinetSubtask $subtask, string $refusal): Response
    {
        return $subtask->isChecklistEditableBy($user) ? Response::allow() : Response::deny($refusal.self::NOT_ACCEPTED);
    }
}
