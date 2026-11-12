<?php

namespace App\Http\Controllers;

use App\Exceptions\AssignmentException;
use App\Http\Requests\ProjectRequest;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Board;
use App\Models\CabinetTemplate;
use App\Models\Department;
use App\Models\JobType;
use App\Models\Project;
use App\Models\User;
use App\Services\CommentAttachmentUploader;
use App\Services\NewProjectAppointments;
use App\Services\NotificationService;
use App\Services\PlanningBoard;
use App\Services\ProjectFollowers;
use App\Services\ProjectList;
use App\Services\ProjectOverview;
use App\Services\SubtaskAssignmentService;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProjectController extends Controller
{
    public function index(Request $request, ProjectList $list)
    {
        $projects = $list->ordered($list->query($request))
            ->with('latestFat', 'jobType:id,name')   // วัน FAT column (the latest round), ประเภทงาน column
            ->paginate(15)
            ->withQueryString();

        return view('projects.index', [
            'projects' => $projects,
            'projectDepartments' => $list->departmentsOf($projects->pluck('id')),
            'departmentColors' => PlanningBoard::departmentColors(),
            'departmentNames' => Department::pluck('name', 'id'),
            'statuses' => Project::$statuses,
            'filters' => $request->only(['q', 'status', 'priority', 'due']),
            'closedCount' => $list->closedCount($request),
        ]);
    }

    /** Same search/status filters as the list, as a CSV (all matching rows, not just the page). */
    public function export(Request $request, ProjectList $list)
    {
        $projects = $list->query($request)->with('projectManager', 'jobType')->orderBy('project_no')->get();

        return Csv::download('โครงการ-'.today()->format('Y-m-d').'.csv', ProjectList::CSV_HEADERS, $projects->map(fn (Project $p) => $list->csvRow($p)));
    }

    public function create()
    {
        return view('projects.create', $this->formOptions());
    }

    public function store(ProjectRequest $request, ProjectFollowers $followers, NewProjectAppointments $newAppointments)
    {
        $data = $request->projectData();
        $appointments = $newAppointments->validate($request);

        $project = DB::transaction(function () use ($data, $appointments, $request, $followers, $newAppointments) {
            $project = Project::create($data);
            $followers->setSales($project, $request->input('sales_ids', []), $request->user());   // the ticked พนักงานขาย follow it
            $newAppointments->store($project, $appointments, $request->user());

            return $project;
        });

        return redirect()->route('projects.show', $project)->with('success', 'สร้างโครงการเรียบร้อยแล้ว');
    }

    /** The project page's "งานครบทุกแผนกแล้ว" banner: close it as Completed (the model's rules - กฎการทำงาน, the log - apply). */
    public function complete(Project $project)
    {
        if ($project->isLocked()) {
            return back();
        }
        $project->update(['status' => Project::STATUS_COMPLETED]);

        return back()->with('success', "ปิดโครงการ {$project->project_no} เป็น Completed แล้ว");
    }

    /** "ปิดงานเหล่านี้": work ticked through but never closed, closed for its departments (needs "Admin / PM ทำแทนแผนกได้"). */
    public function finishTicked(Request $request, Project $project, SubtaskAssignmentService $assignments)
    {
        $done = 0;
        $failed = [];
        foreach (ProjectOverview::tickedNotClosed($project) as $s) {
            try {
                $assignments->finishTicked($s, $request->user());
                $done++;
            } catch (AssignmentException $e) {
                $failed[] = $s->name.': '.$e->getMessage();
            }
        }
        $back = back();
        if ($done) {
            $back = $back->with('success', "ปิดงาน {$done} งานแล้ว (ทำแทนแผนก)");
        }

        return $failed ? $back->with('error', 'ปิดงานไม่ได้ '.count($failed).' งาน — '.implode(' · ', array_slice($failed, 0, 3))
            .(AppSetting::get('pmo_acts_for_departments') ? '' : ' (เปิด "Admin / PM ทำแทนแผนกได้" ที่ ตั้งค่า › กฎการทำงาน ก่อน)')) : $back;
    }

    public function show(Project $project)
    {
        $project->load([
            'cabinets.tasks.subtasks.department', 'cabinets.tasks.subtasks.owner', 'cabinets.cabinetTemplate',
            'projectManager', 'jobType',
        ]);

        $project->cabinets->each(
            fn ($cabinet) => $cabinet->tasks->each(fn ($task) => $task->setRelation('cabinet', $cabinet))
        );

        // Comments: scoped strictly to this Project itself (not its Cabinets, which
        // have their own separate Comment tab) - the user's own written notes.
        $comments = ActivityLog::where('loggable_type', Project::class)
            ->where('loggable_id', $project->id)
            ->where('action', 'note')
            ->with(['user', 'attachments'])
            ->latest()
            ->paginate(30, pageName: 'comment_page');

        return view('projects.show', [
            'project' => $project,
            'cabinetTemplates' => CabinetTemplate::where('is_active', true)->get(),
            // For the Cabinet Quick Detail Panel's Assignment Modal (department
            // select) - same unfiltered query the Cabinet full page/Dispatch page
            // already use, not a new Department query.
            'departments' => Department::orderBy('name')->get(),
            'comments' => $comments,
            'overview' => app(ProjectOverview::class)->build($project, auth()->user()),
        ]);
    }

    public function edit(Project $project)
    {
        return view('projects.edit', ['project' => $project] + $this->formOptions($project));
    }

    public function update(ProjectRequest $request, Project $project, ProjectFollowers $followers)
    {
        $project->update($request->projectData());
        $followers->setSales($project, $request->input('sales_ids', []), $request->user());

        return redirect()->route('projects.show', $project)->with('success', 'บันทึกการแก้ไขโครงการเรียบร้อยแล้ว');
    }

    /** "ติดตามโครงการ" / "เลิกติดตาม": its news comes to me too (ProjectFollowers). Anyone who sees the project (project.visible). */
    public function follow(Request $request, Project $project, ProjectFollowers $followers)
    {
        $following = $followers->toggle($project, $request->user());
        $message = $following ? 'ติดตามโครงการแล้ว — จะได้รับแจ้งเตือนความเคลื่อนไหว' : 'เลิกติดตามโครงการแล้ว';

        return $request->wantsJson()
            ? response()->json(['following' => $following, 'followers' => $project->followers()->pluck('users.id'), 'message' => $message])
            : back()->with('success', $message);
    }

    public function destroy(Project $project)
    {
        $project->delete();

        return redirect()->route('projects.index')->with('success', 'ลบโครงการเรียบร้อยแล้ว');
    }

    /**
     * Manual entry in the project's Activity Log - the replacement for the
     * old single-value Remark field. Append-only: no update/destroy.
     */
    public function storeNote(Request $request, Project $project, CommentAttachmentUploader $uploader)
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:20480', 'mimes:'.CommentAttachmentUploader::ALLOWED_MIMES],
        ]);

        $log = ActivityLog::record($project->id, auth()->id(), $project, 'note', $data['note']);

        foreach ($request->file('attachments', []) as $file) {
            $uploader->store($log, $file, auth()->id());
        }
        app(NotificationService::class)->commented($project, $request->user(), $log);   // the people following the project hear it

        // Comment tab is client-side (Alpine) state, not reflected in the URL -
        // redirect straight back to it (rather than back()/Referer, which would
        // just land on the page's default tab) so posting a comment doesn't
        // visually bounce the user away from the Comment tab.
        return redirect()->route('projects.show', ['project' => $project, 'tab' => 'comment'])
            ->with('success', 'บันทึกรายการเรียบร้อยแล้ว');
    }

    // Lets a user tag a Project with its own color from My Department's Right
    // Detail Panel (overrides the auto-assigned Calendar color). Purely cosmetic,
    // so deliberately not in Project::activityLoggableFields() - a color swap
    // shouldn't clutter the Activity Log the way a status/priority change does.
    public function updateColor(Request $request, Project $project)
    {
        $data = $request->validate([
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $project->update(['color' => $data['color'] ?? null]);

        if ($request->wantsJson()) {
            return response()->json(['color' => $project->color]);
        }

        return back()->with('success', 'อัปเดตสีโครงการเรียบร้อยแล้ว');
    }

    /** What the new-project and edit forms offer. */
    private function formOptions(?Project $project = null): array
    {
        return [
            'users' => $this->managers($project),
            'statuses' => Project::$statuses,
            'priorities' => Project::$priorities,
            'jobTypes' => JobType::where('is_active', true)->orderBy('name')->get(),
            'boards' => Board::orderBy('sort')->orderBy('id')->get(),
            'salesPeople' => ProjectFollowers::salesPeople(),
            'salesChosen' => $project ? $project->followers()->wherePivot('source', ProjectFollowers::SALES)->pluck('users.id')->all() : [],
        ];
    }

    /** The people the form offers as Project Manager: Admin / PM, plus whoever already is this project's manager. */
    private function managers(?Project $project = null)
    {
        return User::where(fn ($q) => $q->whereIn('role', Project::MANAGER_ROLES)->where('is_active', true)
            ->when($project?->project_manager_id, fn ($q, $id) => $q->orWhere('id', $id)))->orderBy('name')->get();
    }
}
