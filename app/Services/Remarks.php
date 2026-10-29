<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use Illuminate\Support\Collection;

/**
 * Every remark ("หมายเหตุ") of a cabinet in one list: the cabinet's own, each Task's, Sub Task's and checklist item's.
 * Shown as the "หมายเหตุ" tab of the cabinet page and of the Planning project window. It lists only what it is given,
 * so pass work that was already trimmed to what the user may see (WorkScope / PlanningBoard).
 */
class Remarks
{
    private const TYPES = ['t' => CabinetTask::class, 's' => CabinetSubtask::class, 'k' => CabinetChecklist::class];

    /**
     * Who last wrote each remark and when, from the change log: ['s12' => ['by' => 'วิภา · 28/10/2569 09:32', 'at' => unix time], ...].
     * A remark with no logged edit (typed in with a new item, or a cabinet's) has none.
     *
     * @param  array<string, array<int>>  $ids  ['t' => task ids, 's' => sub task ids, 'k' => checklist ids] - the ones holding a remark
     */
    public static function authors(array $ids): array
    {
        $ids = array_filter(array_map(fn ($list) => array_values(array_unique($list)), $ids));
        if (! $ids) {
            return [];
        }

        $out = [];
        ActivityLog::query()
            ->where('action', 'updated')
            ->where('changes', 'like', '%"remark"%')
            ->where(function ($q) use ($ids) {
                foreach ($ids as $prefix => $list) {
                    foreach (array_chunk($list, 500) as $chunk) {
                        $q->orWhere(fn ($w) => $w->where('loggable_type', self::TYPES[$prefix])->whereIn('loggable_id', $chunk));
                    }
                }
            })
            ->with('user:id,name')
            ->orderBy('id')   // the latest edit wins
            ->get(['id', 'user_id', 'loggable_type', 'loggable_id', 'created_at'])
            ->each(function (ActivityLog $log) use (&$out) {
                $out[array_search($log->loggable_type, self::TYPES, true).$log->loggable_id] = [
                    'by' => trim(($log->user?->name ?? '').' · '.$log->created_at->thaiDateTime(), ' ·'), 'at' => $log->created_at->getTimestamp()];
            });

        return $out;
    }

    /**
     * The cabinet page's list, in the cabinet's own order. Expects $cabinet->tasks.subtasks.checklists (+ department) loaded.
     *
     * @return Collection<int, array{level: string, key: string, task: string, path: string, text: string, deptId: ?int, dept: string, color: string, who: string, at: int, subtaskId: ?int, itemId: ?int, taskId: ?int}>
     */
    public static function forCabinet(Cabinet $cabinet): Collection
    {
        $colors = PlanningBoard::departmentColors();
        $rows = collect();
        $row = fn (string $level, string $key, string $task, string $path, string $text, $subtask = null, ?int $itemId = null, ?int $taskId = null) => [
            'level' => $level, 'key' => $key, 'task' => $task, 'path' => $path, 'text' => $text,
            'deptId' => $subtask?->department_id, 'dept' => (string) $subtask?->department?->name,
            'color' => $subtask?->department_id ? ($colors[$subtask->department_id] ?? '#94a3b8') : '#94a3b8',
            'subtaskId' => $subtask?->id, 'itemId' => $itemId, 'taskId' => $taskId,
        ];

        if (filled($cabinet->remark)) {
            $rows->push($row('ตู้', '', '', 'ข้อมูลตู้', (string) $cabinet->remark));
        }
        foreach ($cabinet->tasks as $task) {
            if (filled($task->remark)) {
                $rows->push($row('Task', 't'.$task->id, $task->name, $task->name, (string) $task->remark, null, null, $task->id));
            }
            foreach ($task->subtasks as $subtask) {
                if (filled($subtask->remark)) {
                    $rows->push($row('Sub Task', 's'.$subtask->id, $task->name, $task->name.' › '.$subtask->name, (string) $subtask->remark, $subtask, null, $task->id));
                }
                foreach ($subtask->checklists as $item) {
                    if (filled($item->remark)) {
                        $rows->push($row('Checklist', 'k'.$item->id, $task->name, $task->name.' › '.$subtask->name.' › '.$item->name, (string) $item->remark, $subtask, $item->id, $task->id));
                    }
                }
            }
        }

        $who = self::authors($rows->groupBy(fn ($r) => substr($r['key'], 0, 1))->except('')
            ->map(fn ($g) => $g->map(fn ($r) => (int) substr($r['key'], 1))->all())->all());

        return $rows->map(fn ($r) => $r + ['who' => $who[$r['key']]['by'] ?? '', 'at' => $who[$r['key']]['at'] ?? 0])->values();
    }
}
