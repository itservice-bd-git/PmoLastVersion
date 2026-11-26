<?php

namespace App\Services\Concerns;

use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\Project;
use App\Services\ProductionCurve;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * DailyWork by project ("สรุปงานรายโครงการ", reports/today/projects): the same counts as a department's card, one card
 * per project, from the same Sub Tasks (so a department-scoped viewer still sees only its departments' work), plus
 * where the project stands: production %, due date, days left, and which departments hold the most of its work.
 * Every running project gets a card (a viewer with every department); a closed one only when its work moved in the range.
 */
trait DailyWorkProjects
{
    /**
     * @param  list<int>|null  $departmentIds  the viewer's scope (null = every department)
     * @param  Collection<int, string>  $departmentNames  department id => name, the departments shown
     */
    private function projectRows(?array $departmentIds, Collection $departmentNames, Collection $receivedSubtasks, Collection $completions,
        Collection $pendingSubtasks, array $chartBars, Carbon $rangeStart, Carbon $rangeEnd, Carbon $today, \Closure $receivedBetween, \Closure $completedBetween, ?int $onlyProject = null): Collection
    {
        $projectOf = fn (CabinetSubtask $subtask) => (int) $subtask->cabinetTask?->cabinet?->project_id;
        $ticks = $this->ticksPerProject($departmentIds, $rangeStart, $rangeEnd);
        // today's ticks, whatever the range: the card's "since you last looked" counts from them (projectSummary.badgeTicks)
        $lastTicks = $this->lastTickPerProject($departmentIds);   // "เรียงตาม: เคลื่อนไหวล่าสุด"
        $ticksToday = $rangeStart->isSameDay($today) && $rangeEnd->isSameDay($today) ? $ticks : $this->ticksPerProject($departmentIds, $today->copy(), $today->copy()->endOfDay());
        $projectIds = $receivedSubtasks->toBase()->map($projectOf)
            ->merge($completions->map(fn ($completion) => $projectOf($completion['subtask'])))
            ->merge($pendingSubtasks->toBase()->map($projectOf))
            ->merge($ticks->keys())
            ->filter()->unique();

        $projects = Project::query()
            ->where(fn ($query) => $query->whereIn('id', $projectIds->all())
                ->when($departmentIds === null, fn ($running) => $running->orWhereNotIn('status', Project::LOCKED_STATUSES)))
            ->when($onlyProject !== null, fn ($query) => $query->whereKey($onlyProject))
            ->withAvg('cabinets', 'progress')
            ->with('salesPerson:id,team_id', 'salesPerson.team:id,name')   // ทีมขาย: the team of the project's sales person
            ->get(['id', 'project_no', 'project_name', 'customer_name', 'sales_person_id', 'status', 'start_date', 'due_date', 'due_pending_at', 'created_at']);
        $curves = app(ProductionCurve::class)->build($projects, $today, $rangeStart->copy()->subSecond());   // gain: since the range began
        // each project's work, grouped once (not filtered again for every project)
        $receivedBy = $receivedSubtasks->toBase()->groupBy($projectOf);
        $completedBy = $completions->groupBy(fn ($completion) => $projectOf($completion['subtask']));
        $pendingBy = $pendingSubtasks->toBase()->groupBy($projectOf);

        return $projects->map(function (Project $project) use (
            $receivedBy, $completedBy, $pendingBy, $curves, $ticks, $ticksToday, $lastTicks, $departmentNames, $chartBars, $rangeStart, $rangeEnd, $today, $receivedBetween, $completedBetween
        ) {
            $received = $receivedBy->get($project->id, collect());
            $completed = $completedBy->get($project->id, collect());
            $pending = $pendingBy->get($project->id, collect());
            $receivedInRange = $receivedBetween($received, $rangeStart, $rangeEnd);
            $completedInRange = $completedBetween($completed, $rangeStart, $rangeEnd);
            $waitingCount = $pending->where('assignment_status', CabinetSubtask::ASSIGNMENT_ASSIGNED)->count();
            $isDuePending = $project->isDuePending();
            $isClosed = in_array($project->status, Project::LOCKED_STATUSES, true);
            $daysLeft = $project->due_date && ! $isDuePending && ! $isClosed ? (int) $today->diffInDays($project->due_date, false) : null;
            $progress = $project->production_progress;

            $row = [
                'id' => $project->id,
                'name' => (string) ($project->project_name ?: $project->project_no),
                'icon' => '',
                'projectNo' => (string) $project->project_no,
                'customer' => (string) $project->customer_name,
                'team' => (string) $project->salesPerson?->team?->name,
                'url' => route('projects.show', $project),
                'statusLabel' => $isClosed ? (Project::$statuses[$project->status] ?? $project->status) : null,
                'progress' => $progress,
                'curve' => $curves[$project->id],   // production % over time against the plan (ProductionCurve)
                'dueDate' => $isDuePending ? 'รอเซลแจ้งวันส่ง' : ($project->due_date ? $this->dayAndMonth($project->due_date).' '.($project->due_date->year + 543) : 'ยังไม่ลงวันส่ง'),
                'daysLeft' => $daysLeft,
                // late: past due and not made; soon: due within a week and under 80% made (Project::risk_level's rule)
                'dueTone' => $isClosed || $daysLeft === null || $progress >= 100 ? 'none' : ($daysLeft < 0 ? 'late' : ($daysLeft <= 7 && $progress < 80 ? 'soon' : 'none')),
                'received' => $receivedInRange->count(),
                'receivedCabinets' => $this->cabinetQuantity($receivedInRange),
                'completed' => $completedInRange->count(),
                'completedCabinets' => $this->cabinetQuantity($completedInRange),
                'waiting' => $waitingCount,
                'inProgress' => $pending->count() - $waitingCount,
                'overdue' => $pending->filter(fn ($subtask) => $subtask->isLateOn($today))->count(),
                'pending' => $pending->count(),
                'ticked' => (int) $ticks->get($project->id, 0),   // Checklist items ticked in the range: the card's เคลื่อนไหว number
                'tickedToday' => (int) $ticksToday->get($project->id, 0),
                // the latest Checklist tick (any day): for sorting by the latest movement, and said in the card's tooltip
                'lastTickAt' => ($lastTick = $lastTicks->get($project->id)) ? $lastTick->getTimestamp() : null,
                'lastTickLabel' => $lastTick ? ($lastTick->isSameDay($today) ? 'วันนี้ ' : $this->dayAndMonth($lastTick).' ').$lastTick->format('H:i') : null,
                'unacknowledged' => 0,   // "งานเข้าใหม่" is per department: on the department page
                // the departments holding most of its work now, most first (at most 3)
                'pendingDepartments' => $pending->countBy('department_id')->sortDesc()->take(3)
                    ->map(fn ($count, $departmentId) => ['name' => (string) $departmentNames->get($departmentId), 'count' => $count])->values()->all(),
                'chart' => array_map(fn ($bar) => [
                    'date' => $bar['start']->format('Y-m-d'),
                    'shortLabel' => $bar['shortLabel'],
                    'label' => $bar['label'],
                    'isHighlighted' => $bar['isHighlighted'],
                    'received' => $receivedBetween($received, $bar['start'], $bar['end'])->count(),
                    'completed' => $completedBetween($completed, $bar['start'], $bar['end'])->count(),
                ], $chartBars),
                'isClosed' => $isClosed,
                'dueSort' => $project->due_date && ! $isDuePending ? $project->due_date->format('Y-m-d') : '9999-12-31',
            ];
            $row['isQuiet'] = $row['received'] + $row['completed'] + $row['pending'] + $row['ticked'] === 0;

            return $row;
        })
            // running before closed, with work before quiet, the nearest due date first, so cards stay put between refreshes
            ->sortBy(fn ($row) => [$row['isClosed'] ? 1 : 0, $row['isQuiet'] ? 1 : 0, $row['dueSort'], $row['projectNo']])
            ->map(fn ($row) => collect($row)->except(['dueSort', 'isClosed'])->all())
            ->values();
    }

    /**
     * Checklist items ticked in the range, per project (still ticked now; their Sub Task, Task and cabinet not deleted).
     * A department-scoped viewer counts only its departments' Sub Tasks.
     *
     * @param  list<int>|null  $departmentIds
     * @return Collection<int, int> project id => items
     */
    private function ticksPerProject(?array $departmentIds, Carbon $rangeStart, Carbon $rangeEnd): Collection
    {
        if ($departmentIds === []) {
            return collect();
        }

        return CabinetChecklist::query()
            ->join('cabinet_subtasks', 'cabinet_subtasks.id', '=', 'cabinet_checklists.cabinet_subtask_id')
            ->join('cabinet_tasks', 'cabinet_tasks.id', '=', 'cabinet_subtasks.cabinet_task_id')
            ->join('cabinets', 'cabinets.id', '=', 'cabinet_tasks.cabinet_id')
            ->whereNull('cabinet_subtasks.deleted_at')->whereNull('cabinet_tasks.deleted_at')->whereNull('cabinets.deleted_at')
            ->where('cabinet_checklists.is_completed', true)
            ->whereBetween('cabinet_checklists.completed_at', [$rangeStart, $rangeEnd])
            ->when($departmentIds !== null, fn ($query) => $query->whereIn('cabinet_subtasks.department_id', $departmentIds))
            ->groupBy('cabinets.project_id')
            ->selectRaw('cabinets.project_id as project_id, count(*) as items')
            ->pluck('items', 'project_id')
            ->map(fn ($items) => (int) $items);
    }

    /**
     * The latest Checklist tick of each project, whenever it was (still ticked now; nothing deleted). A department-scoped
     * viewer: its departments' Sub Tasks only.
     *
     * @param  list<int>|null  $departmentIds
     * @return Collection<int, Carbon> project id => when
     */
    private function lastTickPerProject(?array $departmentIds): Collection
    {
        if ($departmentIds === []) {
            return collect();
        }

        return CabinetChecklist::query()
            ->join('cabinet_subtasks', 'cabinet_subtasks.id', '=', 'cabinet_checklists.cabinet_subtask_id')
            ->join('cabinet_tasks', 'cabinet_tasks.id', '=', 'cabinet_subtasks.cabinet_task_id')
            ->join('cabinets', 'cabinets.id', '=', 'cabinet_tasks.cabinet_id')
            ->whereNull('cabinet_subtasks.deleted_at')->whereNull('cabinet_tasks.deleted_at')->whereNull('cabinets.deleted_at')
            ->where('cabinet_checklists.is_completed', true)->whereNotNull('cabinet_checklists.completed_at')
            ->when($departmentIds !== null, fn ($query) => $query->whereIn('cabinet_subtasks.department_id', $departmentIds))
            ->groupBy('cabinets.project_id')
            ->selectRaw('cabinets.project_id as project_id, max(cabinet_checklists.completed_at) as last_at')
            ->pluck('last_at', 'project_id')
            ->map(fn ($at) => Carbon::parse($at));
    }
}
