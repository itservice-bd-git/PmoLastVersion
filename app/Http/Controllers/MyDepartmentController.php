<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\Project;
use App\Services\CommentAttachmentUploader;
use App\Services\DepartmentDashboard;
use App\Services\NotificationService;
use App\Services\ProjectEditor;
use App\Services\WorkScope;
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

    // ---- Project modal (the wide "โครงการ" dialog opened from a calendar bar) ----

    /**
     * A user may open a Project here if they are PMO-level, or their own department has dispatched work in it -
     * the same visibility as the calendar itself (not "any project by id").
     */
    private function authorizeProject(Request $request, Project $project): void
    {
        $user = $request->user();

        abort_unless(
            $user->canViewOtherDepartments() || $this->projectSubtasks($project, $user)->exists(),
            403,
            'คุณไม่มีสิทธิ์ดูโครงการนี้'
        );
    }

    /** Dispatched Sub Tasks of a Project the user may see (PMO: all departments, others: their own) - see WorkScope. */
    private function projectSubtasks(Project $project, $user)
    {
        return WorkScope::projectSubtasks($project, $user);
    }

    private function projectBlock(Project $project, $user): array
    {
        $project->loadMissing('projectManager');

        return [
            'id' => $project->id,
            'project_no' => $project->project_no,
            'project_name' => $project->project_name,
            'customer_name' => $project->customer_name,
            'status' => $project->status,
            'priority' => $project->priority,
            'start_date' => $project->start_date?->format('Y-m-d'),
            'due_date' => $project->due_date?->format('Y-m-d'),
            'description' => $project->description,
            'manager_name' => $project->projectManager?->name,
            'locked' => $project->isLocked(),
            'can_edit' => $user->canDispatchWork(),
            'statuses' => Project::$statuses,
            'priorities' => Project::$priorities,
            'urls' => [
                'show' => route('projects.show', $project),
                'update' => route('my-department.project.update', $project),
                'note' => route('my-department.project.note', $project),
                'destroy' => route('projects.destroy', $project),
            ],
        ];
    }

    private function activityItem(ActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'user_name' => $log->user?->name ?? 'ระบบ',
            'action' => $log->action,
            'is_note' => $log->action === 'note',
            'description' => $log->description,
            'created_at' => $log->created_at?->format('d/m/Y H:i'),
            'attachments' => $log->attachments->map(fn ($a) => ['name' => $a->original_name, 'url' => $a->url, 'size' => $a->size_for_humans])->values(),
        ];
    }

    public function project(Request $request, Project $project)
    {
        $this->authorizeProject($request, $project);
        $user = $request->user();
        $today = today();

        $subtasks = $this->projectSubtasks($project, $user)
            ->with('cabinetTask.cabinet.project', 'owner', 'department')
            ->withCount([
                'checklists',
                'checklists as completed_checklists_count' => fn ($q) => $q->where('is_completed', true),
            ])
            ->orderBy('due_date')->orderBy('id')->get();

        $items = $subtasks->map(fn ($s) => $this->item($s, $user, $today));

        // "ส่งต่อแผนก": one chip per department with work in this project
        $departments = $items->groupBy('department_id')->map(function ($rows) {
            $done = $rows->where('assignment_status', 'COMPLETED')->count();
            $checkTotal = $rows->sum('checklists_total');

            return [
                'id' => $rows->first()['department_id'],
                'name' => $rows->first()['department_name'],
                'total' => $rows->count(),
                'done' => $done,
                'overdue' => $rows->where('is_overdue', true)->count(),
                'due_date' => $rows->pluck('due_date')->filter()->max(),
                'pct' => $checkTotal > 0 ? (int) round($rows->sum('checklists_completed') / $checkTotal * 100) : ($rows->count() ? (int) round($done / $rows->count() * 100) : 0),
            ];
        })->sortBy(fn ($d) => $d['due_date'] ?? '9999-12-31')->values();

        $cabinets = $items->groupBy('cabinet_id')->map(function ($rows) {
            $first = $rows->first();
            $checkTotal = $rows->sum('checklists_total');
            $checkDone = $rows->sum('checklists_completed');

            return [
                'id' => $first['cabinet_id'],
                'mo' => $first['cabinet_mo'],
                'name' => $first['cabinet_name'],
                'checklists_total' => $checkTotal,
                'checklists_completed' => $checkDone,
                'pct' => $checkTotal > 0 ? (int) round($checkDone / $checkTotal * 100) : 0,
                'due_date' => $rows->pluck('due_date')->filter()->max(),
                'items' => $rows->values(),
            ];
        })->sortBy('mo')->values();

        $feed = ActivityLog::query()->where('project_id', $project->id)
            // everyone sees the discussion; the system's own change log is for PMO roles only
            ->when(! $user->canViewOtherDepartments(), fn ($q) => $q->where('action', 'note'))
            // "created" rows of cabinets/tasks/checklists are bulk noise (a template creates hundreds) - keep only the Project's own
            ->where(fn ($q) => $q->where('action', '!=', 'created')->orWhere('loggable_type', Project::class))
            ->with('user', 'attachments')->latest('id')->limit(40)->get();

        return response()->json([
            'project' => $this->projectBlock($project, $user),
            'departments' => $departments,
            'cabinets' => $cabinets,
            'activity' => $feed->map(fn ($l) => $this->activityItem($l))->values(),
            'alert_departments' => $user->canDispatchWork() ? Department::where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    /** Autosave from the modal: one or more fields of the Project, PMO-level roles only. */
    public function updateProject(Request $request, Project $project, ProjectEditor $editor)
    {
        abort_unless($request->user()->canDispatchWork(), 403, 'เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่แก้ไขโครงการได้');

        $editor->update($project, $request->all());

        return response()->json(['project' => $this->projectBlock($project->fresh(), $request->user())]);
    }

    /** A comment (optionally with files, optionally sent as an alert to a department) on the Project's Activity. */
    public function storeProjectNote(Request $request, Project $project, CommentAttachmentUploader $uploader, NotificationService $notifications)
    {
        $this->authorizeProject($request, $project);
        $user = $request->user();

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:20480', 'mimes:'.CommentAttachmentUploader::ALLOWED_MIMES],
            'alert_department_id' => ['nullable', 'integer', 'exists:departments,id'],
        ]);

        $alertTo = ! empty($data['alert_department_id']) ? Department::find($data['alert_department_id']) : null;
        abort_if($alertTo && ! $user->canDispatchWork(), 403, 'เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่ส่งแจ้งเตือนไปแผนกได้');

        $log = ActivityLog::record($project->id, $user->id, $project, 'note', $data['note']);
        foreach ($request->file('attachments', []) as $file) {
            $uploader->store($log, $file, $user->id);
        }

        $notified = $alertTo ? $notifications->projectAlert($project, $alertTo, $user, $data['note']) : 0;

        return response()->json([
            'activity' => $this->activityItem($log->load('user', 'attachments')),
            'notified' => $notified,
        ]);
    }

    /**
     * Numbers for the page's "แดชบอร์ด" tab. Department scope comes from resolveDepartment() (the one place that
     * decides what this user may look at), so a department user cannot ask for another department's figures.
     */
    public function dashboard(Request $request, DepartmentDashboard $dashboard)
    {
        $department = $this->resolveDepartment($request);
        $scope = $department ? [$department->id] : null;
        $today = today();

        return response()->json($dashboard->build($scope, $today) + [
            'projects' => $dashboard->projects($scope, $today),
            'department' => $department ? ['id' => $department->id, 'name' => $department->name] : ['id' => null, 'name' => 'ทุกแผนก'],
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

        // read-only lists / CSV: a department marked "sees everything" (Settings > แผนก) may ask for every department too
        if ($user->canSeeAllWork()) {
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

    /**
     * The signed-in user's starred Sub Task ids - one query per request, kept on the
     * request itself (not on this controller) so it can never outlive that request.
     */
    private function starredIds($user): \Illuminate\Support\Collection
    {
        $bag = request()->attributes;
        $key = 'starred_subtask_ids.'.$user->id;

        if (! $bag->has($key)) {
            $bag->set($key, $user->starredSubtasks()->pluck('cabinet_subtasks.id'));
        }

        return $bag->get($key);
    }

    /**
     * Toggle the signed-in user's personal star on a Sub Task. Same visibility rule as
     * show(): you can only star work you are allowed to see.
     */
    public function toggleStar(Request $request, CabinetSubtask $cabinetSubtask)
    {
        $user = $request->user();

        abort_unless(
            $cabinetSubtask->department_id !== null
                && ($user->canViewOtherDepartments() || $user->department_id === $cabinetSubtask->department_id),
            403,
            'คุณไม่มีสิทธิ์ดูงานของแผนกนี้'
        );

        $starred = ! $user->starredSubtasks()->where('cabinet_subtasks.id', $cabinetSubtask->id)->exists();
        $starred ? $user->starredSubtasks()->attach($cabinetSubtask->id) : $user->starredSubtasks()->detach($cabinetSubtask->id);

        return response()->json(['starred' => $starred]);
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
            'owner_id' => $subtask->owner_id,
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
            'is_starred' => $this->starredIds($user)->contains($subtask->id),
            'urls' => [
                'accept' => route('cabinet-subtasks.accept', $subtask),
                'start' => route('cabinet-subtasks.start', $subtask),
                'complete' => route('cabinet-subtasks.complete', $subtask),
                'detail' => route('my-department.show', $subtask),
                'star' => route('my-department.star', $subtask),
            ],
        ] + $this->assignments->actionsFor($subtask, $user);
    }
}
