<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\User;

/**
 * Recalculates progress bottom-up: Checklist -> Sub Task -> Task -> Cabinet.
 * Each level's progress is the average of its children's persisted progress
 * (see cabinet_tasks.weight / cabinets.weight for the future weighted rollup).
 */
class ProgressService
{
    public function toggleChecklist(CabinetChecklist $checklist, bool $completed, ?User $user = null): CabinetChecklist
    {
        // Sub Task checklist count before/after, kept on the log row so the Activity Log
        // can show "1/3 → 2/3" (rows logged before this change simply don't have it).
        $total = $checklist->subtask->checklists()->count();
        $doneBefore = $checklist->subtask->checklists()->where('is_completed', true)->count();

                $checklist->is_completed = $completed;
        $checklist->completed_by = $completed ? $user?->id : null;
        $checklist->completed_at = $completed ? now() : null;
        $checklist->save();

        $cabinet = $checklist->subtask->cabinetTask->cabinet;

        ActivityLog::record(
            $cabinet->project_id,
            $user?->id,
            $checklist,
            $completed ? 'checked' : 'unchecked',
            ($completed ? 'ทำเครื่องหมายเสร็จ Checklist "' : 'ยกเลิกเครื่องหมาย Checklist "').$checklist->name.'" ('.$cabinet->mo_no.' / '.$checklist->subtask->cabinetTask->name.')',
            ['progress' => [
                'label' => 'Checklist ที่เสร็จ',
                'old' => "{$doneBefore}/{$total}",
                'new' => $checklist->subtask->checklists()->where('is_completed', true)->count()."/{$total}",
            ]]
        );

        $this->recalculateSubtask($checklist->subtask);
        if ($completed) {
            $this->announceTick($checklist, $doneBefore + 1 === $total, $user);
        }
        if ($checklist->source_checklist_id) {
            $this->followCopy($checklist, $completed, $user);
        }

        return $checklist->fresh();
    }

    /** A receiver ticked / unticked its copy (SharedChecklist): the source's item goes with it, logged, and its department hears it. */
    private function followCopy(CabinetChecklist $copy, bool $completed, ?User $user): void
    {
        $source = app(SharedChecklist::class)->copyTicked($copy, $completed, $user);
        if (! $source) {
            return;
        }
        $cabinet = $source->subtask->cabinetTask->cabinet;
        $by = $copy->subtask->department?->name ?? $copy->subtask->name;
        ActivityLog::record($cabinet->project_id, $user?->id, $source, $completed ? 'checked' : 'unchecked',
            ($completed ? "{$by} รับแล้ว — ทำเครื่องหมายเสร็จ Checklist \"" : "{$by} ยกเลิกการรับ — ยกเลิกเครื่องหมาย Checklist \"")
            .$source->name.'" ของ '.($source->subtask->department?->name ?? $source->subtask->name).' ('.$cabinet->mo_no.' / '.$source->subtask->cabinetTask->name.')');

        $this->recalculateSubtask($source->subtask);
        if ($completed && ! app(ChecklistNoticeRules::class)->covers('tick', $copy->subtask)) {   // a "ติ๊กรายการ" rule of the receiver says it instead
            app(HandoffService::class)->receivedBack($copy, $source, $user);
        }
    }

    /**
     * Who hears a tick: Settings > แจ้งเตือน Checklist's rules of the department ("ติ๊กรายการ", and "ครบทั้งตู้" when this
     * tick finished its list); a department with neither keeps the built-in notice to the Sub Tasks paired with it.
     */
    private function announceTick(CabinetChecklist $checklist, bool $finished, ?User $user): void
    {
        $rules = app(ChecklistNoticeRules::class);
        $sub = $checklist->subtask;
        $ticked = $rules->fire('tick', $sub, collect([$checklist]), $user);
        $whole = $finished && $rules->fire('cabinet', $sub, $sub->checklists()->get(), $user);
        if (! $ticked && ! $whole && ! $rules->covers('cabinet', $sub)) {
            app(HandoffService::class)->ticked($checklist, $user);   // the next department hears it
        }
    }

    public function recalculateSubtask(CabinetSubtask $subtask): void
    {
        $total = $subtask->checklists()->count();

        if ($total > 0) {
            $completed = $subtask->checklists()->where('is_completed', true)->count();
            $progress = (int) round($completed / $total * 100);

            $subtask->progress = $progress;
            $subtask->status = $this->deriveStatus($progress, $subtask->status);
            $subtask->completed_date = $progress >= 100 ? ($subtask->completed_date ?? now()) : null;
            $subtask->save();
        }

        $this->recalculateTask($subtask->cabinetTask);
    }

    public function recalculateTask(CabinetTask $task): void
    {
        $progress = (int) round($task->subtasks()->avg('progress') ?? 0);

        $task->progress = $progress;
        $task->status = $this->deriveStatus($progress, $task->status);
        $task->completed_date = $progress >= 100 ? ($task->completed_date ?? now()) : null;
        $task->save();

        $this->recalculateCabinet($task->cabinet);
    }

    public function recalculateCabinet(Cabinet $cabinet): void
    {
        $progress = (int) round($cabinet->tasks()->avg('progress') ?? 0);

        $cabinet->progress = $progress;
        $cabinet->status = $this->deriveStatus($progress, $cabinet->status);
        $cabinet->save();
    }

    private function deriveStatus(int $progress, string $currentStatus): string
    {
        if ($currentStatus === 'on_hold') {
            return 'on_hold';
        }

        return match (true) {
            $progress >= 100 => 'completed',
            $progress > 0 => 'in_progress',
            default => 'not_started',
        };
    }
}
