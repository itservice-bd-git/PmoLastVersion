<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\Project;
use App\Models\ProjectDateChange;
use App\Services\ProjectDates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * หน้าโครงการ / หน้าตู้ › "เลื่อน / แก้วัน" (Admin / PM, route: pmo): a project's or a cabinet's dates move only here, with
 * a reason (ProjectDates).
 */
class ProjectDateController extends Controller
{
    public function store(Request $request, Project $project, ProjectDates $dates)
    {
        return $this->save($request, $project, $dates);
    }

    /** The project's "เลื่อน / แก้วัน" windows on their own, for Planning's project window to open (it saves through store()). */
    public function form(Project $project, ProjectDates $dates)
    {
        return view('projects.partials._dates', ['subject' => $project, 'dates' => $dates->summary($project), 'canDates' => true]);
    }

    public function storeCabinet(Request $request, Cabinet $cabinet, ProjectDates $dates)
    {
        return $this->save($request, $cabinet, $dates);
    }

    private function save(Request $request, Project|Cabinet $subject, ProjectDates $dates)
    {
        $postpone = $request->input('kind') === ProjectDateChange::POSTPONE;
        $data = $request->validate([
            'kind' => ['required', Rule::in([ProjectDateChange::POSTPONE, ProjectDateChange::CORRECT])],
            'due_date' => [$postpone ? 'required' : 'nullable', 'date'],
            'change_start' => ['nullable', 'boolean'],
            'start_date' => ['nullable', 'date'],
            'reason_id' => [$postpone ? 'required' : 'nullable', 'integer'],
            'note' => [$postpone ? 'required' : 'nullable', 'string', 'max:1000'],
            'notify' => ['nullable', 'boolean'],
        ], [
            'due_date.required' => 'ใส่วันครบกำหนดใหม่', 'reason_id.required' => 'เลือกสาเหตุการเลื่อน', 'note.required' => 'ใส่รายละเอียดว่าเลื่อนเพราะอะไร',
        ]);
        if (! $request->boolean('change_start')) {   // the start date stays unless "เปลี่ยนวันเริ่มด้วย" is ticked
            unset($data['start_date']);
        }
        $data['notify'] = $request->boolean('notify');

        $change = $dates->change($subject, $request->user(), $data);

        $message = $change->kind === ProjectDateChange::POSTPONE
            ? 'บันทึกการเลื่อนวันครั้งที่ '.$dates->summary($subject)['rounds'].' แล้ว'
            : 'แก้ไขวันที่แล้ว (ไม่นับเป็นการเลื่อน)';

        return $request->wantsJson() ? response()->json(['message' => $message]) : back()->with('success', $message);   // JSON: Planning
    }
}
