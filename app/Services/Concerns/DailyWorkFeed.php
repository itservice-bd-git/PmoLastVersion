<?php

namespace App\Services\Concerns;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\Project;
use App\Services\WorkScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/** DailyWork: what moved (received / accepted / started / done / new cabinets), the work still waiting, and the feed of it. */
trait DailyWorkFeed
{
    /**
     * The dispatched Sub Tasks of these departments that moved between $start and $end: dispatched, accepted, started
     * or finished. One query; every count and the feed are worked out from it.
     */
    private function movedSubtasks(array $departmentIds, Carbon $start, Carbon $end): Collection
    {
        if ($departmentIds === []) {
            return collect();
        }

        return CabinetSubtask::query()
            ->whereIn('department_id', $departmentIds)
            ->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES)
            ->where(fn ($query) => $query->whereBetween('assigned_at', [$start, $end])
                ->orWhereBetween('accepted_at', [$start, $end])
                ->orWhereBetween('started_at', [$start, $end])
                ->orWhereBetween('completed_at', [$start, $end])
                ->orWhereBetween('completed_date', [$start, $end]))
            ->whereHas('cabinetTask.cabinet.project')
            ->withMax(['checklists as last_tick_at' => fn ($query) => $query->where('is_completed', true)], 'completed_at')
            ->with(['cabinetTask:id,cabinet_id', 'cabinetTask.cabinet:id,project_id,mo_no,cabinet_name,quantity', 'cabinetTask.cabinet.project:id,project_no,project_name'])
            ->get(['id', 'cabinet_task_id', 'name', 'department_id', 'assignment_status', 'status', 'is_active',
                'assigned_at', 'accepted_at', 'started_at', 'completed_at', 'completed_date']);
    }

    /**
     * When a Sub Task was done: the earlier of "เสร็จงาน" pressed and the checklist completed (that day's last tick
     * gives the time; without one the time is unknown). Null = not done, or done with no date at all (old data).
     *
     * @return array{at: Carbon, hasTime: bool}|null
     */
    private function completion(CabinetSubtask $subtask): ?array
    {
        if (! $subtask->isDone()) {
            return null;
        }
        $byChecklist = null;
        if ($subtask->completed_date) {
            $lastTick = $subtask->last_tick_at ? Carbon::parse($subtask->last_tick_at) : null;
            $byChecklist = $lastTick && $lastTick->isSameDay($subtask->completed_date)
                ? ['at' => $lastTick, 'hasTime' => true]
                : ['at' => $subtask->completed_date->copy()->startOfDay(), 'hasTime' => false];
        }
        $byButton = $subtask->completed_at ? ['at' => $subtask->completed_at, 'hasTime' => true] : null;

        if ($byButton && $byChecklist) {   // both: the earlier day; on the same day "เสร็จงาน" (it has a real time)
            return $byChecklist['at']->copy()->startOfDay()->lt($byButton['at']->copy()->startOfDay()) ? $byChecklist : $byButton;
        }

        return $byButton ?? $byChecklist;
    }

    /**
     * Cabinets added to a project in the range, newest first. Everyone with a department scope sees only the cabinets
     * it has dispatched work in, the same rule as its Sub Tasks (WorkScope).
     *
     * @param  list<int>|null  $departmentIds  the viewer's scope (null = every department)
     * @param  list<int>  $shownDepartmentIds  the active departments within it
     */
    private function newCabinets(?array $departmentIds, array $shownDepartmentIds, Carbon $start, Carbon $end): Collection
    {
        if ($departmentIds !== null && $shownDepartmentIds === []) {
            return collect();
        }

        return Cabinet::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereHas('project')
            ->when($departmentIds !== null, fn ($query) => $query->whereHas('tasks.subtasks', fn ($subtasks) => $subtasks
                ->whereIn('department_id', $shownDepartmentIds)
                ->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES)))
            ->with('project:id,project_no,project_name')
            ->latest()
            ->get(['id', 'project_id', 'mo_no', 'cabinet_name', 'quantity', 'created_at']);
    }

    /** The work these departments hold right now; a closed project's is finished business, as on the dashboard. */
    private function pendingSubtasks(array $departmentIds): Collection
    {
        if ($departmentIds === []) {
            return collect();
        }

        return CabinetSubtask::query()
            ->whereIn('department_id', $departmentIds)
            ->whereIn('assignment_status', self::PENDING_ASSIGNMENT_STATUSES)
            ->notDone()
            ->whereHas('cabinetTask.cabinet.project', fn ($query) => $query->whereNotIn('status', [Project::STATUS_COMPLETED, Project::STATUS_CANCELLED]))
            ->get(['id', 'department_id', 'assignment_status', 'status', 'due_date', 'is_active']);
    }

    /** "มีงานเข้า": the pending work not opened yet, per department newest first, as feed events (the page groups them by department) */
    private function newWork(array $departmentIds): array
    {
        if ($departmentIds === []) {
            return [];
        }

        return CabinetSubtask::query()
            ->whereIn('department_id', $departmentIds)
            ->whereIn('assignment_status', self::PENDING_ASSIGNMENT_STATUSES)
            ->where('is_active', false)
            ->notDone()
            ->whereHas('cabinetTask.cabinet.project', fn ($query) => $query->whereNotIn('status', [Project::STATUS_COMPLETED, Project::STATUS_CANCELLED]))
            ->with(['cabinetTask:id,cabinet_id', 'cabinetTask.cabinet:id,project_id,mo_no,cabinet_name,quantity', 'cabinetTask.cabinet.project:id,project_no,project_name'])
            ->orderByDesc('assigned_at')->orderByDesc('id')
            ->get(['id', 'cabinet_task_id', 'name', 'department_id', 'is_active', 'assigned_at'])
            ->groupBy('department_id')
            ->flatMap(fn ($subtasks) => $subtasks->take(self::NEW_WORK_LIMIT))
            ->map(fn ($subtask) => ['key' => 'new-'.$subtask->id]
                + $this->workEvent($subtask, self::EVENT_RECEIVED, $subtask->assigned_at, true, $subtask->assigned_at ? $this->dayAndMonth($subtask->assigned_at) : null))
            ->values()->all();
    }

    /**
     * one line of work in the feed; isNew = nobody has opened it from this page yet ("มีงานเข้า"). Its link opens the
     * cabinet at that Sub Task (#goto-subtask-{id}: the cabinet page starts at its top, then scrolls down and opens it)
     */
    private function workEvent(CabinetSubtask $subtask, string $type, ?Carbon $at, bool $hasTime, ?string $date): array
    {
        $cabinet = $subtask->cabinetTask?->cabinet;

        return [
            'key' => $type.'-'.$subtask->id,
            'type' => $type,
            'typeLabel' => self::EVENT_LABELS[$type],
            'time' => $at && $hasTime ? $at->format('H:i') : null,
            'date' => $date,
            'departmentId' => $subtask->department_id,
            'title' => (string) $subtask->name,
            'projectNo' => (string) $cabinet?->project?->project_no,
            'projectName' => (string) $cabinet?->project?->project_name,
            'detail' => (string) ($cabinet?->mo_no ?: $cabinet?->cabinet_name),
            'url' => $cabinet ? route('cabinets.show', $cabinet->id).'#goto-subtask-'.$subtask->id : null,
            'subtaskId' => $subtask->id,
            'isNew' => ! $subtask->is_active,
        ];
    }

    /** How many cabinets (their quantity) the Sub Tasks belong to, each cabinet once. */
    private function cabinetQuantity(Collection $subtasks): int
    {
        return (int) $subtasks->map(fn ($subtask) => $subtask->cabinetTask?->cabinet)->filter()->unique('id')
            ->sum(fn ($cabinet) => max(1, (int) $cabinet->quantity));
    }

    /**
     * What happened in the range, newest first; a checklist completed without a tick time comes last in its day
     * ("ไม่ทราบเวลา"). Over more than one day each event also says which day.
     *
     * @return array{0: list<array>, 1: int} the events listed, and how many work events (not cabinets) there were in all
     */
    private function feed(Collection $movedSubtasks, Collection $newCabinets, Carbon $start, Carbon $end, bool $showDate): array
    {
        $workEvents = collect();
        $dateLabel = fn (Carbon $at) => $showDate ? $this->dayAndMonth($at) : null;
        foreach ($movedSubtasks as $subtask) {
            $addEvent = function (string $type, Carbon $at, bool $hasTime = true) use ($workEvents, $subtask, $dateLabel) {
                $workEvents->push($this->workEvent($subtask, $type, $at, $hasTime, $dateLabel($at))
                    + ['sortAt' => $hasTime ? $at->getTimestamp() : $at->copy()->startOfDay()->getTimestamp()]);
            };
            $steps = [
                self::EVENT_RECEIVED => $subtask->assigned_at,
                self::EVENT_ACCEPTED => $subtask->accepted_at,
                self::EVENT_STARTED => $subtask->started_at,
            ];
            foreach ($steps as $type => $at) {
                if ($at && $at->between($start, $end)) {
                    $addEvent($type, $at);
                }
            }
            $completed = $this->completion($subtask);
            if ($completed && $completed['at']->between($start, $end)) {
                $addEvent(self::EVENT_COMPLETED, $completed['at'], $completed['hasTime']);
            }
        }

        $workEventCount = $workEvents->count();
        $events = $workEvents->sortByDesc('sortAt')->take(self::FEED_WORK_LIMIT);
        foreach ($newCabinets->take(self::FEED_NEW_CABINET_LIMIT) as $cabinet) {
            $events->push([
                'key' => self::EVENT_NEW_CABINET.'-'.$cabinet->id,
                'type' => self::EVENT_NEW_CABINET,
                'typeLabel' => self::EVENT_LABELS[self::EVENT_NEW_CABINET],
                'time' => $cabinet->created_at->format('H:i'),
                'date' => $dateLabel($cabinet->created_at),
                'sortAt' => $cabinet->created_at->getTimestamp(),
                'departmentId' => null,
                'title' => (string) $cabinet->cabinet_name,
                'projectNo' => (string) $cabinet->project?->project_no,
                'projectName' => (string) $cabinet->project?->project_name,
                'detail' => max(1, (int) $cabinet->quantity).' ตู้'.($cabinet->mo_no ? ' · '.$cabinet->mo_no : ''),
                'url' => route('cabinets.show', $cabinet->id),
                'subtaskId' => null,
                'isNew' => false,
            ]);
        }

        $events = $events->sortByDesc('sortAt')->map(fn ($event) => collect($event)->except('sortAt')->all())->values()->all();

        return [$events, $workEventCount];
    }
}
