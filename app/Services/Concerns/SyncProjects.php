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
 * PlanningSync · Projects: fields, dates, the board it is on, delete; create / copy / restore; moving the whole project; people in charge; labels.
 * Part of PlanningSync (a trait of it), which owns the dispatch (applyOne) and the helpers: id(), projectFor(), requirePmo(),
 * date(), deptSubtasks(), open().
 */
trait SyncProjects
{
    private function project(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'แก้ข้อมูลและวันที่ของโครงการ');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $f = is_array($op['fields'] ?? null) ? $op['fields'] : [];

        $map = [];
        if (array_key_exists('title', $f)) {
            $map['project_name'] = is_string($f['title']) ? trim($f['title']) : '';
        }
        if (array_key_exists('description', $f)) {
            $map['description'] = $f['description'] === '' ? null : $f['description'];
        }
        if (array_key_exists('priority', $f)) {
            $map['priority'] = self::PRIORITY[$f['priority']] ?? throw new PlanningRejected('ระดับความสำคัญไม่ถูกต้อง');
        }
        if (array_key_exists('statusId', $f)) {
            // the project's status, picked by Admin / PM ('' = none: the page then shows PMO's own project status)
            $status = $f['statusId'] === '' || $f['statusId'] === null ? null
                : (is_string($f['statusId']) ? BoardStatus::fromPageId($f['statusId']) : null) ?? throw new PlanningRejected('สถานะไม่ถูกต้อง');
            $project->forceFill(['board_status_id' => $status?->id])->save();
            ActivityLog::record($project->id, $user->id, $project, 'updated', 'ตั้งสถานะโครงการเป็น '.($status?->name ?? 'ตามสถานะ PMO'));
        }
        if (array_key_exists('startDate', $f)) {
            $map['start_date'] = $this->date($f['startDate']);
        }
        if (array_key_exists('date', $f)) {
            $map['due_date'] = $this->date($f['date']);
        }
        // the project's own record (customer, PO, PM, ...): Admin / PM only, like the dispatch side of PMO
        $info = ['customer' => 'customer_name', 'customerPhone' => 'customer_phone', 'poNo' => 'po_no', 'soNo' => 'sales_order_no', 'owner' => 'project_owner', 'salesPerson' => 'sales_person',
            'managerId' => 'project_manager_id', 'paymentTerms' => 'payment_terms', 'jobTypeId' => 'job_type_id', 'pmoStatus' => 'status',
            'contractValue' => 'contract_value', 'margin' => 'margin'];
        if (array_intersect_key($f, $info)) {
            $this->requirePmo($user, 'แก้ข้อมูลโครงการ (ลูกค้า / PO / PM / สถานะ PMO)');
            // what this person may not see, they do not change (Settings > สิทธิ์การใช้งาน)
            if (! $user->allows('data.finance') && array_intersect_key($f, ['contractValue' => 1, 'margin' => 1])) {
                throw new PlanningRejected('คุณไม่มีสิทธิ์ดูหรือแก้ข้อมูลการเงินของโครงการ');
            }
            if (! $user->allows('data.contact') && array_key_exists('customerPhone', $f)) {
                throw new PlanningRejected('คุณไม่มีสิทธิ์ดูหรือแก้ข้อมูลติดต่อลูกค้า');
            }
            foreach ($info as $key => $column) {
                if (array_key_exists($key, $f)) {
                    $v = is_string($f[$key]) ? trim($f[$key]) : $f[$key];
                    $map[$column] = $v === '' ? null : $v;
                }
            }
        }
        if ($map) {
            $this->editor->update($project, $map);   // same validation as the project form; the model's own guards (closing a project) apply
        }

