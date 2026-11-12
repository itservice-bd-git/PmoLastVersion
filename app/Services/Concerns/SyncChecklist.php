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
use App\Services\DeliveryChecklist;
use App\Services\CabinetCopyService;
use App\Services\HandoffService;
use App\Services\ProjectLock;
use App\Services\SharedChecklist;
use App\Services\WorkScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PlanningSync · Checklist items: tick, add / delete (through SharedChecklist), rename, remark, tags, dates, order.
 * Part of PlanningSync (a trait of it), which owns the dispatch (applyOne) and the helpers: id(), projectFor(), requirePmo(),
 * date(), deptSubtasks(), open().
 */
trait SyncChecklist
{
    // ---- operations ----

    private function checklist(User $user, array $op): ?string
    {
        $item = CabinetChecklist::with('subtask.cabinetTask.cabinet.project')->find($this->id($op['itemId'] ?? null, 'k'))
            ?? throw new PlanningRejected('ไม่พบรายการ checklist นี้');
        $subtask = $item->subtask;
        $project = $subtask->cabinetTask->cabinet->project;
        $done = (bool) ($op['done'] ?? false);

        if (! WorkScope::projectSubtasks($project, $user)->whereKey($subtask->id)->exists()) {
            throw new PlanningRejected('คุณไม่มีสิทธิ์ในรายการนี้');
        }
        if ((bool) $item->is_completed === $done) {
            return null;
        }
        app(DeliveryChecklist::class)->assertManual($item);   // ห่อตู้ / แจ้งเปิดบิล / ส่งมอบ: the delivery round ticks them
        $accepted = $done && $this->assignments->acceptByTick($subtask, $user);   // work still waiting: ticking accepts it (Settings > กฎการทำงาน)
        if (! $accepted && ! $subtask->isChecklistEditableBy($user)) {
            throw new PlanningRejected('ติ๊ก Checklist ได้เฉพาะแผนกที่รับงานแล้ว — ต้อง "รับงาน" ก่อน');
        }
        if ($accepted) {
            $subtask = $subtask->fresh();
            $item->setRelation('subtask', $subtask);
            $this->notes[] = 'รับงาน "'.$subtask->name.'" ให้แล้ว (ติ๊ก Checklist ของงานที่รอรับ)';
        }

        $this->progress->toggleChecklist($item, $done, $user);
        if ($done) {
            $this->automation->afterChecklistTicked($subtask->fresh(), $user);
        }

        return null;
    }

