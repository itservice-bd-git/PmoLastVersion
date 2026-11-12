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
use App\Services\CabinetTemplateService;
use App\Services\CabinetCopyService;
use App\Services\HandoffService;
use App\Services\ProjectLock;
use App\Services\SharedChecklist;
use App\Services\WorkScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PlanningSync · Cabinets: add, rename, delete, its record, its status.
 * Part of PlanningSync (a trait of it), which owns the dispatch (applyOne) and the helpers: id(), projectFor(), requirePmo(),
 * date(), deptSubtasks(), open().
 */
trait SyncCabinets
{
    // ---- cabinets and department stages: building a project up (PMO roles) ----

    private function cabinetOf(Project $project, mixed $id): Cabinet
    {
        return Cabinet::where('project_id', $project->id)->find($this->id($id, 'c')) ?? throw new PlanningRejected('ไม่พบตู้นี้ในโครงการ');
    }

    private function cabinetAdd(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'เพิ่มตู้');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        [$mo, $name] = [trim((string) ($op['mo'] ?? '')), trim((string) ($op['text'] ?? ''))];

        if ($mo === '' || $name === '' || mb_strlen($mo) > 100 || mb_strlen($name) > 255) {
            throw new PlanningRejected('กรุณากรอกเลข MO และชื่อตู้');
        }
        if (Cabinet::where('mo_no', $mo)->exists()) {
            throw new PlanningRejected("เลข MO {$mo} มีอยู่แล้ว");
        }

        [$cabStart, $cabDue] = [$this->date($op['startDate'] ?? null), $this->date($op['dueDate'] ?? null)];
        if ($cabStart && $cabDue && $cabDue < $cabStart) {
            throw new PlanningRejected('วันครบกำหนดของตู้ต้องไม่ก่อนวันเริ่ม');
        }
        $cabinet = $project->cabinets()->create(['mo_no' => $mo, 'cabinet_name' => $name, 'start_date' => $cabStart, 'due_date' => $cabDue, 'sequence' => (int) $project->cabinets()->max('sequence') + 1]);
        // its tasks / sub tasks / checklists come from the chosen template ('none' = an empty cabinet; not given = the default one)
        $raw = $op['templateId'] ?? null;
        $template = $raw === 'none' ? null : CabinetTemplate::with('taskTemplates.subtaskTemplates.checklistTemplates')
            ->when($raw !== null && $raw !== '', fn ($q) => $q->whereKey((int) $raw), fn ($q) => $q->where('is_default', true))->first();
        if ($raw !== null && $raw !== '' && $raw !== 'none' && ! $template) {
            throw new PlanningRejected('ไม่พบเทมเพลตนี้');
        }
        if ($template) {
            app(CabinetTemplateService::class)->applyToCabinet($cabinet, $template);
        }

