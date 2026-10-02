<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTemplate;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\CabinetCopyService;
use App\Services\CabinetTemplateService;
use App\Services\CommentAttachmentUploader;
use App\Services\SubtaskAssignmentService;
use Illuminate\Http\Request;

class CabinetController extends Controller
{
    public function index(Request $request)
    {
        $query = Cabinet::with('project');

        if ($status = $request->get('status')) {
            $query->where('status', $status);
        }

        if ($search = $request->get('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('mo_no', 'like', "%{$search}%")
                    ->orWhere('cabinet_name', 'like', "%{$search}%");
            });
        }

        // Nearest due date first (overdue cabinets surface at the top). Cabinets
        // without a due date follow, and completed ones sink to the bottom.
        $cabinets = $query
            ->orderByRaw('CASE WHEN status = ? THEN 1 ELSE 0 END', [Cabinet::STATUS_COMPLETED])
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('cabinets.index', [
            'cabinets' => $cabinets,
            'statuses' => Cabinet::$statuses,
            'filters' => $request->only(['q', 'status']),
        ]);
    }

    public function store(Request $request, Project $project, CabinetTemplateService $templateService)
    {
        $data = $request->validate([
            'mo_no' => ['required', 'string', 'max:100', 'unique:cabinets,mo_no'],
            'cabinet_name' => ['required', 'string', 'max:255'],
            'cabinet_type' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'expected_completion_date' => ['nullable', 'date', 'before_or_equal:due_date'],
            'cabinet_template_id' => ['nullable', 'exists:cabinet_templates,id'],
        ], [
            'expected_completion_date.before_or_equal' => 'วันคาดว่าจะเสร็จต้องไม่เกินวันครบกำหนด (Due Date)',
        ]);

        $templateId = $data['cabinet_template_id'] ?? CabinetTemplate::where('is_default', true)->value('id');
        unset($data['cabinet_template_id']);

        $data['sequence'] = $project->cabinets()->max('sequence') + 1;

        $cabinet = $project->cabinets()->create($data);

        if ($templateId) {
            $template = CabinetTemplate::with('taskTemplates.subtaskTemplates.checklistTemplates')->find($templateId);
            if ($template) {
                $templateService->applyToCabinet($cabinet, $template);
            }
        }

        return redirect()->route('cabinets.show', $cabinet)->with('success', 'สร้างตู้ไฟฟ้าเรียบร้อยแล้ว');
    }

    public function copy(Request $request, Cabinet $cabinet, CabinetCopyService $copyService)
    {
        $data = $request->validate([
            'mo_no' => ['required', 'string', 'max:100', 'unique:cabinets,mo_no'],
            'cabinet_name' => ['required', 'string', 'max:255'],
        ]);

        $project = $cabinet->project;

        $newCabinet = $project->cabinets()->create([
            'cabinet_template_id' => $cabinet->cabinet_template_id,
            'mo_no' => $data['mo_no'],
            'cabinet_name' => $data['cabinet_name'],
            'cabinet_type' => $cabinet->cabinet_type,
            'size' => $cabinet->size,
            'quantity' => $cabinet->quantity,
            'description' => $cabinet->description,
            'start_date' => $cabinet->start_date,
            'due_date' => $cabinet->due_date,
            'expected_completion_date' => $cabinet->expected_completion_date,
            'status' => 'not_started',
            'progress' => 0,
            'sequence' => $project->cabinets()->max('sequence') + 1,
        ]);

        $cabinet->loadMissing('tasks.subtasks.checklists');
        $copyService->copyFrom($cabinet, $newCabinet);

        return redirect()->route('projects.show', ['project' => $project, 'tab' => 'cabinets'])
            ->with('success', "คัดลอกตู้ไฟฟ้าเรียบร้อยแล้ว - ตู้ใหม่: {$newCabinet->mo_no}");
    }

    public function show(Request $request, Cabinet $cabinet)
    {
        $cabinet->load([
            'project',
            'tasks.subtasks.checklists.completedBy',
            'tasks.subtasks.department',
            'tasks.subtasks.owner',
            'tasks.subtasks.acceptedBy',
            'tasks.subtasks.startedBy',
            'tasks.subtasks.completedBy',
            'attachments.uploadedBy',
        ]);

        $cabinet->tasks->each(fn ($task) => $task->setRelation('cabinet', $cabinet));

        // Cabinet Quick Detail Panel (Project > ตู้ไฟฟ้า card click) - same route/
        // controller/eager-loading as the full Cabinet page, just a lean JSON
        // reply instead of the Blade view, and skipping the paginated Activity
        // Log/Comment queries below (the panel doesn't show either, so there's
        // no reason to run them on every card click - "เปิดหน้าตู้แบบเต็ม" still
        // goes to this same URL without the Accept header, loading them as usual).
        if ($request->wantsJson()) {
            return $this->jsonDetail($cabinet, $request->user());
        }

        // Activity Log: system-generated events only - manual Comments (action=note)
        // moved to their own tab/query below.
        $activityLogs = ActivityLog::where('loggable_type', Cabinet::class)
            ->where('loggable_id', $cabinet->id)
            ->where('action', '!=', 'note')
            ->with('user')
            ->latest()
            ->paginate(30, pageName: 'activity_page');

        $comments = ActivityLog::where('loggable_type', Cabinet::class)
            ->where('loggable_id', $cabinet->id)
            ->where('action', 'note')
            ->with(['user', 'attachments'])
            ->latest()
            ->paginate(30, pageName: 'comment_page');

        return view('cabinets.show', [
            'cabinet' => $cabinet,
            'subtaskStatuses' => \App\Models\CabinetSubtask::$statuses,
            'departments' => \App\Models\Department::orderBy('name')->get(),
            'users' => \App\Models\User::orderBy('name')->get(),
            'activityLogs' => $activityLogs,
            'comments' => $comments,
        ]);
    }

    /**
     * Lean JSON for the Cabinet Quick Detail Panel - same eager-loaded
     * $cabinet as the full page above, reshaped for Alpine instead of Blade.
     * Reuses SubtaskAssignmentService::payload() for every assignment-related
     * field (department/lock/accept-start-complete flags) rather than
     * recomputing that mapping a third time - the Cabinet full page, the
     * Dispatch page, and this panel must never disagree about one Sub Task's
     * assignment state.
     */
    private function jsonDetail(Cabinet $cabinet, ?User $user)
    {
        $assignments = app(SubtaskAssignmentService::class);
        $departments = Department::orderBy('name')->get(['id', 'name']);

        return response()->json([
            'cabinet' => [
                'id' => $cabinet->id,
                'mo_no' => $cabinet->mo_no,
                'cabinet_name' => $cabinet->cabinet_name,
                'status' => $cabinet->status,
                'status_label' => Cabinet::$statuses[$cabinet->status] ?? $cabinet->status,
                'progress' => $cabinet->progress,
                'due_date' => $cabinet->due_date?->format('Y-m-d'),
                'is_overdue' => $cabinet->is_overdue,
                'days_remaining' => $cabinet->days_remaining,
                'checklist_counts' => $cabinet->checklist_counts,
                'show_url' => route('cabinets.show', $cabinet),
                'attachments' => $cabinet->attachments->map(fn ($a) => [
                    'id' => $a->id,
                    'original_name' => $a->original_name,
                    'description' => $a->description,
                    'size_for_humans' => $a->size_for_humans,
                    'url' => $a->url,
                    'uploaded_by_name' => $a->uploadedBy?->name,
                    'created_at' => $a->created_at->format('d/m/Y H:i'),
                    'destroy_url' => route('cabinet-attachments.destroy', $a),
                ]),
                'attachments_upload_url' => route('cabinet-attachments.store', $cabinet),
            ],
            'tasks' => $cabinet->tasks->map(function ($task) use ($assignments, $user, $departments) {
                return [
                    'id' => $task->id,
                    'name' => $task->name,
                    'progress' => $task->progress,
                    'subtasks' => $task->subtasks->map(function ($subtask) use ($assignments, $user, $departments) {
                        $daysRemaining = $subtask->days_remaining;

                        return array_merge([
                            'id' => $subtask->id,
                            'name' => $subtask->name,
                            'status' => $subtask->status,
                            'owner_id' => $subtask->owner_id,
                            'owner_name' => $subtask->owner?->name,
                            'start_date' => $subtask->start_date?->format('Y-m-d'),
                            'due_date' => $subtask->due_date?->format('Y-m-d'),
                            'is_overdue' => $subtask->is_overdue,
                            'is_near_due' => $daysRemaining !== null && $daysRemaining >= 0 && $daysRemaining <= 3
                                && $subtask->status !== CabinetSubtask::STATUS_COMPLETED,
                            'days_remaining' => $daysRemaining,
                            'remark' => $subtask->remark,
                            // Single source of truth (CabinetSubtask::isChecklistEditableBy) -
                            // same rule My Department's detail modal already shows/enforces:
                            // only the assigned department, once ACCEPTED/IN_PROGRESS (not
                            // while still just ASSIGNED/unaccepted), or Admin/PM. The toggle
                            // endpoint itself re-checks this server-side regardless - this
                            // flag only lets the checkbox render disabled instead of
                            // erroring on click.
                            'checklist_editable' => $subtask->isChecklistEditableBy($user),
                            'checklists' => $subtask->checklists->map(fn ($c) => [
                                'id' => $c->id,
                                'name' => $c->name,
                                'is_completed' => $c->is_completed,
                                'completed_by_name' => $c->completedBy?->name,
                                'completed_at_formatted' => $c->completed_at?->format('d/m/Y H:i'),
                                'toggle_url' => route('checklists.toggle', $c),
                            ]),
                            'checklists_total' => $subtask->checklists->count(),
                            'checklists_completed' => $subtask->checklists->where('is_completed', true)->count(),
                            'urls' => [
                                'accept' => route('cabinet-subtasks.accept', $subtask),
                                'start' => route('cabinet-subtasks.start', $subtask),
                                'complete' => route('cabinet-subtasks.complete', $subtask),
                                'update' => route('cabinet-subtasks.update', $subtask),
                            ],
                            // Server-rendered, exactly what the Cabinet full page/Dispatch
                            // page already render for this Sub Task (badge, department
                            // select/lock, accept/start/complete buttons) - shown inside
                            // this row's expanded detail view via x-html, so the quick
                            // panel never reimplements that markup/logic a third time.
                            // _assignment-scripts.blade.php's delegated `document` listeners
                            // (included once on the Project page) already handle whatever
                            // this HTML renders, including into dynamically-injected markup.
                            'assignment_html' => view('cabinets.partials._assignment', [
                                'subtask' => $subtask,
                                'departments' => $departments,
                            ])->render(),
                        ], $assignments->payload($subtask, $user));
                    }),
                ];
            }),
            'departments' => $departments,
            'can_dispatch_work' => (bool) $user?->canDispatchWork(),
        ]);
    }

    public function edit(Cabinet $cabinet)
    {
        return view('cabinets.edit', [
            'cabinet' => $cabinet,
        ]);
    }

    public function update(Request $request, Cabinet $cabinet)
    {
        $data = $request->validate([
            'mo_no' => ['required', 'string', 'max:100', 'unique:cabinets,mo_no,'.$cabinet->id],
            'cabinet_name' => ['required', 'string', 'max:255'],
            'cabinet_type' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'expected_completion_date' => ['nullable', 'date', 'before_or_equal:due_date'],
        ], [
            'expected_completion_date.before_or_equal' => 'วันคาดว่าจะเสร็จต้องไม่เกินวันครบกำหนด (Due Date)',
        ]);

        $cabinet->update($data);

        return redirect()->route('cabinets.show', $cabinet)->with('success', 'บันทึกข้อมูลตู้ไฟฟ้าเรียบร้อยแล้ว');
    }

    public function destroy(Cabinet $cabinet)
    {
        $project = $cabinet->project;
        $cabinet->delete();

        return redirect()->route('projects.show', $project)->with('success', 'ลบตู้ไฟฟ้าเรียบร้อยแล้ว');
    }

    /**
     * Manual entry in the cabinet's Activity Log - the replacement for the
     * old single-value Remark field. Append-only: no update/destroy.
     */
    public function storeNote(Request $request, Cabinet $cabinet, CommentAttachmentUploader $uploader)
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:20480', 'mimes:'.CommentAttachmentUploader::ALLOWED_MIMES],
        ]);

        $log = ActivityLog::record($cabinet->project_id, auth()->id(), $cabinet, 'note', $data['note']);

        foreach ($request->file('attachments', []) as $file) {
            $uploader->store($log, $file, auth()->id());
        }

        // Comment tab is client-side (Alpine) state, not reflected in the URL -
        // redirect straight back to it (rather than back()/Referer, which would
        // just land on the page's default tab) so posting a comment doesn't
        // visually bounce the user away from the Comment tab.
        return redirect()->route('cabinets.show', ['cabinet' => $cabinet, 'tab' => 'comment'])
            ->with('success', 'บันทึกรายการเรียบร้อยแล้ว');
    }
}