    /** The department's Sub Task in this cabinet that a new checklist item would belong to (visible to the user). */
    private function cabinetDeptSubtask(User $user, Project $project, mixed $cabinetKey, mixed $deptKey): CabinetSubtask
    {
        return WorkScope::projectSubtasks($project, $user)
            ->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $this->id($cabinetKey, 'c')))
            ->where('department_id', $this->id($deptKey, 'd_'))
            ->orderBy('id')->first()
            ?? throw new PlanningRejected('ไม่พบงานของแผนกนี้ในตู้นี้');
    }

    private function checklistAdd(User $user, array $op): ?string
    {
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        // into the Sub Task picked in the checklist window; older pages send only the cabinet + department (its first Sub Task)
        $subtask = ! empty($op['subtaskId'])
            ? WorkScope::projectSubtasks($project, $user)->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $this->id($op['cabinetId'] ?? null, 'c')))
                ->find($this->id($op['subtaskId'], 's')) ?? throw new PlanningRejected('ไม่พบงานย่อยนี้ในตู้นี้')
            : $this->cabinetDeptSubtask($user, $project, $op['cabinetId'] ?? null, $op['deptId'] ?? null);
        $text = trim((string) ($op['text'] ?? ''));

        if ($text === '') {
            throw new PlanningRejected('กรุณาพิมพ์ชื่อรายการ checklist');
        }
        if (! $subtask->isChecklistEditableBy($user)) {
            throw new PlanningRejected('เพิ่ม Checklist ได้เฉพาะแผนกที่รับงานแล้ว — ต้อง "รับงาน" ก่อน');
        }
        ProjectLock::assertOpen($project->id);

        $item = $subtask->checklists()->create([
            'checklist_template_id' => null,
            'name' => mb_substr($text, 0, 255),
            'is_completed' => false,
            'sequence' => (int) $subtask->checklists()->max('sequence') + 1,
        ]);
        $this->progress->recalculateSubtask($subtask);
        $this->shareChanged(fn (SharedChecklist $shared) => $shared->added($subtask, collect([$item])));

        return null;
    }

    private function checklistDelete(User $user, array $op): ?string
    {
        $item = CabinetChecklist::with('subtask.cabinetTask.cabinet.project')->find($this->id($op['itemId'] ?? null, 'k'))
            ?? throw new PlanningRejected('ไม่พบรายการ checklist นี้ (อาจถูกลบไปแล้ว)');
        $subtask = $item->subtask;
        $project = $subtask->cabinetTask->cabinet->project;

        if (! WorkScope::projectSubtasks($project, $user)->whereKey($subtask->id)->exists()) {
            throw new PlanningRejected('คุณไม่มีสิทธิ์ในรายการนี้');
        }
        if (! $subtask->isChecklistEditableBy($user)) {
            throw new PlanningRejected('ลบ Checklist ได้เฉพาะแผนกที่รับงานแล้ว — ต้อง "รับงาน" ก่อน');
        }
        ProjectLock::assertOpen($project->id);
        $shared = app(SharedChecklist::class);
        $shared->guardOwn([$item]);
        $shared->assertDeletable(collect([$item]));

        $item->delete();
        $this->progress->recalculateSubtask($subtask);
        $this->shareChanged(fn (SharedChecklist $shared) => $shared->deleted(collect([$item])), $shared);

        return null;
    }

    /** A change that reaches the Sub Tasks comparing with this one (SharedChecklist): their progress follows. */
    private function shareChanged(\Closure $change, ?SharedChecklist $shared = null): void
    {
        $shared ??= app(SharedChecklist::class);
        $change($shared);
        foreach (CabinetSubtask::whereKey($shared->takeTouched())->get() as $s) {
            $this->progress->recalculateSubtask($s);
        }
    }

    // ---- checklist items: rename, remark, order ----

    private function editableItem(User $user, mixed $id): CabinetChecklist
    {
        $item = CabinetChecklist::with('subtask.cabinetTask.cabinet.project')->find($this->id($id, 'k')) ?? throw new PlanningRejected('ไม่พบรายการ checklist นี้');
        $project = $item->subtask->cabinetTask->cabinet->project;
        if (! WorkScope::projectSubtasks($project, $user)->whereKey($item->subtask->id)->exists() || ! $item->subtask->isChecklistEditableBy($user)) {
            throw new PlanningRejected('แก้ Checklist ได้เฉพาะแผนกที่รับงานแล้ว (หรือ Admin / PM)');
        }
        ProjectLock::assertOpen($project->id);

        return $item;
    }

    private function checklistRename(User $user, array $op): ?string
    {
        $item = $this->editableItem($user, $op['itemId'] ?? null);
        $name = trim((string) ($op['text'] ?? ''));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new PlanningRejected('กรุณาพิมพ์ชื่อรายการ checklist');
        }
        if ($name !== $item->name) {
            app(SharedChecklist::class)->guardOwn([$item]);
        }
        $item->update(['name' => $name]);
        app(SharedChecklist::class)->renamed($item);

        return null;
    }

    private function checklistRemark(User $user, array $op): ?string
    {
        $item = $this->editableItem($user, $op['itemId'] ?? null);
        $item->update(['remark' => mb_substr(trim((string) ($op['remark'] ?? '')), 0, 1000) ?: null]);

        return null;
    }

    /** Settings > สีสถานะ Checklist: one item's statuses, all at once. */
    private function checklistTags(User $user, array $op): ?string
    {
        app(\App\Services\ChecklistStatuses::class)->set($this->editableItem($user, $op['itemId'] ?? null), (array) ($op['tags'] ?? []), $user);

        return null;
    }

    /** Settings > การส่งต่องาน: the date a sending department gives an item ("วันของเข้า"); the receiving departments hear it. */
    private function checklistDate(User $user, array $op): ?string
    {
        $item = $this->editableItem($user, $op['itemId'] ?? null);
        $this->requireDateLabel($item->subtask->department_id);
        $item->update(['expected_date' => $this->date($op['date'] ?? null)]);
        if ($item->wasChanged('expected_date')) {
            app(HandoffService::class)->datesChanged(collect([$item]), $user);
        }

        return null;
    }

    /** The same date for every item of one department in one cabinet ("ใส่วันเดียวกันทั้งตู้"). */
    private function checklistDateAll(User $user, array $op): ?string
    {
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        ProjectLock::assertOpen($project->id);
        $deptId = $this->id($op['deptId'] ?? null, 'd_');
        $this->requireDateLabel($deptId);
        $subtasks = WorkScope::projectSubtasks($project, $user)->where('department_id', $deptId)
            ->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $this->id($op['cabinetId'] ?? null, 'c')))->with('checklists')->get();
        if ($subtasks->isEmpty() || $subtasks->contains(fn ($s) => ! $s->isChecklistEditableBy($user))) {
            throw new PlanningRejected('ใส่วันที่ได้เฉพาะแผนกที่รับงานแล้ว (หรือ Admin / PM)');
        }
        $date = $this->date($op['date'] ?? null);
        $changed = $subtasks->flatMap->checklists->filter(fn ($c) => $c->expected_date?->format('Y-m-d') !== $date)
            ->each(fn ($c) => $c->update(['expected_date' => $date]));
        if ($changed->isNotEmpty()) {
            app(HandoffService::class)->datesChanged($changed->values(), $user);
        }

        return $changed->isEmpty() ? null : 'ใส่วันที่ให้ '.$changed->count().' รายการ';
    }

    private function requireDateLabel(?int $deptId): void
    {
        if (! app(HandoffService::class)->dateLabel($deptId)) {
            throw new PlanningRejected('แผนกนี้ยังไม่ได้ตั้งชื่อวันที่ส่งต่อ (ตั้งค่า › แผนก)');
        }
    }

    private function checklistMove(User $user, array $op): ?string
    {
        $item = $this->editableItem($user, $op['itemId'] ?? null);
        $list = $item->subtask->checklists()->orderBy('sequence')->orderBy('id')->get()->values();
        $i = $list->search(fn ($c) => $c->id === $item->id);
        $j = ($op['dir'] ?? '') === 'up' ? $i - 1 : $i + 1;
        if (isset($list[$j])) {
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
            $list->each(fn ($c, $n) => $c->sequence === $n + 1 ?: $c->forceFill(['sequence' => $n + 1])->saveQuietly());
            app(SharedChecklist::class)->reordered($item->subtask);
        }

        return null;
    }
}
