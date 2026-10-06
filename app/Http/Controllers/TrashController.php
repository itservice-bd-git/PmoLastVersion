<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\Project;
use Illuminate\Http\Request;

/**
 * Recoverable deletes: a deleted Project/Cabinet (with everything under it) sits
 * here until a PMO-level user restores it. Nothing is purged from the UI.
 */
class TrashController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canManageTrash(), 403, 'เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager');

        return view('trash.index', [
            'projects' => Project::onlyTrashed()->withCount('cabinets')->latest('deleted_at')->get(),
            // A cabinet deleted together with its project comes back with the project -
            // only cabinets deleted on their own (project still live) are listed here.
            'cabinets' => Cabinet::onlyTrashed()->whereHas('project')->with('project')->latest('deleted_at')->get(),
        ]);
    }

    public function restoreProject(Request $request, int $id)
    {
        abort_unless($request->user()->canManageTrash(), 403);

        $project = Project::onlyTrashed()->findOrFail($id);

        // Numbers are only unique among live rows now - don't resurrect a clash.
        if (Project::where('project_no', $project->project_no)->exists()) {
            return back()->with('error', "กู้คืนไม่ได้: มีโครงการเลขที่ {$project->project_no} อยู่แล้ว กรุณาเปลี่ยนเลขของโครงการนั้นก่อน");
        }

        $project->restore();

        return redirect()->route('projects.show', $project)->with('success', 'กู้คืนโครงการเรียบร้อยแล้ว');
    }

    public function restoreCabinet(Request $request, int $id)
    {
        abort_unless($request->user()->canManageTrash(), 403);

        $cabinet = Cabinet::onlyTrashed()->whereHas('project')->findOrFail($id);

        if (Cabinet::where('mo_no', $cabinet->mo_no)->exists()) {
            return back()->with('error', "กู้คืนไม่ได้: มีตู้เลขที่ {$cabinet->mo_no} อยู่แล้ว กรุณาเปลี่ยนเลขของตู้นั้นก่อน");
        }

        $cabinet->restore();

        return redirect()->route('cabinets.show', $cabinet)->with('success', 'กู้คืนตู้ไฟฟ้าเรียบร้อยแล้ว');
    }
}
