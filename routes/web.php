<?php

use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\CabinetChecklistController;
use App\Http\Controllers\CabinetAttachmentController;
use App\Http\Controllers\CabinetController;
use App\Http\Controllers\CabinetSubtaskController;
use App\Http\Controllers\CabinetTaskController;
use App\Http\Controllers\CabinetTemplateController;
use App\Http\Controllers\CabinetTemplateItemController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MyDepartmentController;
use App\Http\Controllers\PlanningController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectAttachmentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\TrashController;
use App\Http\Controllers\ProjectRequestController;
use App\Http\Controllers\ProjectTaskController;
use App\Http\Controllers\PublicRequestController;
use App\Http\Controllers\StatusReportController;
use App\Http\Controllers\Settings\AutomationController;
use App\Http\Controllers\Settings\DepartmentController;
use App\Http\Controllers\Settings\JobTypeController;
use App\Http\Controllers\Settings\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Public "ยื่นขอโครงการ" form - the only unauthenticated page besides login. POST is throttled per IP.
Route::get('request', [PublicRequestController::class, 'create'])->name('requests.create');
Route::post('request', [PublicRequestController::class, 'store'])->middleware('throttle:5,10')->name('requests.store');
Route::get('request/thanks', [PublicRequestController::class, 'thanks'])->name('requests.thanks');

