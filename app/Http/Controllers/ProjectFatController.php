<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\ProjectFat;
use App\Services\EventDates;
use App\Services\PlanningBoard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * วัน FAT (the customer's acceptance test at the factory) on the project page: Admin / PM set it; a failed FAT stays in the
 * history and the next appointment is a new round. Planning shows the latest round (PlanningBoard).
 */
class ProjectFatController extends Controller
{
    public function __construct(private EventDates $dates) {}

    /** The FAT window on its own, for Planning's project window to open (it saves through store() / update() as JSON). */
    public function form(Request $request, Project $project)
    {
        $this->authorizeEdit($request);
        $fat = $project->latestFat;

        // as the project page: edit the latest round, or book a new one when there is none or it failed
        return view('projects.partials._fat-window', ['project' => $project, 'f' => ProjectFat::formData($project, $fat?->status === 'failed' ? null : $fat, $fat)]);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorizeEdit($request);
        $round = (int) $project->fats()->max('round') + 1;
        $fat = $project->fats()->create($this->validated($request, $project) + ['round' => $round, 'created_by' => $request->user()->id]);

        $this->log($request, $project, ($round > 1 ? "นัด FAT รอบที่ {$round}" : 'ลงวัน FAT').': '.$fat->when());

        return $this->done($request, 'บันทึกวัน FAT แล้ว');
    }

    public function update(Request $request, ProjectFat $fat)
    {
        $this->authorizeEdit($request);
        $before = ['when' => $fat->when(), 'status' => $fat->statusLabel(), 'days' => EventDates::snapshot($fat)];
        $move = $request->validate(EventDates::rules());
        $fat->update($this->validated($request, $fat->project) + ['date_pending_at' => null]);   // saved with its day: no longer waiting
        $moved = $this->dates->record($fat, $before['days'], $request->user(), $move);   // ประวัติการเลื่อน

        $changes = array_filter([
            $before['when'] !== $fat->when() ? "เลื่อนจาก {$before['when']} เป็น {$fat->when()}".EventDates::why($moved) : null,
            $before['status'] !== $fat->statusLabel() ? "สถานะ {$before['status']} → {$fat->statusLabel()}" : null,
        ]);
        $this->log($request, $fat->project, "วัน FAT รอบที่ {$fat->round}: ".($changes ? implode(' · ', $changes) : 'แก้รายละเอียด'));

        return $this->done($request, 'บันทึกวัน FAT แล้ว');
    }

    public function destroy(Request $request, ProjectFat $fat)
    {
        $this->authorizeEdit($request);
        $fat->delete();
        $this->log($request, $fat->project, "ลบวัน FAT รอบที่ {$fat->round} ({$fat->when()})");

        return $this->done($request, 'ลบวัน FAT แล้ว');
    }

    private function done(Request $request, string $message)
    {
        return $request->wantsJson() ? response()->json(['message' => $message]) : back()->with('success', $message);   // JSON: Planning
    }

    private function authorizeEdit(Request $request): void
    {
        abort_unless($request->user()->canDispatchWork(), 403, 'ลงวัน FAT ได้เฉพาะ Admin / PM');
    }

    private function validated(Request $request, Project $project): array
    {
        $data = $request->validate([
            'fat_date' => ['required', 'date'],
            'fat_end_date' => ['nullable', 'date', 'after_or_equal:fat_date'],
            'fat_time' => ['nullable', 'date_format:H:i'],
            'status' => ['required', Rule::in(array_keys(ProjectFat::STATUS_LABELS))],
            'scope' => ['required', Rule::in(['all', 'some'])],
            'cabinet_ids' => ['array', 'required_if:scope,some'],
            'cabinet_ids.*' => ['integer', Rule::exists('cabinets', 'id')->where('project_id', $project->id)],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'fat_date.required' => 'กรุณาเลือกวัน FAT',
            'fat_end_date.after_or_equal' => 'วันสุดท้ายของ FAT ต้องไม่ก่อนวันแรก',
            'cabinet_ids.required_if' => 'เลือกตู้อย่างน้อย 1 ตู้ หรือเลือก "ทุกตู้"',
        ]);

        return [
            'fat_date' => $data['fat_date'],
            // one day = no end date
            'fat_end_date' => ($data['fat_end_date'] ?? null) && $data['fat_end_date'] !== $data['fat_date'] ? $data['fat_end_date'] : null,
            'fat_time' => $data['fat_time'] ?? null,
            'status' => $data['status'],
            'cabinet_ids' => $data['scope'] === 'some' ? array_values(array_map('intval', $data['cabinet_ids'] ?? [])) : null,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
        ];
    }

    private function log(Request $request, Project $project, string $line): void
    {
        ActivityLog::record($project->id, $request->user()->id, $project, 'fat', $line);
        PlanningBoard::touch();
    }
}