        // hand the template's sub tasks to the departments picked in the same window (the normal dispatch: logged, the department is told)
        $assign = is_array($op['assign'] ?? null) ? $op['assign'] : [];
        $sent = 0;
        foreach (CabinetSubtask::with('cabinetTask')->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cabinet->id))->get() as $subtask) {
            $sent += $this->dispatchTo($user, $subtask, $assign[(string) $subtask->cabinet_subtask_template_id] ?? null);
        }

        return "เพิ่มตู้ {$cabinet->mo_no} แล้ว".($sent ? " · จ่ายงาน {$sent} งาน" : '');
    }

    private function cabinetRename(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'แก้ไขตู้');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $cabinet = $this->cabinetOf($project, $op['cabinetId'] ?? null);
        [$mo, $name] = [trim((string) ($op['mo'] ?? $cabinet->mo_no)), trim((string) ($op['text'] ?? $cabinet->cabinet_name))];

        if ($mo === '' || $name === '' || mb_strlen($mo) > 100 || mb_strlen($name) > 255) {
            throw new PlanningRejected('กรุณากรอกเลข MO และชื่อตู้');
        }
        if (Cabinet::where('mo_no', $mo)->where('id', '!=', $cabinet->id)->exists()) {
            throw new PlanningRejected("เลข MO {$mo} มีอยู่แล้ว");
        }
        $cabinet->update(['mo_no' => $mo, 'cabinet_name' => $name]);

        return null;
    }

    private function cabinetDelete(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'ลบตู้');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $cabinet = $this->cabinetOf($project, $op['cabinetId'] ?? null);
        $cabinet->delete();   // soft delete + cascade: it goes to the trash and can be restored there

        return 'ลบตู้ '.$cabinet->mo_no.' แล้ว (กู้คืนได้ที่ถังขยะ)';
    }

    // ---- the project's record, a cabinet's record, one sub task's dates / owner / remark ----

    private function cabinetInfo(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'แก้ข้อมูลตู้');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $cabinet = Cabinet::where('project_id', $project->id)->find($this->id($op['cabinetId'] ?? null, 'c')) ?? throw new PlanningRejected('ไม่พบตู้นี้ในโครงการ');
        $f = is_array($op['fields'] ?? null) ? $op['fields'] : [];
        $map = [];
        foreach (['startDate' => 'start_date', 'dueDate' => 'due_date', 'expectedDate' => 'expected_completion_date'] as $k => $col) {
            if (array_key_exists($k, $f)) {
                $map[$col] = $this->date($f[$k]);
            }
        }
        foreach (['type' => ['cabinet_type', 100], 'size' => ['size', 100], 'description' => ['description', 5000], 'remark' => ['remark', 5000]] as $k => [$col, $max]) {
            if (array_key_exists($k, $f)) {
                $map[$col] = mb_substr(trim((string) $f[$k]), 0, $max) ?: null;
            }
        }
        if (array_key_exists('quantity', $f)) {
            $q = filter_var($f['quantity'], FILTER_VALIDATE_INT);
            $map['quantity'] = $q !== false && $q > 0 && $q < 100000 ? $q : throw new PlanningRejected('จำนวนต้องเป็นตัวเลขมากกว่า 0');
        }
        // a date the cabinet already has moves only on หน้าตู้ › "เลื่อน / แก้วัน" (with a reason; ProjectDates)
        foreach (['start_date' => 'วันเริ่ม', 'due_date' => 'กำหนดเสร็จ'] as $col => $label) {
            if (array_key_exists($col, $map) && $cabinet->{$col} && $map[$col] !== $cabinet->{$col}->format('Y-m-d')) {
                throw new PlanningRejected("เปลี่ยน{$label}ของตู้ได้ที่หน้าตู้ › เลื่อน / แก้วัน (ต้องใส่สาเหตุ)");
            }
        }
        $start = array_key_exists('start_date', $map) ? $map['start_date'] : $cabinet->start_date?->format('Y-m-d');
        $due = array_key_exists('due_date', $map) ? $map['due_date'] : $cabinet->due_date?->format('Y-m-d');
        $expected = array_key_exists('expected_completion_date', $map) ? $map['expected_completion_date'] : $cabinet->expected_completion_date?->format('Y-m-d');
        if ($start && $due && $due < $start) {
            throw new PlanningRejected('วันครบกำหนดของตู้ต้องไม่ก่อนวันเริ่ม');
        }
        if ($expected && $due && $expected > $due) {
            throw new PlanningRejected('วันคาดว่าจะเสร็จต้องไม่เกินวันครบกำหนด (Due Date)');
        }
        if ($map) {
            $cabinet->update($map);
        }

        return null;
    }

    // ---- cabinets ----

    private function cabinetStatus(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'ตั้งสถานะตู้');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $cabinet = Cabinet::where('project_id', $project->id)->find($this->id($op['cabinetId'] ?? null, 'c')) ?? throw new PlanningRejected('ไม่พบตู้นี้ในโครงการ');
        if (! $user->canDispatchWork() && ! WorkScope::projectSubtasks($project, $user)->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cabinet->id))->exists()) {
            throw new PlanningRejected('ตั้งสถานะได้เฉพาะตู้ที่แผนกของคุณมีงาน');
        }
        $raw = $op['statusId'] ?? '';
        $status = $raw === '' || $raw === null ? null : (is_string($raw) ? BoardStatus::fromPageId($raw) : null) ?? throw new PlanningRejected('สถานะไม่ถูกต้อง');

        $cabinet->forceFill(['board_status_id' => $status?->id])->save();
        ActivityLog::record($project->id, $user->id, $cabinet, 'updated', "ตั้งสถานะตู้ {$cabinet->mo_no} เป็น ".($status?->name ?? 'อัตโนมัติ'));

        return null;
    }
}
