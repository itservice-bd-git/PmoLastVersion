<?php

namespace App\Http\Controllers;

use App\Models\CabinetSubtask;
use App\Models\Department;
use Illuminate\Http\Request;

/**
 * "Dispatch" - a flat list of every Cabinet Sub Task across every Project,
 * for Admin/PM to assign or reassign a department from one place instead of
 * opening each Cabinet individually. Pure read/filter layer over the same
 * cabinet_subtasks data and SubtaskAssignmentService the Cabinet page and
 * My Department already use - no new assignment data or logic here.
 */
class AssignmentController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->canDispatchWork(), 403, 'เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่เข้าหน้านี้ได้');

        $query = CabinetSubtask::query()
            ->with(['cabinetTask.cabinet.project', 'department', 'acceptedBy']);

        if ($search = $request->get('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('cabinet_subtasks.name', 'like', "%{$search}%")
                    ->orWhereHas('cabinetTask', fn ($t) => $t->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('cabinetTask.cabinet', fn ($c) => $c->where('mo_no', 'like', "%{$search}%")
                        ->orWhere('cabinet_name', 'like', "%{$search}%"))
                    ->orWhereHas('cabinetTask.cabinet.project', fn ($p) => $p->where('project_no', 'like', "%{$search}%")
                        ->orWhere('project_name', 'like', "%{$search}%"));
            });
        }

        if ($status = $request->get('status')) {
            $query->where('assignment_status', $status);
        }

        if ($departmentId = $request->get('department_id')) {
            $query->where('department_id', $departmentId);
        }

        // "Urgent" = overdue OR due within 3 days (same window the row's own Due
        // Date badge below uses) AND not finished yet. Overdue is a subset of
        // "due within 3 days", so a single upper-bound check covers both.
        if ($request->boolean('urgent')) {
            $query->whereNotNull('due_date')
                ->where('assignment_status', '!=', CabinetSubtask::ASSIGNMENT_COMPLETED)
                ->whereDate('due_date', '<=', today()->addDays(3));
        }

        // Work that still needs a decision (UNASSIGNED/ASSIGNED) bubbles to the
        // top; already-locked work (ACCEPTED onwards) sinks to the bottom since
        // there's nothing left to dispatch there - it's shown for visibility only.
        $subtasks = $query
            ->orderByRaw("CASE assignment_status
                WHEN 'UNASSIGNED' THEN 0
                WHEN 'ASSIGNED' THEN 1
                WHEN 'ACCEPTED' THEN 2
                WHEN 'IN_PROGRESS' THEN 3
                ELSE 4 END")
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->latest('cabinet_subtasks.id')
            ->paginate(30)
            ->withQueryString();

        return view('assignments.index', [
            'subtasks' => $subtasks,
            // Unfiltered, matching the Cabinet page's own department dropdown - an
            // inactive department can still hold Sub Tasks that need to be reassigned.
            'departments' => Department::orderBy('name')->get(),
            'assignmentStatuses' => CabinetSubtask::$assignmentStatuses,
            'filters' => $request->only(['q', 'status', 'department_id', 'urgent']),
        ]);
    }
}
