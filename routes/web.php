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
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectAttachmentController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectTaskController;
use App\Http\Controllers\Settings\DepartmentController;
use App\Http\Controllers\Settings\JobTypeController;
use App\Http\Controllers\Settings\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

// Real per-user login is required from here on (was auto-login while the
// system was demo/single-user only). See AutoLoginDemoUser if it's ever
// needed again for local/demo use - swap the alias below back to it.
Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::put('activity-logs/{activityLog}', [ActivityLogController::class, 'update'])->name('activity-logs.update');

    Route::get('my-department', [MyDepartmentController::class, 'index'])->name('my-department.index');
    Route::get('my-department/tasks', [MyDepartmentController::class, 'tasks'])->name('my-department.tasks');
    Route::get('my-department/subtasks/{cabinetSubtask}', [MyDepartmentController::class, 'show'])->name('my-department.show');

    Route::get('assignments', [AssignmentController::class, 'index'])->name('assignments.index');

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

    Route::resource('settings/users', UserController::class)->parameters(['users' => 'user'])->names('settings.users');
    Route::resource('settings/departments', DepartmentController::class)->parameters(['departments' => 'department'])->names('settings.departments');
    Route::resource('settings/job-types', JobTypeController::class)->parameters(['job-types' => 'jobType'])->names('settings.job-types');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
t