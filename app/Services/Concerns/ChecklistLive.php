<?php

namespace App\Services\Concerns;

use App\Models\CabinetSubtask;
use App\Models\User;
use App\Services\HandoffService;
use App\Services\WorkScope;
use Illuminate\Support\Facades\DB;

/** ChecklistEditor: the cabinet page kept current (its version stamp, every list, and the progress after a change). */
trait ChecklistLive
{
    /** A stamp of every list of the cabinet: it moves with any add, edit, tick, order or delete (the page asks every 15 s). */
    public function cabinetVersion(\App\Models\Cabinet $cabinet): string
    {
        $row = DB::table('cabinet_checklists as c')->join('cabinet_subtasks as s', 's.id', '=', 'c.cabinet_subtask_id')
            ->join('cabinet_tasks as t', 't.id', '=', 's.cabinet_task_id')->where('t.cabinet_id', $cabinet->id)
            ->selectRaw('count(*) as n, count(c.deleted_at) as gone, max(c.updated_at) as at, sum(case when c.is_completed = 1 then 1 else 0 end) as done, sum(c.sequence) as seq, sum(length(c.name)) + sum(length(coalesce(c.remark, \'\'))) as len')
            ->first();

        return substr(md5(json_encode($row)), 0, 12);
    }

    /**
     * Every Sub Task's list of the cabinet as the page shows it (its items and the lists it compares with), and the bars
     * (the same shape as an edit's answer, one per Sub Task).
     */
    public function cabinetLists(\App\Models\Cabinet $cabinet, ?User $user = null): array
    {
        $cabinet->load('tasks.subtasks.checklists.completedBy:id,name');
        if ($user) {
            WorkScope::trimCabinet($cabinet, $user);   // the same Sub Tasks the page shows them
        }
        $handoff = app(HandoffService::class);
        $lists = [];
        $progress = [];
        $row = fn ($m, array $c) => ['id' => $m->id, 'progress' => $m->progress, 'status' => $m->status, 'completed_checklists' => $c['completed'], 'total_checklists' => $c['total']];
        foreach ($cabinet->tasks as $task) {
            $task->setRelation('cabinet', $cabinet);
            foreach ($task->subtasks as $s) {
                $s->setRelation('cabinetTask', $task);
                $lists[$s->id] = ['items' => $this->items('subtask', $s), 'linked' => $handoff->linkedForSubtask($s)];
                $progress[] = ['subtask' => $row($s, ['completed' => $s->checklists->where('is_completed', true)->count(), 'total' => $s->checklists->count()]),
                    'task' => $row($task, $task->checklist_counts), 'cabinet' => $row($cabinet, $cabinet->checklist_counts)];
            }
        }

        return ['lists' => $lists, 'progress' => $progress];
    }

    /** The bars the cabinet page shows (same shape as the tick endpoint's answer). */
    public function progressOf(CabinetSubtask $s): array
    {
        $task = $s->cabinetTask->fresh('subtasks.checklists');
        $cabinet = $task->cabinet->fresh('tasks.subtasks.checklists');
        $row = fn ($m, array $c) => ['id' => $m->id, 'progress' => $m->progress, 'status' => $m->status, 'completed_checklists' => $c['completed'], 'total_checklists' => $c['total']];

        return [
            'subtask' => $row($s, ['completed' => $s->checklists()->where('is_completed', true)->count(), 'total' => $s->checklists()->count()]),
            'task' => $row($task, $task->checklist_counts),
            'cabinet' => $row($cabinet, $cabinet->checklist_counts),
        ];
    }
}