// Real per-user login is required for everything below.
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('projects/export', [ProjectController::class, 'export'])->name('projects.export');
    Route::get('projects/{project}/activity-export', [ActivityLogController::class, 'projectExport'])->name('projects.activity-export');
    Route::get('cabinets/{cabinet}/activity-export', [ActivityLogController::class, 'cabinetExport'])->name('cabinets.activity-export');
    Route::get('projects/{project}/activity-tree', [ActivityLogController::class, 'projectTree'])->name('projects.activity-tree');
    Route::get('cabinets/{cabinet}/activity-tree', [ActivityLogController::class, 'cabinetTree'])->name('cabinets.activity-tree');
    Route::put('activity-logs/{activityLog}', [ActivityLogController::class, 'update'])->name('activity-logs.update');

    // "งานแผนกของฉัน" and Planning are ONE page now: the Planning board. The old URL (bookmarks, old notices) lands there.
    Route::get('my-department', fn (\Illuminate\Http\Request $r) => redirect()->route('planning.board', $r->only('project')))->name('my-department.index');
    // Trial: the original Avatar Planning page running on PMO data (read-only) - see PlanningController.
    Route::middleware('gzip')->group(function () {
        Route::get('planning', fn (\Illuminate\Http\Request $r) => redirect()->route('planning.board', $r->only('project')))->name('planning.index');
        // the original single-file page, kept only as a hidden fallback (not linked anywhere)
        Route::get('planning/classic', [PlanningController::class, 'index'])->name('planning.spa');
        Route::get('planning/board', [PlanningController::class, 'board'])->name('planning.board');
        Route::get('planning/data', [PlanningController::class, 'data'])->name('planning.data');
        Route::post('planning/comment', [PlanningController::class, 'comment'])->middleware('throttle:60,1')->name('planning.comment');
        Route::post('planning/sync', [PlanningController::class, 'sync'])->middleware('throttle:240,1')->name('planning.sync');
    });
    Route::get('my-department/tasks', [MyDepartmentController::class, 'tasks'])->name('my-department.tasks');
    Route::get('my-department/projects/{project}', [MyDepartmentController::class, 'project'])->name('my-department.project');
    Route::patch('my-department/projects/{project}', [MyDepartmentController::class, 'updateProject'])->name('my-department.project.update');
    Route::post('my-department/projects/{project}/notes', [MyDepartmentController::class, 'storeProjectNote'])->name('my-department.project.note');
    Route::get('my-department/dashboard', [MyDepartmentController::class, 'dashboard'])->name('my-department.dashboard');
    Route::get('my-department/export', [MyDepartmentController::class, 'export'])->name('my-department.export');
    Route::get('my-department/subtasks/{cabinetSubtask}', [MyDepartmentController::class, 'show'])->name('my-department.show');
    Route::post('my-department/subtasks/{cabinetSubtask}/star', [MyDepartmentController::class, 'toggleStar'])->name('my-department.star');

    Route::get('status-report', [StatusReportController::class, 'show'])->name('status-report.show');
    Route::post('status-report/acknowledge', [StatusReportController::class, 'acknowledge'])->name('status-report.acknowledge');

    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->name('notifications.read');

    Route::get('trash', [TrashController::class, 'index'])->name('trash.index');
    Route::post('trash/projects/{id}/restore', [TrashController::class, 'restoreProject'])->name('trash.projects.restore');
    Route::post('trash/cabinets/{id}/restore', [TrashController::class, 'restoreCabinet'])->name('trash.cabinets.restore');

    Route::get('assignments', [AssignmentController::class, 'index'])->name('assignments.index');

    // Inbox for the public request form (admin/PM, checked in the controller).
    Route::get('project-requests', [ProjectRequestController::class, 'index'])->name('project-requests.index');
    Route::post('project-requests/{projectRequest}/import', [ProjectRequestController::class, 'import'])->name('project-requests.import');
    Route::post('project-requests/{projectRequest}/reject', [ProjectRequestController::class, 'reject'])->name('project-requests.reject');

    Route::resource('projects', ProjectController::class);

    Route::post('projects/{project}/attachments', [ProjectAttachmentController::class, 'store'])->name('project-attachments.store');
    Route::delete('project-attachments/{attachment}', [ProjectAttachmentController::class, 'destroy'])->name('project-attachments.destroy');
    Route::post('projects/{project}/notes', [ProjectController::class, 'storeNote'])->name('projects.notes.store');
    Route::patch('projects/{project}/color', [ProjectController::class, 'updateColor'])->name('projects.color');

    Route::post('projects/{project}/tasks', [ProjectTaskController::class, 'store'])->name('project-tasks.store');
    Route::put('project-tasks/{projectTask}', [ProjectTaskController::class, 'update'])->name('project-tasks.update');
    Route::delete('project-tasks/{projectTask}', [ProjectTaskController::class, 'destroy'])->name('project-tasks.destroy');

    Route::post('projects/{project}/cabinets', [CabinetController::class, 'store'])->name('cabinets.store');
    Route::post('cabinets/{cabinet}/copy', [CabinetController::class, 'copy'])->name('cabinets.copy');
    Route::get('cabinets/{cabinet}', [CabinetController::class, 'show'])->name('cabinets.show');
    Route::get('cabinets/{cabinet}/edit', [CabinetController::class, 'edit'])->name('cabinets.edit');
    Route::put('cabinets/{cabinet}', [CabinetController::class, 'update'])->name('cabinets.update');
    Route::delete('cabinets/{cabinet}', [CabinetController::class, 'destroy'])->name('cabinets.destroy');
    Route::get('cabinets', [CabinetController::class, 'index'])->name('cabinets.index');

    Route::post('cabinets/{cabinet}/attachments', [CabinetAttachmentController::class, 'store'])->name('cabinet-attachments.store');
    Route::delete('cabinet-attachments/{attachment}', [CabinetAttachmentController::class, 'destroy'])->name('cabinet-attachments.destroy');
    Route::post('cabinets/{cabinet}/notes', [CabinetController::class, 'storeNote'])->name('cabinets.notes.store');

    Route::put('cabinet-tasks/{cabinetTask}', [CabinetTaskController::class, 'update'])->name('cabinet-tasks.update');
    Route::put('cabinet-subtasks/{cabinetSubtask}', [CabinetSubtaskController::class, 'update'])->name('cabinet-subtasks.update');
    Route::patch('cabinet-subtasks/{cabinetSubtask}/department', [CabinetSubtaskController::class, 'updateDepartment'])->name('cabinet-subtasks.department');
    Route::post('cabinet-subtasks/{cabinetSubtask}/accept', [CabinetSubtaskController::class, 'accept'])->name('cabinet-subtasks.accept');
    Route::post('cabinet-subtasks/{cabinetSubtask}/start', [CabinetSubtaskController::class, 'start'])->name('cabinet-subtasks.start');
    Route::post('cabinet-subtasks/{cabinetSubtask}/complete', [CabinetSubtaskController::class, 'complete'])->name('cabinet-subtasks.complete');

    Route::post('cabinet-subtasks/{cabinetSubtask}/checklists', [CabinetChecklistController::class, 'store'])->name('checklists.store');
    Route::post('cabinet-subtasks/{cabinetSubtask}/copy-checklists', [CabinetChecklistController::class, 'copyChecklists'])->name('checklists.copy');
    Route::patch('checklists/{checklist}/toggle', [CabinetChecklistController::class, 'toggle'])->name('checklists.toggle');
    Route::patch('checklists/{checklist}/remark', [CabinetChecklistController::class, 'updateRemark'])->name('checklists.update-remark');
    Route::delete('checklists/{checklist}', [CabinetChecklistController::class, 'destroy'])->name('checklists.destroy');

    Route::resource('cabinet-templates', CabinetTemplateController::class);
    Route::post('cabinet-templates/{cabinetTemplate}/task-templates', [CabinetTemplateItemController::class, 'storeTask'])->name('task-templates.store');
    Route::patch('task-templates/reorder', [CabinetTemplateItemController::class, 'reorderTasks'])->name('task-templates.reorder');
    Route::put('task-templates/{taskTemplate}', [CabinetTemplateItemController::class, 'updateTask'])->name('task-templates.update');
    Route::delete('task-templates/{taskTemplate}', [CabinetTemplateItemController::class, 'destroyTask'])->name('task-templates.destroy');
    Route::post('task-templates/{taskTemplate}/subtask-templates', [CabinetTemplateItemController::class, 'storeSubtask'])->name('subtask-templates.store');
    Route::patch('subtask-templates/reorder', [CabinetTemplateItemController::class, 'reorderSubtasks'])->name('subtask-templates.reorder');
    Route::put('subtask-templates/{subtaskTemplate}', [CabinetTemplateItemController::class, 'updateSubtask'])->name('subtask-templates.update');
    Route::delete('subtask-templates/{subtaskTemplate}', [CabinetTemplateItemController::class, 'destroySubtask'])->name('subtask-templates.destroy');
    Route::post('subtask-templates/{subtaskTemplate}/checklist-templates', [CabinetTemplateItemController::class, 'storeChecklist'])->name('checklist-templates.store');
    Route::patch('checklist-templates/reorder', [CabinetTemplateItemController::class, 'reorderChecklists'])->name('checklist-templates.reorder');
    Route::put('checklist-templates/{checklistTemplate}', [CabinetTemplateItemController::class, 'updateChecklist'])->name('checklist-templates.update');
    Route::delete('checklist-templates/{checklistTemplate}', [CabinetTemplateItemController::class, 'destroyChecklist'])->name('checklist-templates.destroy');

    // Settings hub: every tab is admin-only except "การแจ้งเตือน" (your own).
    Route::get('settings', [\App\Http\Controllers\Settings\SettingsController::class, 'index'])->name('settings.index');
    Route::get('settings/notifications', [\App\Http\Controllers\Settings\SettingsController::class, 'editNotifications'])->name('settings.notifications.edit');
    Route::put('settings/notifications', [\App\Http\Controllers\Settings\SettingsController::class, 'updateNotifications'])->name('settings.notifications.update');
    Route::get('settings/rules', [\App\Http\Controllers\Settings\SettingsController::class, 'editRules'])->middleware('admin')->name('settings.rules.edit');
    Route::put('settings/rules', [\App\Http\Controllers\Settings\SettingsController::class, 'updateRules'])->middleware('admin')->name('settings.rules.update');

    Route::middleware('admin')->prefix('settings')->group(function () {
        Route::get('statuses', [\App\Http\Controllers\Settings\StatusController::class, 'index'])->name('settings.statuses.edit');
        Route::put('statuses', [\App\Http\Controllers\Settings\StatusController::class, 'update'])->name('settings.statuses.update');
        Route::post('statuses', [\App\Http\Controllers\Settings\StatusController::class, 'store'])->name('settings.statuses.store');
        Route::delete('statuses/{status}', [\App\Http\Controllers\Settings\StatusController::class, 'destroy'])->name('settings.statuses.destroy');
        Route::patch('statuses/{status}/move', [\App\Http\Controllers\Settings\StatusController::class, 'move'])->name('settings.statuses.move');
        $boards = \App\Http\Controllers\Settings\BoardController::class;
        Route::get('boards', [$boards, 'boards'])->name('settings.boards.index');
        Route::post('boards', [$boards, 'storeBoard'])->name('settings.boards.store');
        Route::put('boards/{board}', [$boards, 'updateBoard'])->name('settings.boards.update');
        Route::delete('boards/{board}', [$boards, 'destroyBoard'])->name('settings.boards.destroy');
        Route::get('labels', [$boards, 'labels'])->name('settings.labels.index');
        Route::post('labels', [$boards, 'storeLabel'])->name('settings.labels.store');
        Route::put('labels/{label}', [$boards, 'updateLabel'])->name('settings.labels.update');
        Route::delete('labels/{label}', [$boards, 'destroyLabel'])->name('settings.labels.destroy');
        Route::get('fields', [\App\Http\Controllers\Settings\FieldController::class, 'index'])->name('settings.fields.index');
        Route::post('fields', [\App\Http\Controllers\Settings\FieldController::class, 'storeField'])->name('settings.fields.store');
        Route::delete('fields/{field}', [\App\Http\Controllers\Settings\FieldController::class, 'destroyField'])->name('settings.fields.destroy');
        Route::post('fields/{field}/options', [\App\Http\Controllers\Settings\FieldController::class, 'storeOption'])->name('settings.fields.options.store');
        Route::delete('field-options/{option}', [\App\Http\Controllers\Settings\FieldController::class, 'destroyOption'])->name('settings.field-options.destroy');
    });

    // Settings are admin-only: user management in particular can mint admin accounts and reset passwords.
    Route::resource('settings/users', UserController::class)->parameters(['users' => 'user'])->names('settings.users')->except('show')->middleware('admin');
    Route::resource('settings/departments', DepartmentController::class)->parameters(['departments' => 'department'])->names('settings.departments')->only(['index', 'store', 'update', 'destroy'])->middleware('admin');
    // Admin-only (checked inside the controller): workflow automation + date-limit rules.
    Route::get('settings/automation', [AutomationController::class, 'index'])->name('settings.automation.index');
    Route::post('settings/automation/rules', [AutomationController::class, 'storeRule'])->name('settings.automation.rules.store');
    Route::patch('settings/automation/rules/{rule}', [AutomationController::class, 'toggleRule'])->name('settings.automation.rules.toggle');
    Route::delete('settings/automation/rules/{rule}', [AutomationController::class, 'destroyRule'])->name('settings.automation.rules.destroy');
    Route::post('settings/automation/date-limits', [AutomationController::class, 'storeLimit'])->name('settings.automation.limits.store');
    Route::patch('settings/automation/date-limits/{limit}', [AutomationController::class, 'toggleLimit'])->name('settings.automation.limits.toggle');
    Route::delete('settings/automation/date-limits/{limit}', [AutomationController::class, 'destroyLimit'])->name('settings.automation.limits.destroy');

    Route::resource('settings/job-types', JobTypeController::class)->parameters(['job-types' => 'jobType'])->names('settings.job-types')->only(['index', 'store', 'update', 'destroy'])->middleware('admin');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
