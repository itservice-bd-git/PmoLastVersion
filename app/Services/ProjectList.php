<?php

namespace App\Services;

use App\Models\CabinetSubtask;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** The โครงการ list page and its CSV: the filters (search / status / priority / due), the order, and the department dots. */
class ProjectList
{
    public const CSV_HEADERS = ['Project No.', 'ชื่อโครงการ', 'ลูกค้า', 'PO No.', 'สถานะ', 'ความสำคัญ', 'Project Manager', 'ประเภทงาน', 'เริ่ม', 'กำหนดส่ง', 'จำนวนตู้', 'ความคืบหน้าการผลิต', 'ความเสี่ยง'];

    /** The projects this person may see, narrowed by the list's filters. */
    public function query(Request $request): Builder
    {
        $query = Project::visibleTo($request->user())
            ->withAvg('cabinets', 'progress')
            ->withAvg('projectTasks', 'progress')
            ->withCount('cabinets');

        if ($search = $request->get('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('project_no', 'like', "%{$search}%")
                    ->orWhere('project_name', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%");
            });
        }

        if ($status = $request->get('status')) {
            if ($status === 'not_started') {   // the dashboard card "รอเริ่ม": Draft and Planning together
                $query->whereIn('status', [Project::STATUS_DRAFT, Project::STATUS_PLANNING]);
            } elseif ($status === 'closed') {   // "ปิดแล้ว": Completed and Cancelled together (the link under the list)
                $query->whereIn('status', Project::LOCKED_STATUSES);
            } else {
                $query->where('status', $status);
            }
        } elseif (! $request->filled('q')) {
            // the open ones by default, as Planning's dashboard; a search still finds a closed one
            $query->whereNotIn('status', Project::LOCKED_STATUSES);
        }

        if (in_array($priority = $request->get('priority'), [Project::PRIORITY_URGENT, Project::PRIORITY_HIGH], true)) {   // the ⚑ ด่วน / ⚑ สูง buttons
            $query->where('priority', $priority);
        }

        // the dashboard cards "ล่าช้า" / "ตามแผน": in progress and past / not past the due date (Project::scopeRunningLate)
        match ($request->get('due')) {
            'late' => $query->runningLate(),
            'ontrack' => $query->runningLate(false),
            default => null,
        };

        return $query;
    }

    /**
     * The same list and order as Planning's dashboard table (resources/js/planning/dashboard.js dash().rows): the open projects,
     * late first - its due date passed and not marked done on Planning - then those waiting for sales to give the new
     * dates (รอฝ่ายขายแจ้งกำหนดส่ง), then the nearest due date, then the order made (id); no due date last.
     * Closed ones (ปิดแล้ว, or found by a search) come after, in the same order.
     */
    public function ordered(Builder $query): Builder
    {
        return $query
            ->orderByRaw('CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END', [Project::STATUS_COMPLETED, Project::STATUS_CANCELLED])
            ->orderByRaw('CASE WHEN due_pending_at IS NOT NULL THEN 1 WHEN due_date < ? AND NOT EXISTS (SELECT 1 FROM board_statuses bs WHERE bs.id = projects.board_status_id AND bs.is_done = ?) THEN 0 ELSE 2 END', [today()->toDateString(), true])
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderBy('id');
    }

    /** How many closed projects the default list (no status, no search) leaves out, for the link under it; 0 otherwise. */
    public function closedCount(Request $request): int
    {
        return $request->filled('status') || $request->filled('q') ? 0
            : $this->query($request->duplicate(array_merge($request->query(), ['status' => 'closed'])))->count();
    }

    /** Which departments have dispatched work in each listed project (the coloured dots, as on Planning): project id => [department ids]. */
    public function departmentsOf(Collection $projectIds): Collection
    {
        return CabinetSubtask::query()
            ->join('cabinet_tasks', 'cabinet_tasks.id', '=', 'cabinet_subtasks.cabinet_task_id')
            ->join('cabinets', 'cabinets.id', '=', 'cabinet_tasks.cabinet_id')
            ->whereIn('cabinets.project_id', $projectIds)
            ->whereNull('cabinet_tasks.deleted_at')->whereNull('cabinets.deleted_at')
            ->whereNotNull('cabinet_subtasks.department_id')
            ->whereIn('cabinet_subtasks.assignment_status', WorkScope::ACTIVE_STATUSES)
            ->distinct()->get(['cabinets.project_id', 'cabinet_subtasks.department_id'])
            ->groupBy('project_id')->map(fn ($rows) => $rows->pluck('department_id')->all());
    }

    /** One CSV row per project, in the order of CSV_HEADERS. */
    public function csvRow(Project $p): array
    {
        return [
            $p->project_no,
            $p->project_name,
            $p->customer_name,
            $p->po_no,
            Project::$statuses[$p->status] ?? $p->status,
            Project::$priorities[$p->priority] ?? $p->priority,
            $p->projectManager?->name,
            $p->jobType?->name,
            $p->start_date?->thaiDate(),
            $p->due_date?->thaiDate(),
            $p->cabinets_count,
            $p->production_progress.'%',
            ['overdue' => 'Delayed', 'at_risk' => 'At Risk', 'pending' => 'Waiting for a new due date'][$p->risk_level] ?? 'On Track',
        ];
    }
}