        return null;
    }

    /** Changes the due date of every still-open Sub Task in $subtasks, after the date-limit rules say it is allowed. */
    private function moveDue(Collection $subtasks, ?string $due, User $user): void
    {
        $open = $this->open($subtasks);
        if ($open->isEmpty()) {
            throw new PlanningRejected('ไม่มีงานที่แก้วันได้ (เสร็จแล้วหรือยังไม่ได้จ่ายงาน)');
        }
        if ($due) {
            foreach ($open as $s) {
                if ($message = DateLimitRule::violationFor($s->cabinetTask->cabinet_id, $s->department_id, $due)) {
                    throw new PlanningRejected($message);
                }
            }
        }
        foreach ($open as $s) {
            $change = ['due_date' => $due];
            if ($due && $s->start_date && $s->start_date->format('Y-m-d') > $due) {
                $change['start_date'] = $due;   // keep start <= due
            }
            $s->update($change);
        }
        $this->notifications->dueChanged($open, $user);   // the departments whose dates moved hear it
    }

    // ---- labels (tags) ----

    private function labelsSet(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'ติด / ถอดแท็ก');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $ids = collect(is_array($op['labels'] ?? null) ? $op['labels'] : [])->map(fn ($l) => is_string($l) && preg_match('/^l(\d+)$/', $l, $m) ? (int) $m[1] : null)->filter()->unique();
        $valid = BoardLabel::whereIn('id', $ids)->pluck('id');

        $project->boardLabels()->sync($valid);
        ActivityLog::record($project->id, $user->id, $project, 'updated', 'แท็ก: '.($valid->isEmpty() ? '(ไม่มี)' : BoardLabel::whereIn('id', $valid)->pluck('name')->implode(', ')));

        return null;
    }

    private function labelCreate(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'สร้างแท็ก');
        $name = trim((string) ($op['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 40) {
            throw new PlanningRejected('ชื่อแท็กต้องมี 1–40 ตัวอักษร');
        }
        $color = is_string($op['color'] ?? null) && preg_match('/^#[0-9A-Fa-f]{6}$/', $op['color']) ? $op['color'] : '#7b68ee';
        BoardLabel::create(['name' => $name, 'color' => $color, 'sort' => (int) BoardLabel::max('sort') + 1]);

        return null;
    }

    private function labelDelete(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'ลบแท็ก');
        BoardLabel::find($this->id($op['labelId'] ?? null, 'l'))?->delete();   // detached from every project by the foreign key

        return null;
    }

    private function boardSet(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'ย้ายโครงการไปบอร์ดอื่น');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $raw = $op['boardId'] ?? '';
        $board = ($raw === '' || $raw === 'main' || $raw === null) ? null
            : (is_string($raw) && preg_match('/^b(\d+)$/', $raw, $m) ? Board::find((int) $m[1]) : null) ?? throw new PlanningRejected('ไม่พบบอร์ดนี้');

        $project->forceFill(['board_id' => $board?->id])->save();
        ActivityLog::record($project->id, $user->id, $project, 'updated', 'ย้ายไปบอร์ด '.($board?->name ?? 'หลัก'));

        return 'ย้ายโครงการไปบอร์ด '.($board?->name ?? 'หลัก').' แล้ว';
    }

    private function delete(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'ลบโครงการ');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $project->delete();   // soft delete + cascade: it goes to the trash and can be restored there

        return 'ย้ายโครงการ '.$project->project_no.' ไปถังขยะแล้ว';
    }
    // ---- projects ----

    private function projectCreate(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'สร้างโครงการ');
        [$so, $title, $customer] = [trim((string) ($op['so'] ?? '')), trim((string) ($op['title'] ?? '')), trim((string) ($op['customer'] ?? ''))];
        if ($so === '' || $title === '' || $customer === '' || mb_strlen($so) > 50 || mb_strlen($title) > 255 || mb_strlen($customer) > 255) {
            throw new PlanningRejected('กรุณากรอกเลขที่โครงการ ชื่อ และลูกค้า');
        }
        if (Project::where('project_no', $so)->exists()) {
            throw new PlanningRejected("เลขที่โครงการ {$so} มีอยู่แล้ว");
        }
        [$start, $due] = [$this->date($op['startDate'] ?? null), $this->date($op['date'] ?? null)];
        if ($start && $due && $due < $start) {
            throw new PlanningRejected('วันครบกำหนดต้องไม่ก่อนวันเริ่ม');
        }

        // the rest of the project form (all optional): phone, job type, contract value, margin - same rules as everywhere else
        $extra = \Illuminate\Support\Facades\Validator::make([
            'customer_phone' => trim((string) ($op['customerPhone'] ?? '')) ?: null,
            'job_type_id' => ($op['jobTypeId'] ?? null) ?: null,
            'contract_value' => Project::cleanAmount($op['contractValue'] ?? null),
            'margin' => Project::cleanAmount($op['margin'] ?? null),
        ], [
            'customer_phone' => ['nullable', 'string', 'max:50'], 'job_type_id' => ['nullable', 'exists:job_types,id'],
            'contract_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'], 'margin' => ['nullable', 'numeric', 'max:9999999999999'],
        ], ['contract_value.numeric' => 'มูลค่างานต้องเป็นตัวเลข', 'margin.numeric' => 'Margin ต้องเป็นตัวเลข'])->validate();
        if (! $user->allows('data.finance')) {   // not theirs to set (Settings > สิทธิ์การใช้งาน)
            unset($extra['contract_value'], $extra['margin']);
        }
        if (! $user->allows('data.contact')) {
            unset($extra['customer_phone']);
        }

        $project = Project::create($extra + [
            'project_no' => $so, 'project_name' => $title, 'customer_name' => $customer,
            'start_date' => $start, 'due_date' => $due,
            'priority' => self::PRIORITY[$op['priority'] ?? 'none'] ?? 'normal',
            'status' => Project::STATUS_PLANNING,
            'board_id' => $this->boardIdOf($op['boardId'] ?? null),
            'project_manager_id' => User::where('is_active', true)->whereIn('role', Project::MANAGER_ROLES)->find((int) ($op['managerId'] ?? 0))?->id,
        ]);
        $this->created = $project->id;

        return "สร้างโครงการ {$project->project_no} แล้ว";
    }

    private function projectDuplicate(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'คัดลอกโครงการ');
        $source = $this->projectFor($user, $op['taskId'] ?? null);
        $so = trim((string) ($op['so'] ?? ''));
        if ($so === '' || mb_strlen($so) > 50) {
            throw new PlanningRejected('กรุณากรอกเลขที่โครงการใหม่');
        }
        if (Project::where('project_no', $so)->exists()) {
            throw new PlanningRejected("เลขที่โครงการ {$so} มีอยู่แล้ว");
        }

        $copy = DB::transaction(function () use ($source, $so, $op) {
            $copy = $source->replicate(['project_no', 'board_status_id', 'created_by', 'updated_by']);
            $copy->project_no = $so;
            $copy->project_name = trim((string) ($op['title'] ?? '')) ?: $source->project_name.' (สำเนา)';
            $copy->status = Project::STATUS_PLANNING;
            $copy->save();
            $copy->boardLabels()->sync($source->boardLabels()->pluck('board_labels.id'));

            // every cabinet with its tasks / sub tasks / checklists (the workflow starts over - CabinetCopyService)
            foreach ($source->cabinets()->orderBy('sequence')->get() as $i => $cabinet) {
                $mo = $cabinet->mo_no.'-'.$so;
                for ($n = 2; Cabinet::withTrashed()->where('mo_no', $mo)->exists(); $n++) {
                    $mo = $cabinet->mo_no.'-'.$so.'-'.$n;
                }
                $target = $copy->cabinets()->create(['mo_no' => mb_substr($mo, 0, 100), 'cabinet_name' => $cabinet->cabinet_name, 'quantity' => $cabinet->quantity,
                    'cabinet_type' => $cabinet->cabinet_type, 'size' => $cabinet->size, 'cabinet_template_id' => $cabinet->cabinet_template_id, 'sequence' => $i + 1]);
                app(CabinetCopyService::class)->copyFrom($cabinet->load('tasks.subtasks.checklists'), $target);
            }

            return $copy;
        });
        $this->created = $copy->id;

        return "คัดลอกเป็นโครงการ {$copy->project_no} แล้ว";
    }

    /** Back from the trash, on this page (same rule as the trash screen: Admin / PM, and the number must still be free). */
    private function projectRestore(User $user, array $op): ?string
    {
        if (! $user->canManageTrash()) {
            throw new PlanningRejected('กู้คืนได้เฉพาะ Admin / Project Manager');
        }
        $project = Project::onlyTrashed()->find($this->id($op['taskId'] ?? null, 'p')) ?? throw new PlanningRejected('ไม่พบโครงการนี้ในถังขยะ');
        if (Project::where('project_no', $project->project_no)->exists()) {
            throw new PlanningRejected("กู้คืนไม่ได้: มีโครงการเลขที่ {$project->project_no} อยู่แล้ว");
        }
        $project->restore();

        return "กู้คืนโครงการ {$project->project_no} แล้ว";
    }

    private function boardIdOf(mixed $raw): ?int
    {
        return is_string($raw) && preg_match('/^b(\d+)$/', $raw, $m) ? Board::find((int) $m[1])?->id : null;
    }

    /**
     * The customer moved the job: the project, its cabinets and every sub task that is not finished yet move by the same number
     * of days (Admin / PM). All or nothing - if one moved date breaks a date-limit rule, nothing moves.
     */
    private function projectShift(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'เลื่อนทั้งโครงการ');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $days = filter_var($op['days'] ?? null, FILTER_VALIDATE_INT);
        if ($days === false || $days === 0 || abs($days) > 730) {
            throw new PlanningRejected('จำนวนวันต้องเป็นตัวเลข (บวก = เลื่อนออก, ลบ = เลื่อนเข้า) ไม่เกิน 730');
        }
        $move = fn ($d) => $d?->copy()->addDays($days)->format('Y-m-d');

        $moved = collect();
        [$oldStart, $oldDue] = [$project->start_date?->copy(), $project->due_date?->copy()];
        DB::transaction(function () use ($project, $move, &$moved, $user, $oldStart, $oldDue, $days) {
            app(\App\Services\ProjectDates::class)->shifted($project, $user, $oldStart, $oldDue, $days);
            $project->update(array_filter(['start_date' => $move($project->start_date), 'due_date' => $move($project->due_date)]));
            foreach ($project->cabinets()->get() as $cabinet) {
                app(\App\Services\ProjectDates::class)->shifted($cabinet, $user, $cabinet->start_date?->copy(), $cabinet->due_date?->copy(), $days);
                $cabinet->update(array_filter(['start_date' => $move($cabinet->start_date), 'due_date' => $move($cabinet->due_date), 'expected_completion_date' => $move($cabinet->expected_completion_date)]));
                foreach (\App\Models\CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cabinet->id))
                    ->where('assignment_status', '!=', \App\Models\CabinetSubtask::ASSIGNMENT_COMPLETED)->get() as $s) {
                    $due = $move($s->due_date);
                    if ($due && ($message = \App\Models\DateLimitRule::violationFor($cabinet->id, $s->department_id, $due))) {
                        throw new PlanningRejected($message);
                    }
                    $s->update(array_filter(['start_date' => $move($s->start_date), 'due_date' => $due]));
                    if ($due) {
                        $moved->push($s);
                    }
                }
            }
        });
        ActivityLog::record($project->id, $user->id, $project, 'updated', 'เลื่อนทั้งโครงการ '.($days > 0 ? '+' : '').$days.' วัน');
        $this->notifications->dueChanged($moved->each->loadMissing('cabinetTask.cabinet.project'), $user);

        return 'เลื่อนทั้งโครงการ '.($days > 0 ? '+' : '').$days.' วันแล้ว';
    }

    // ---- people in charge ----

    private function assigneesSet(User $user, array $op): ?string
    {
        $this->requirePmo($user, 'กำหนดผู้รับผิดชอบ');
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $ids = collect(is_array($op['userIds'] ?? null) ? $op['userIds'] : [])->filter(fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v)))->map(fn ($v) => (int) $v)->unique()->take(30);
        $people = User::whereIn('id', $ids)->where('is_active', true)->get();

        $before = $project->assignees()->pluck('users.id');
        $project->assignees()->sync($people->pluck('id'));
        foreach ($people->whereNotIn('id', $before) as $added) {
            $this->notifications->assignedPerson($project, $user, $added);
        }
        ActivityLog::record($project->id, $user->id, $project, 'updated', 'ผู้รับผิดชอบ: '.($people->isEmpty() ? '(ไม่มี)' : $people->pluck('name')->implode(', ')));

        return null;
    }
}
