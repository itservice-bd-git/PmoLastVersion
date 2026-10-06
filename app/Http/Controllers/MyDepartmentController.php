<?php

namespace App\Http\Controllers;

use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\Project;
use App\Services\SubtaskAssignmentService;
use App\Support\Csv;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * "My Department" calendar/list. Pure read-side view over the existing
 * cabinet_subtasks + Department Assignment data - nothing is stored for it.
 * Accept/start/complete and checklist toggles reuse the existing endpoints.
 */
class MyDepartmentController extends Controller
{
    public function __construct(private SubtaskAssignmentService $assignments) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $canViewOthers = $user->canViewOtherDepartments();
        $department = ($canViewOthers || $user->department_id) ? $this->resolveDepartment($request) : null;

        return view('my-department.index', [
            // $department is null in two different cases the view must tell
            // apart: "no department at all" (blocked, see the @if above in
            // the view) vs "PMO viewing ทุกแผนก" (canViewOthers true) - the
            // view already branches on canViewOthers for that reason.
            'department' => $department,
            'canViewOthers' => $canViewOthers,
            'departments' => $canViewOthers ? Department::where('is_active', true)->orderBy('name')->get() : collect(),
            // Project filter (point 5) - available to everyone, not just PMO:
            // it only narrows further within whatever department scope already
            // applies, same as Status, so it can't leak another department's data.
            'projects' => Project::orderBy('project_no')->get(['id', 'project_no', 'project_name']),
        ]);
    }

    /**
     * JSON for one calendar window (the visible month grid), scoped to a single
     * department on the server. Also returned regardless of window: overdue
     * work (so the list/"เกินกำหนด" filter never hides it) and undated work.
     */
    public function tasks(Request $request)
    {
        [$department, $subtasks, $today] = $this->scheduleQuery($request);
        $user = $request->user();

        return response()->json([
            'department' => $department ? ['id' => $department->id, 'name' => $department->name] : ['id' => null, 'name' => 'ทุกแผนก'],
            'is_all_departments' => $department === null,
            'today' => $today->format('Y-m-d'),
            'items' => $subtasks->map(fn ($s) => $this->item($s, $user, $today))->values(),
        ]);
    }

    /**
     * Same window/department/project/status filters as the screen, as a CSV the
     * department can take to a meeting or into Excel.
     */
    public function export(Request $request)
    {
        [$department, $subtasks, $today] = $this->scheduleQuery($request);
        $user = $request->user();

        $rows = $subtasks->map(fn ($s) => $this->item($s, $user, $today))->map(fn ($i) => [
            $i['department_name'] ?? '-',
            $i['project_no'],
            $i['project_name'],
            $i['cabinet_mo'],
            $i['task_name'],
            $i['name'],
            $i['owner_name'] ?? '-',
            $i['start_date'] ? date('d/m/Y', strtotime($i['start_date'])) : '-',
            $i['due_date'] ? date('d/m/Y', strtotime($i['due_date'])) : '-',
            $i['assignment_status_label'],
            $i['is_overdue'] ? 'เกินกำหนด '.abs($i['days_remaining']).' วัน' : '',
            $i['progress'].'%',
            $i['checklists_completed'].'/'.$i['checklists_total'],
        ]);

        return Csv::download(
            'งานแผนก-'.($department?->name ?? 'ทุกแผนก').'-'.$today->format('Y-m-d').'.csv',
            ['แผนก', 'Project', 'ชื่อโครงการ', 'ตู้ (MO)', 'Task', 'Sub Task', 'ผู้รับผิดชอบ', 'เริ่ม', 'กำหนดส่ง', 'สถานะ', 'เกินกำหนด', 'ความคืบหน้า', 'Checklist เสร็จ/ทั้งหมด'],
            $rows
        );
    }

    /**
     * The one query behind both the calendar JSON and the CSV, so what is exported
     * is exactly what the screen shows.
     *
     * @return array{0: ?Department, 1: \Illuminate\Support\Collection, 2: Carbon}
     */
    private function scheduleQuery(Request $request): array
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            // 'all' (PMO only, resolveDepartment() enforces that) or a real id -
            // resolved below, so this stays a loose string rule here.
            'department_id' => ['nullable', 'string'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'status' => ['nullable', 'in:ASSIGNED,ACCEPTED,IN_PROGRESS,COMPLETED,OVERDUE'],
        ]);

        // null = "ทุกแผนก" (PMO only - resolveDepartment() already enforces
        // canViewOtherDepartments() before this can ever come back null).
        $department = $this->resolveDepartment($request);
        $today = today();

        $subtasks = CabinetSubtask::query()
            ->when($department, fn ($q) => $q->where('department_id', $department->id))
            ->whereIn('assignment_status', [
                CabinetSubtask::ASSIGNMENT_ASSIGNED,
                CabinetSubtask::ASSIGNMENT_ACCEPTED,
                CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
                CabinetSubtask::ASSIGNMENT_COMPLETED,
            ])
            ->when($data['project_id'] ?? null, function ($q) use ($data) {
                $q->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $data['project_id']));
            })
            ->when($data['status'] ?? null, function ($q) use ($data, $today) {
                // OVERDUE is a derived state (due date passed, not completed),
                // never a real assignment_status value - same rule the frontend
                // already uses for is_overdue, just applied server-side here.
                if ($data['status'] === 'OVERDUE') {
                    $q->whereDate('due_date', '<', $today)->where('assignment_status', '!=', CabinetSubtask::ASSIGNMENT_COMPLETED);
                } else {
                    $q->where('assignment_status', $data['status']);
                }
            })
            ->where(function ($q) use ($data, $today) {
                // Active in the window: [start ?? due, due ?? start] overlaps [from, to].
                $q->where(function ($q) use ($data) {
                    $q->whereRaw('COALESCE(start_date, due_date) <= ?', [$data['to']])
                        ->whereRaw('COALESCE(due_date, start_date) >= ?', [$data['from']]);
                })
                    ->orWhere(function ($q) use ($today) {
                        $q->whereDate('due_date', '<', $today)
                            ->where('assignment_status', '!=', CabinetSubtask::ASSIGNMENT_COMPLETED);
                    })
                    ->orWhere(function ($q) {
                        $q->whereNull('start_date')->whereNull('due_date');
                    });
            })
            ->with('cabinetTask.cabinet.project', 'owner', 'department')
            ->withCount([
                'checklists',
                'checklists as completed_checklists_count' => fn ($q) => $q->where('is_completed', true),
            ])
            ->orderBy('due_date')
            ->orderBy('id')
            ->get();

        return [$department, $subtasks, $today];
    }

    /**
     * Sub task detail (with checklist) for the detail modal.
     */
    public function show(Request $request, CabinetSubtask $cabinetSubtask)
    {
        $user = $request->user();

        abort_unless(
            $cabinetSubtask->department_id !== null
                && ($user->canViewOtherDepartments() || $user->department_id === $cabinetSubtask->department_id),
            403,
            'คุณไม่มีสิทธิ์ดูงานของแผนกนี้'
        );

        $cabinetSubtask->load('cabinetTask.cabinet.project', 'department', 'checklists.completedBy', 'acceptedBy', 'owner')
            ->loadCount([
                'checklists',
                'checklists as completed_checklists_count' => fn ($q) => $q->where('is_completed', true),
            ]);

        $checklistEditable = $cabinetSubtask->isChecklistEditableBy($user);

        return response()->json([
            'item' => $this->item($cabinetSubtask, $user, today()) + [
                'department_name' => $cabinetSubtask->department?->name,
                'cabinet_url' => route('cabinets.show', $cabinetSubtask->cabinetTask->cabinet),
                'accepted_by_name' => $cabinetSubtask->acceptedBy?->name,
                'checklist_editable' => $checklistEditable,
                'checklists' => $cabinetSubtask->checklists->map(fn ($c) => [
                    'id' => $c->id,
                    'name' => $c->name,
                    'is_completed' => $c->is_completed,
                    'completed_by_name' => $c->completedBy?->name,
                    'completed_at' => $c->completed_at?->format('d/m/Y H:i'),
                    'toggle_url' => route('checklists.toggle', $c),
                ])->values(),
            ],
        ]);
    }

    /**
     * Which department is being viewed - null means "ทุกแผนก" (PMO only).
     * Regular users are pinned to their own; admin/PM may pick another
     * department, or all of them, via department_id. This is the ONLY place
     * that decision is made - index() and tasks() both call this, so a
     * Department User can never reach another department's data by hand-
     * crafting the request (not just hidden in the UI).
     */
    private function resolveDepartment(Request $request): ?Department
    {
        $user = $request->user();
        $requested = $request->input('department_id');

        if ($user->canViewOtherDepartments()) {
            if ($requested === 'all') {
                return null;
            }

            if ($requested) {
                return Department::findOrFail((int) $requested);
            }

            return $user->department
                ?? Department::where('is_active', true)->orderBy('name')->first()
                ?? abort(404, 'ยังไม่มีแผนกในระบบ');
        }

        abort_unless($user->department_id, 403, 'บัญชีของคุณยังไม่ได้ผูกกับแผนก กรุณาติดต่อผู้ดูแลระบบ');
        abort_if($requested && ((int) $requested !== $user->department_id), 403, 'คุณไม่มีสิทธิ์ดูงานของแผนกอื่น');

        return $user->department;
    }

    private function item(CabinetSubtask $subtask, $user, Carbon $today): array
    {
        $cabinet = $subtask->cabinetTask->cabinet;
        $project = $cabinet->project;
        $completed = $subtask->assignment_status === CabinetSubtask::ASSIGNMENT_COMPLETED;
        $daysRemaining = $subtask->due_date ? (int) $today->diffInDays($subtask->due_date, false) : null;

        return [
            'id' => $subtask->id,
            'project_id' => $project->id,
            'project_no' => $project->project_no,
            'project_name' => $project->project_name,
            'customer_name' => $project->customer_name,
            // User-assigned Calendar color override (nullable) - see taskColor()
            // in _script.blade.php, which falls back to the auto-assigned palette
            // when this is null. project_color_url follows the same per-item URL
            // convention as the `urls` block below instead of being built client-side.
            'color' => $project->color,
            'project_color_url' => route('projects.color', $project),
            'cabinet_id' => $cabinet->id,
            'cabinet_mo' => $cabinet->mo_no,
            'cabinet_name' => $cabinet->cabinet_name,
            'cabinet_task_id' => $subtask->cabinet_task_id,
            'task_name' => $subtask->cabinetTask->name,
            'name' => $subtask->name,
            // Per-item Department (point 32) - needed once "ทุกแผนก" can return
            // items from more than one Department at a time; a single-Department
            // view already knows this from the top-level `department` key, but
            // sending it per item too costs nothing and keeps both views reading
            // the same shape.
            'department_id' => $subtask->department_id,
            'department_name' => $subtask->department?->name,
            'owner_name' => $subtask->owner?->name,
            'start_date' => $subtask->start_date?->format('Y-m-d'),
            'due_date' => $subtask->due_date?->format('Y-m-d'),
            // Sub tasks carry no priority of their own - it is the project's.
            'priority' => $project->priority,
            'priority_label' => Project::$priorities[$project->priority] ?? $project->priority,
            'assignment_status' => $subtask->assignment_status,
            'assignment_status_label' => $subtask->assignment_status_label,
            'is_overdue' => $daysRemaining !== null && $daysRemaining < 0 && ! $completed,
            'is_near_due' => $daysRemaining !== null && $daysRemaining >= 0 && $daysRemaining <= 3 && ! $completed,
            'days_remaining' => $daysRemaining,
            'progress' => $subtask->progress,
            'checklists_total' => $subtask->checklists_count ?? 0,
            'checklists_completed' => $subtask->completed_checklists_count ?? 0,
            'is_department_locked' => $subtask->is_department_locked,
            'urls' => [
                'accept' => route('cabinet-subtasks.accept', $subtask),
                'start' => route('cabinet-subtasks.start', $subtask),
                'complete' => route('cabinet-subtasks.complete', $subtask),
                'detail' => route('my-department.show', $subtask),
            ],
        ] + $this->assignments->actionsFor($subtask, $user);
    }
}
