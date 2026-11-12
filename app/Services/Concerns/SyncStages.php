<?php

namespace App\Services\Concerns;

use App\Exceptions\AssignmentException;
use App\Exceptions\PlanningRejected;
use App\Exceptions\ProjectLockedException;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Board;
use App\Models\BoardLabel;
use App\Models\BoardStatus;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\CabinetTemplate;
use App\Models\DateLimitRule;
use App\Models\Department;
use App\Models\DepartmentField;
use App\Models\Project;
use App\Models\ProjectDepartmentStatus;
use App\Models\ProjectFieldValue;
use App\Models\User;
use App\Services\CabinetCopyService;
use App\Services\HandoffService;
use App\Services\ProjectLock;
use App\Services\SharedChecklist;
use App\Services\WorkScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PlanningSync · A department's work in a project (its "stage"): dates, due, done, workflow steps, fields, status, handing sub tasks out, adding / removing a department.
 * Part of PlanningSync (a trait of it), which owns the dispatch (applyOne) and the helpers: id(), projectFor(), requirePmo(),
 * date(), deptSubtasks(), open().
 */
trait SyncStages
{
    /**
     * One start - end range for ALL of a department's open work in the project (the department decides the order inside it;
     * PMO only watches the end date). Date-limit rules hold; finished work keeps its dates.
     */
    private function stageDates(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'กำหนดช่วงวันของแผนก');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        [$start, $due] = [$this->date($op['start'] ?? null), $this->date($op['due'] ?? null)];
        if ($start && $due && $due < $start) {
            throw new PlanningRejected('วันส่งต้องไม่ก่อนวันเริ่ม');
        }
        $open = $this->open($this->deptSubtasks($project, $user, $op['deptId'] ?? null));
        if ($open->isEmpty()) {
            throw new PlanningRejected('ไม่มีงานที่แก้วันได้ (เสร็จแล้วหรือยังไม่ได้จ่ายงาน)');
        }
        foreach ($open as $s) {
            if ($due && ($message = DateLimitRule::violationFor($s->cabinetTask->cabinet_id, $s->department_id, $due))) {
                throw new PlanningRejected($message);
            }
        }
        $moved = $open->filter(fn ($s) => $s->due_date?->format('Y-m-d') !== $due);
        foreach ($open as $s) {
            $s->update(['start_date' => $start, 'due_date' => $due]);
        }
        if ($moved->isNotEmpty()) {
            $this->notifications->dueChanged($moved->each->refresh(), $user);
        }

