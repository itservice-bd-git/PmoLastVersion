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

    /** "ยกเลิกการรอ": the old dates stand after all (the project's and its waiting rounds'). */
    public function pending(Request $request, Project $project, ProjectDates $dates)
    {
        $request->validate(['action' => ['required', Rule::in(['cancel'])]]);
        $dates->cancelPending($project, $request->user());
        $message = 'ยกเลิกการรอแล้ว · ใช้วันเดิม · รอบส่งที่ดึงกลับยังเป็นร่าง ส่งให้แผนกจัดส่งอีกครั้งที่ "การจัดส่ง"';

        return $request->wantsJson() ? response()->json(['message' => $message]) : back()->with('success', $message);
    }

    private function save(Request $request, Project|Cabinet $subject, ProjectDates $dates)
    {
        $kind = $request->input('kind');
        $postpone = $kind === ProjectDateChange::POSTPONE;
        $pending = $kind === ProjectDateChange::PENDING;
        $data = $request->validate([
            // รอฝ่ายขายแจ้งกำหนดส่งอีกครั้ง: a project's own dates only
            'kind' => ['required', Rule::in($subject instanceof Project ? [ProjectDateChange::POSTPONE, ProjectDateChange::PENDING, ProjectDateChange::CORRECT] : [ProjectDateChange::POSTPONE, ProjectDateChange::CORRECT])],
            'due_date' => [$postpone ? 'required' : 'nullable', 'date'],
            'change_start' => ['nullable', 'boolean'],
            'start_date' => ['nullable', 'date'],
            'reason_id' => [$postpone || $pending ? 'required' : 'nullable', 'integer'],
            'note' => [$postpone || $pending ? 'required' : 'nullable', 'string', 'max:1000'],
            'notify' => ['nullable', 'boolean'],
            // the FAT / delivery rounds that go along (ProjectDates::linkedEvents)
            'events' => ['nullable', 'array', 'max:50'],
            'events.*.type' => ['required', Rule::in(['fat', 'delivery'])],
            'events.*.id' => ['required', 'integer'],
            'events.*.action' => ['required', Rule::in(['keep', 'shift', 'date', 'pending'])],
            'events.*.date' => ['nullable', 'date'],
        ], [
            'due_date.required' => 'ใส่วันครบกำหนดใหม่', 'reason_id.required' => 'เลือกสาเหตุ', 'note.required' => 'ใส่รายละเอียด',
        ]);
        if (! $request->boolean('change_start') || $pending) {   // the start date stays unless "เปลี่ยนวันเริ่มด้วย" is ticked
            unset($data['start_date']);
        }
        $data['notify'] = $request->boolean('notify');

        $change = $dates->change($subject, $request->user(), $data);

        $message = match ($change->kind) {
            ProjectDateChange::POSTPONE => 'บันทึกการเลื่อนวันครั้งที่ '.$dates->summary($subject)['rounds'].' แล้ว',
            ProjectDateChange::PENDING => 'บันทึกแล้ว · '.Project::PENDING_LABEL.'อีกครั้ง',
            default => 'แก้ไขวันที่แล้ว (ไม่นับเป็นการเลื่อน)',
        };

        return $request->wantsJson() ? response()->json(['message' => $message]) : back()->with('success', $message);   // JSON: Planning
    }
}
