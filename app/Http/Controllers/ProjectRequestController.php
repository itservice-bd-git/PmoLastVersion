<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inbox for the public request form. Admin/PM only (the same PMO-level roles that dispatch work).
 */
class ProjectRequestController extends Controller
{
    private function authorizePmo(Request $request): void
    {
        abort_unless($request->user()->canDispatchWork(), 403, 'เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้น');
    }

    public function index(Request $request)
    {
        $this->authorizePmo($request);

        return view('project-requests.index', [
            'pending' => ProjectRequest::where('status', ProjectRequest::STATUS_PENDING)->oldest()->get(),
            'handled' => ProjectRequest::where('status', '!=', ProjectRequest::STATUS_PENDING)->with('project', 'handler')->latest('handled_at')->limit(30)->get(),
            'suggestedNo' => ProjectRequest::suggestedProjectNo(),
        ]);
    }

    /** Turn a pending request into a Draft Project. */
    public function import(Request $request, ProjectRequest $projectRequest)
    {
        $this->authorizePmo($request);

        $data = $request->validate([
            'project_no' => ['required', 'string', 'max:50', 'unique:projects,project_no,NULL,id,deleted_at,NULL'],
        ]);

        $project = DB::transaction(function () use ($projectRequest, $data, $request) {
            // Re-read under a lock so two admins clicking "นำเข้า" at once cannot create two Projects.
            $locked = ProjectRequest::whereKey($projectRequest->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['project_no' => 'คำขอนี้ถูกดำเนินการไปแล้ว']);
            }

            $project = Project::create([
                'project_no' => $data['project_no'],
                'project_name' => $locked->title,
                'customer_name' => $locked->customer_name,
                'description' => $locked->description,
                'due_date' => $locked->needed_by,
                'remark' => 'นำเข้าจากคำขอออนไลน์ · ผู้ขอ: '.$locked->requester_name.' ('.$locked->contact.')'
                    .($locked->quantity ? ' · จำนวนที่ขอ '.$locked->quantity.' ตู้' : ''),
                'status' => Project::STATUS_DRAFT,
                'priority' => Project::PRIORITY_NORMAL,
            ]);

            $locked->update([
                'status' => ProjectRequest::STATUS_IMPORTED,
                'project_id' => $project->id,
                'handled_by' => $request->user()->id,
                'handled_at' => now(),
            ]);

            return $project;
        });

        return redirect()->route('projects.show', $project)->with('success', 'นำเข้าคำขอเป็นโครงการ (Draft) เรียบร้อยแล้ว');
    }

    public function reject(Request $request, ProjectRequest $projectRequest)
    {
        $this->authorizePmo($request);

        $data = $request->validate(['reject_reason' => ['required', 'string', 'max:500']]);

        $updated = ProjectRequest::whereKey($projectRequest->id)->where('status', ProjectRequest::STATUS_PENDING)->update([
            'status' => ProjectRequest::STATUS_REJECTED,
            'reject_reason' => $data['reject_reason'],
            'handled_by' => $request->user()->id,
            'handled_at' => now(),
        ]);

        return back()->with($updated ? 'success' : 'error', $updated ? 'ปฏิเสธคำขอเรียบร้อยแล้ว' : 'คำขอนี้ถูกดำเนินการไปแล้ว');
    }
}