        return null;
    }

    private function stageDue(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'แก้วันกำหนดส่งของแผนก');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $this->moveDue($this->deptSubtasks($project, $user, $op['deptId'] ?? null)->when(! $user->canDispatchWork(), fn ($c) => $c->whereIn('department_id', $user->departmentIds())), $this->date($op['due'] ?? null), $user);

        return null;
    }

    private function cabinetDue(User $user, array $op, bool $perDept): ?string
    {
        $this->requirePmo($user, 'แก้วันกำหนดส่งของตู้');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $cabinetId = $this->id($op['cabinetId'] ?? null, 'c');

        $subtasks = WorkScope::projectSubtasks($project, $user)
            ->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cabinetId))
            ->when(! $user->canDispatchWork(), fn ($q) => $q->whereIn('department_id', $user->departmentIds()))   // dates stay inside your own department's work, even if you may SEE more
            ->when($perDept, fn ($q) => $q->where('department_id', $this->id($op['deptId'] ?? null, 'd_')))
            ->with('cabinetTask.cabinet')->get();

        $this->moveDue($subtasks, $this->date($op['due'] ?? null), $user);

        return null;
    }

    /** Ticking "เสร็จแล้ว" on a department's stage = that department finishes its Sub Tasks (accept -> start -> complete as needed). */
    private function stageDone(User $user, array $op): ?string
    {
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $open = $this->open($this->deptSubtasks($project, $user, $op['deptId'] ?? null));

        foreach ($open as $s) {
            foreach (['accept', 'start', 'complete'] as $step) {
                if ($s->fresh()->assignment_status === self::STEP_FROM[$step]) {
                    $this->assignments->{$step}($s->fresh(), $user);
                }
            }
        }

        return $open->isEmpty() ? null : 'ทำเครื่องหมายงานของแผนกเป็นเสร็จแล้ว '.$open->count().' รายการ';
    }

    private function stageStep(User $user, array $op): ?string
    {
        $step = (string) ($op['step'] ?? '');
        if (! isset(self::STEP_FROM[$step])) {
            throw new PlanningRejected('ขั้นตอนไม่ถูกต้อง');
        }

        $reason = trim((string) ($op['reason'] ?? ''));
        if ($step === 'reject' && $reason === '') {
            throw new PlanningRejected('ใส่เหตุผลที่ไม่รับงาน');
        }
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $eligible = $this->deptSubtasks($project, $user, $op['deptId'] ?? null)->where('assignment_status', self::STEP_FROM[$step])->values();
        if ($eligible->isEmpty()) {
            throw new PlanningRejected('ไม่มีงานที่ทำรายการนี้ได้ในขณะนี้');
        }

        foreach ($eligible as $s) {
            // refuses (403-style) unless the user belongs to that department
            $step === 'reject' ? $this->assignments->reject($s, $user, $reason) : $this->assignments->{$step}($s, $user);
        }

        return ['accept' => 'รับงาน', 'start' => 'เริ่มงาน', 'complete' => 'ปิดงาน', 'reject' => 'ส่งงานคืน PM'][$step].' '.$eligible->count().' รายการแล้ว';
    }

    /**
     * "เลือกรับ…": accept, or send back with a reason, only the picked pieces of work still waiting for the department (the
     * same service calls as the whole stage; work that moved on meanwhile is skipped and said so).
     */
    private function subtasksStep(User $user, array $op): ?string
    {
        $step = (string) ($op['step'] ?? '');
        if (! in_array($step, ['accept', 'reject'], true)) {
            throw new PlanningRejected('ขั้นตอนไม่ถูกต้อง');
        }
        $reason = trim((string) ($op['reason'] ?? ''));
        if ($step === 'reject' && $reason === '') {
            throw new PlanningRejected('ใส่เหตุผลที่ไม่รับงาน');
        }
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $ids = collect(is_array($op['ids'] ?? null) ? $op['ids'] : [])->map(fn ($k) => $this->id((string) $k, 's'))->unique()->values();
        if ($ids->isEmpty()) {
            throw new PlanningRejected('ยังไม่ได้เลือกงาน');
        }
        $picked = WorkScope::projectSubtasks($project, $user)->whereKey($ids)->with('cabinetTask.cabinet')->orderBy('id')->get();
        if ($picked->count() !== $ids->count()) {
            throw new PlanningRejected('ไม่พบงานบางรายการในโครงการนี้');
        }
        $waiting = $picked->where('assignment_status', CabinetSubtask::ASSIGNMENT_ASSIGNED);
        if ($waiting->isEmpty()) {
            throw new PlanningRejected('งานที่เลือกไม่ได้รอรับแล้ว');
        }
        foreach ($waiting as $s) {
            $step === 'reject' ? $this->assignments->reject($s, $user, $reason) : $this->assignments->accept($s, $user);   // each checks the user's department
        }
        $skipped = $picked->count() - $waiting->count();

        return ($step === 'reject' ? 'ส่งงานคืน PM ' : 'รับงาน ').$waiting->count().' งานแล้ว'.($skipped ? " · ข้าม {$skipped} งานที่ไม่ได้รอรับแล้ว" : '');
    }

    /** One extra field (Settings > ฟิลด์เพิ่มเติม) of a department's part of a project: PMO roles any department's, members their own. */
    private function stageField(User $user, array $op): ?string
    {
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $deptId = $this->id($op['deptId'] ?? null, 'd_');

        if (! $user->canDispatchWork() && ! $user->inDepartment($deptId)) {
            throw new PlanningRejected('แก้ข้อมูลเพิ่มเติมได้เฉพาะของแผนกตัวเอง');
        }
        if (! WorkScope::projectSubtasks($project, $user)->where('department_id', $deptId)->exists()) {
            throw new PlanningRejected('แผนกนี้ไม่มีงานในโครงการนี้');
        }

        $field = DepartmentField::where('department_id', $deptId)->find($this->id($op['fieldId'] ?? null, 'f'))
            ?? throw new PlanningRejected('ไม่พบฟิลด์นี้ (อาจถูกลบไปแล้ว)');
        $raw = is_string($op['value'] ?? null) ? trim($op['value']) : '';

        if ($raw === '') {
            ProjectFieldValue::where(['project_id' => $project->id, 'department_field_id' => $field->id])->delete();
            $shown = '(ว่าง)';
        } else {
            if ($field->type === 'select') {
                $option = $field->options()->find($this->id($raw, 'o')) ?? throw new PlanningRejected('ตัวเลือกไม่ถูกต้อง');
                [$value, $shown] = [(string) $option->id, $option->label];
            } else {
                if ($field->type === 'link' && ! preg_match('#^https?://[^\s]+$#i', $raw)) {
                    throw new PlanningRejected('ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://');
                }
                [$value, $shown] = [mb_substr($raw, 0, 500), mb_substr($raw, 0, 500)];
            }
            ProjectFieldValue::updateOrCreate(['project_id' => $project->id, 'department_field_id' => $field->id], ['value' => $value]);
        }

        ActivityLog::record($project->id, $user->id, $project, 'updated', "ตั้งค่า {$field->name} ของแผนก ".Department::find($deptId)?->name." = {$shown}");

        return null;
    }

    /** Hands sub tasks of this project that have no department yet (e.g. from a template) to departments: { s12: 'd_5', ... }. */
    private function subtaskAssign(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'จ่ายงาน');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $assign = is_array($op['assign'] ?? null) ? $op['assign'] : [];
        $sent = 0;

        foreach ($assign as $key => $value) {
            $subtask = CabinetSubtask::with('cabinetTask')->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $project->id))->find($this->id((string) $key, 's'))
                ?? throw new PlanningRejected('ไม่พบงานย่อยนี้ในโครงการ');
            $sent += $this->dispatchTo($user, $subtask, $value);
        }

        return $sent ? "จ่ายงานแล้ว {$sent} งาน" : null;
    }

    /** Adds a department to the project: every cabinet gets one Sub Task "งาน <แผนก>" handed to it (the normal dispatch: logged, the department is told). */
    private function stageAdd(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'เพิ่มแผนกเข้างาน');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $department = Department::where('is_active', true)->find($this->id($op['deptId'] ?? null, 'd_')) ?? throw new PlanningRejected('ไม่พบแผนกนี้');

        if (WorkScope::projectSubtasks($project, $user)->where('department_id', $department->id)->exists()) {
            throw new PlanningRejected("แผนก {$department->name} อยู่ในโครงการนี้แล้ว");
        }
        $cabinets = $project->cabinets()->get();
        if ($cabinets->isEmpty()) {
            throw new PlanningRejected('โครงการนี้ยังไม่มีตู้ — เพิ่มตู้ก่อน');
        }

        foreach ($cabinets as $cabinet) {
            $task = $cabinet->tasks()->orderBy('sequence')->first()
                ?? $cabinet->tasks()->create(['name' => 'งานแผนก', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
            $subtask = $task->subtasks()->create([
                'name' => "งาน {$department->name}", 'status' => 'not_started', 'progress' => 0,
                'sequence' => (int) $task->subtasks()->max('sequence') + 1,
            ] + CabinetSubtask::initialAssignment(null));
            $this->dispatchTo($user, $subtask->load('cabinetTask'), ['dept' => 'd_'.$department->id, 'start' => $op['start'] ?? null, 'due' => $op['due'] ?? null]);
        }

        return "เพิ่มแผนก {$department->name} เข้าโครงการแล้ว";
    }

    /** Takes a department off the project - only while none of its work was accepted yet (the same lock as changing a department). */
    private function stageRemove(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'เอาแผนกออกจากงาน');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $rows = $this->deptSubtasks($project, $user, $op['deptId'] ?? null);

        if ($rows->isEmpty()) {
            throw new PlanningRejected('แผนกนี้ไม่มีงานในโครงการนี้');
        }
        if ($rows->contains(fn ($s) => $s->assignment_status !== CabinetSubtask::ASSIGNMENT_ASSIGNED)) {
            throw new PlanningRejected('เอาแผนกออกไม่ได้ — แผนกนี้รับงานไปแล้ว');
        }
        foreach ($rows as $subtask) {
            $this->assignments->changeDepartment($subtask, null, $user);
        }

        return 'เอาแผนกออกจากโครงการแล้ว';
    }

    /**
     * Hands one sub task to a department, with its dates if given: 'd_5', or ['dept' => 'd_5', 'start' => 'YYYY-MM-DD', 'due' => ...].
     * The normal dispatch (SubtaskAssignmentService: logged, the department is told); the due date passes the date-limit rules.
     *
     * @return int 1 when it was handed out
     */
    private function dispatchTo(User $user, \App\Models\CabinetSubtask $subtask, mixed $value): int
    {
        [$dept, $start, $due] = is_array($value) ? [$value['dept'] ?? null, $value['start'] ?? null, $value['due'] ?? null] : [$value, null, null];
        if (! is_string($dept) || $dept === '') {
            return 0;
        }
        $department = \App\Models\Department::where('is_active', true)->find($this->id($dept, 'd_')) ?? throw new PlanningRejected('ไม่พบแผนกนี้');
        [$start, $due] = [$this->date($start), $this->date($due)];
        // no dates given: the department's range in this project (all its work there shares one start - end)
        if (! $start && ! $due) {
            $range = \App\Models\CabinetSubtask::where('department_id', $department->id)->where('id', '!=', $subtask->id)
                ->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $subtask->cabinetTask->cabinet->project_id))
                ->selectRaw('min(start_date) s, max(due_date) d')->first();
            [$start, $due] = [$range?->s ? substr($range->s, 0, 10) : null, $range?->d ? substr($range->d, 0, 10) : null];
        }
        if ($start && $due && $due < $start) {
            throw new PlanningRejected('วันส่งต้องไม่ก่อนวันเริ่ม ('.$subtask->name.')');
        }
        if ($due && ($message = \App\Models\DateLimitRule::violationFor($subtask->cabinetTask->cabinet_id, $department->id, $due))) {
            throw new PlanningRejected($message);
        }

        $sent = (int) $this->assignments->changeDepartment($subtask, $department->id, $user);
        if ($sent && ! $this->notifications->headcount($department->id)) {
            $this->notes[] = "⚠️ แผนก {$department->name} ยังไม่มีผู้ใช้ในระบบ — ไม่มีใครเห็นงานหรือได้รับแจ้งเตือน (เพิ่มผู้ใช้ที่ ตั้งค่า › ผู้ใช้งาน)";
        }
        if ($start || $due) {
            $subtask->refresh()->update(array_filter(['start_date' => $start, 'due_date' => $due]));
        }

        return $sent;
    }

    /** One sub task: dates, the person doing it, a remark. PMO roles: any; a department member: their own department's work. */
    private function subtaskInfo(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'แก้วันที่ / ผู้ทำ / หมายเหตุของงาน');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $subtask = \App\Models\CabinetSubtask::with('cabinetTask')->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $project->id))
            ->find($this->id($op['subtaskId'] ?? null, 's')) ?? throw new PlanningRejected('ไม่พบงานย่อยนี้');
        if (! $user->canDispatchWork() && ! $user->inDepartment($subtask->department_id)) {
            throw new PlanningRejected('แก้ได้เฉพาะงานของแผนกตัวเอง');
        }
        if ($subtask->assignment_status === \App\Models\CabinetSubtask::ASSIGNMENT_COMPLETED && (array_key_exists('startDate', $op['fields'] ?? []) || array_key_exists('dueDate', $op['fields'] ?? []))) {
            throw new PlanningRejected('งานที่เสร็จแล้วแก้วันไม่ได้');
        }
        $f = is_array($op['fields'] ?? null) ? $op['fields'] : [];
        $map = [];
        if (array_key_exists('startDate', $f)) {
            $map['start_date'] = $this->date($f['startDate']);
        }
        if (array_key_exists('dueDate', $f)) {
            $map['due_date'] = $this->date($f['dueDate']);
        }
        if (array_key_exists('remark', $f)) {
            $map['remark'] = mb_substr(trim((string) $f['remark']), 0, 2000) ?: null;
        }
        if (array_key_exists('ownerId', $f)) {
            $owner = $f['ownerId'] === '' || $f['ownerId'] === null ? null
                : User::where('is_active', true)->find((int) $f['ownerId']) ?? throw new PlanningRejected('ไม่พบผู้ใช้นี้');
            if ($owner && $subtask->department_id && ! $owner->inDepartment($subtask->department_id) && ! $owner->canDispatchWork()) {
                throw new PlanningRejected('ผู้รับผิดชอบต้องอยู่ในแผนกของงานนี้');
            }
            $map['owner_id'] = $owner?->id;
        }
        $start = array_key_exists('start_date', $map) ? $map['start_date'] : $subtask->start_date?->format('Y-m-d');
        $due = array_key_exists('due_date', $map) ? $map['due_date'] : $subtask->due_date?->format('Y-m-d');
        if ($start && $due && $due < $start) {
            throw new PlanningRejected('วันส่งต้องไม่ก่อนวันเริ่ม');
        }
        if (! empty($map['due_date']) && ($message = \App\Models\DateLimitRule::violationFor($subtask->cabinetTask->cabinet_id, $subtask->department_id, $map['due_date']))) {
            throw new PlanningRejected($message);
        }
        $dueMoved = array_key_exists('due_date', $map) && $map['due_date'] !== $subtask->due_date?->format('Y-m-d');
        if ($map) {
            $subtask->update($map);
        }
        if ($dueMoved) {
            $this->notifications->dueChanged(collect([$subtask->fresh('cabinetTask.cabinet.project')]), $user);
        }

        return null;
    }

    /** A department's status in one project, set by Admin / PM over the one worked out from its work ('' = back to automatic). */
    private function stageStatus(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'ตั้งสถานะงานของแผนก');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $department = Department::find($this->id($op['deptId'] ?? null, 'd_')) ?? throw new PlanningRejected('ไม่พบแผนกนี้');
        $raw = $op['statusId'] ?? '';
        $status = $raw === '' || $raw === null ? null : (is_string($raw) ? BoardStatus::fromPageId($raw) : null) ?? throw new PlanningRejected('สถานะไม่ถูกต้อง');

        $where = ['project_id' => $project->id, 'department_id' => $department->id];
        $status ? ProjectDepartmentStatus::updateOrCreate($where, ['board_status_id' => $status->id]) : ProjectDepartmentStatus::where($where)->delete();
        ActivityLog::record($project->id, $user->id, $project, 'updated', "ตั้งสถานะงานของแผนก {$department->name} เป็น ".($status?->name ?? 'อัตโนมัติ'));

        return null;
    }
}
