<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Project;
use App\Services\ProgressService;

/**
 * Recoverable deletes: a deleted Project/Cabinet/Task/Sub Task (with everything under it) sits
 * here until a PMO-level user restores it. Nothing is purged from the UI.
 */
class TrashController extends Controller
{
    public function index()
    {
        return view('trash.index', [
            'projects' => Project::onlyTrashed()->withCount('cabinets')->latest('deleted_at')->get(),
            // A cabinet deleted together with its project comes back with the project -
            // only cabinets deleted on their own (project still live) are listed here.
            'cabinets' => Cabinet::onlyTrashed()->whereHas('project')->with('project')->latest('deleted_at')->get(),
            // likewise: a Task / Sub Task deleted on its own from the cabinet page (its cabinet / Task still live)
            'tasks' => CabinetTask::onlyTrashed()->whereHas('cabinet.project')->with('cabinet.project')->withCount(['subtasks' => fn ($q) => $q->onlyTrashed()])->latest('deleted_at')->get(),
            'subtasks' => CabinetSubtask::onlyTrashed()->whereHas('cabinetTask.cabinet.project')->with('cabinetTask.cabinet.project', 'department:id,name')->latest('deleted_at')->get(),
        ]);
    }

    public function restoreProject(int $id)
    {
        $project = Project::onlyTrashed()->findOrFail($id);

        // Numbers are only unique among live rows now - don't resurrect a clash.
        if (Project::where('project_no', $project->project_no)->exists()) {
            return back()->with('error', "กู้คืนไม่ได้: มีโครงการเลขที่ {$project->project_no} อยู่แล้ว กรุณาเปลี่ยนเลขของโครงการนั้นก่อน");
        }

        $project->restore();

        return redirect()->route('projects.show', $project)->with('success', 'กู้คืนโครงการเรียบร้อยแล้ว');
    }

    public function restoreCabinet(int $id)
    {
        $cabinet = Cabinet::onlyTrashed()->whereHas('project')->findOrFail($id);

        if (Cabinet::where('mo_no', $cabinet->mo_no)->exists()) {
            return back()->with('error', "กู้คืนไม่ได้: มีตู้เลขที่ {$cabinet->mo_no} อยู่แล้ว กรุณาเปลี่ยนเลขของตู้นั้นก่อน");
        }

        $cabinet->restore();

        return redirect()->route('cabinets.show', $cabinet)->with('success', 'กู้คืนตู้ไฟฟ้าเรียบร้อยแล้ว');
    }

    public function restoreTask(int $id, ProgressService $progress)
    {
        $task = CabinetTask::onlyTrashed()->whereHas('cabinet')->findOrFail($id);
        $task->restore();   // brings back the Sub Tasks / Checklists that went with it
        $progress->recalculateCabinet($task->cabinet);

        return redirect()->route('cabinets.show', $task->cabinet_id)->with('success', "กู้คืน Task \"{$task->name}\" เรียบร้อยแล้ว");
    }

    public function restoreSubtask(int $id, ProgressService $progress)
    {
        $subtask = CabinetSubtask::onlyTrashed()->whereHas('cabinetTask')->findOrFail($id);
        $subtask->restore();
        $progress->recalculateTask($subtask->cabinetTask);

        return redirect()->route('cabinets.show', $subtask->cabinetTask->cabinet_id)->with('success', "กู้คืน Sub Task \"{$subtask->name}\" เรียบร้อยแล้ว");
    }
}
