<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Services\DepartmentSchedule;
use App\Support\Csv;
use Illuminate\Http\Request;

/**
 * "Export CSV" on the Planning page: a department's Sub Tasks over a window (from / to), filtered by project and status,
 * scoped on the server exactly as Planning is (DepartmentSchedule::resolveDepartment: your own department unless PMO).
 * The address stays my-department/export (route my-department.export) from the page it first belonged to.
 */
class DepartmentExportController extends Controller
{
    public function __construct(private DepartmentSchedule $schedule) {}

    /**
     * Same window/department/project/status filters as the screen, as a CSV the
     * department can take to a meeting or into Excel.
     */
    public function __invoke(Request $request)
    {
        [$department, $subtasks, $today] = $this->scheduleQuery($request);
        $user = $request->user();

        $rows = $subtasks->map(fn ($s) => $this->schedule->item($s, $user, $today))->map(fn ($i) => [
            $i['department_name'] ?? '-',
            $i['project_no'],
            $i['project_name'],
            $i['cabinet_mo'],
            $i['task_name'],
            $i['name'],
            $i['owner_name'] ?? '-',
            $i['start_date'] ? \Illuminate\Support\Carbon::parse($i['start_date'])->thaiDate() : '-',
            $i['due_date'] ? \Illuminate\Support\Carbon::parse($i['due_date'])->thaiDate() : '-',
            $i['assignment_status_label'],
            $i['is_overdue'] ? 'เกินกำหนด '.abs($i['days_remaining']).' วัน' : '',
            $i['progress'].'%',
            $i['checklists_completed'].'/'.$i['checklists_total'],
        ]);

        return Csv::download(
            'งานแผนก-'.($department?->name ?? 'ทุกแผนก').'-'.$today->format('Y-m-d').'.csv',
            ['แผนก', 'Project', 'ชื่อโครงการ', 'ตู้ (MO)', 'Task', 'Sub Task', 'ผู้รับผิดชอบ', 'เริ่ม', 'กำหนดส่ง', 'สถานะ', 'เกินกำหนด', 'ความคืบหน้า', 'Checklist เสร็จ/ทั้งหมด'],
            $rows
        );
    }

    private function scheduleQuery(Request $request): array
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            // 'all' (PMO only, resolveDepartment() enforces that) or a real id -
            // resolved below, so this stays a loose string rule here.
            'department_id' => ['nullable', 'string'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'status' => ['nullable', 'in:ASSIGNED,ACCEPTED,IN_PROGRESS,COMPLETED,OVERDUE'],
        ]);

        // null = "ทุกแผนก" (PMO only - resolveDepartment() already enforces
        // canViewOtherDepartments() before this can ever come back null).
        $department = $this->resolveDepartment($request);
        $today = today();

        return [$department, $this->schedule->subtasks($department, $data, $today), $today];
    }

    private function resolveDepartment(Request $request): ?Department
    {
        return $this->schedule->resolveDepartment($request->user(), $request->input('department_id'));
    }
}
