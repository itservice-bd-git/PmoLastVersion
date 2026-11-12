<?php

namespace App\Services;

use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "สรุปงาน" (reports/today): per department, what came in on a day - or over a range of days - and what got done,
 * read at a glance (or from a screen on the wall). Read only. Which departments the viewer may see is decided by the caller with
 * DepartmentDashboard::scopeFor() and passed in as $departmentIds - this class never widens it.
 *
 *  - received      a Sub Task dispatched to the department in the range (assigned_at; moving it to another department
 *                  dispatches it again, so it counts for the department that holds it now)
 *  - completed     a Sub Task done in the range by the one done rule (CabinetSubtask::isDone()): the department pressed
 *                  "เสร็จงาน" (completed_at) or its checklist became complete (completed_date, timed by its last tick).
 *                  The earlier of the two is the day it was done, so a Sub Task is done on one day only.
 *  - new cabinets  cabinets (ตู้ / อุปกรณ์, by quantity) added to a project in the range; a department sees only those
 *                  it has work in. Not per department: a new cabinet's work is spread over many of them.
 *  - pending       the work it holds right now, not on a closed project: waiting (ASSIGNED), in progress (ACCEPTED /
 *                  IN_PROGRESS), and of those, overdue (due before today) - the same counts as the dashboard's "งานตามแผนก"
 *  - new work      ("มีงานเข้า") pending work nobody has opened from this page yet: cabinet_subtasks.is_active = 0 until
 *                  someone in its department's scope clicks it here (acknowledge()); then 1. Not tied to the range.
 * "ตู้" is the cabinets' quantity, as in รายงานการผลิต.
 */
class DailyWork
{
    use Concerns\DailyWorkChart;
    use Concerns\DailyWorkFeed;

    /** one day shown: its small chart covers this many days, ending on it */
    public const CHART_DAYS_FOR_ONE_DAY = 7;

    /** "ความเคลื่อนไหว": the latest this many work events; new cabinets are listed besides */
    public const FEED_WORK_LIMIT = 40;

    /** "ความเคลื่อนไหว": at most this many new cabinets, newest first */
    public const FEED_NEW_CABINET_LIMIT = 200;

    /** "มีงานเข้า": per department, at most this many of the work not opened yet are listed, newest first */
    public const NEW_WORK_LIMIT = 30;

    public const EVENT_RECEIVED = 'received';

    public const EVENT_ACCEPTED = 'accepted';

    public const EVENT_STARTED = 'started';

    public const EVENT_COMPLETED = 'completed';

    public const EVENT_NEW_CABINET = 'new_cabinet';

    /** feed event type => the label on its tag */
    public const EVENT_LABELS = [
        self::EVENT_RECEIVED => 'งานเข้า',
        self::EVENT_ACCEPTED => 'รับงาน',
        self::EVENT_STARTED => 'เริ่มทำ',
        self::EVENT_COMPLETED => 'เสร็จ',
        self::EVENT_NEW_CABINET => 'ของเข้า',
    ];

    private const SHORT_DAY_NAMES = ['อา.', 'จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.'];

    private const DAY_NAMES = ['อาทิตย์', 'จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์'];

    private const SHORT_MONTH_NAMES = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    /** dispatched and not finished: what a department holds */
    private const PENDING_ASSIGNMENT_STATUSES = [
        CabinetSubtask::ASSIGNMENT_ASSIGNED,
        CabinetSubtask::ASSIGNMENT_ACCEPTED,
        CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
    ];

    /**
     * @param  list<int>|null  $departmentIds  null = every department, [] = none
     * @param  Carbon  $firstDay  the first day shown
     * @param  Carbon|null  $lastDay  the last day shown (null = $firstDay only); the caller keeps it on or before today
     */
    public function build(?array $departmentIds, Carbon $firstDay, ?Carbon $lastDay = null, ?Carbon $now = null): array
    {
        $now = ($now ?? now())->copy();
        $today = $now->copy()->startOfDay();
        $rangeStart = $firstDay->copy()->startOfDay();
        $rangeEnd = ($lastDay ?? $firstDay)->copy()->endOfDay();
        $lastDay = $rangeEnd->copy()->startOfDay();
        $dayCount = (int) $rangeStart->diffInDays($lastDay) + 1;
        $chartBars = $this->chartBars($rangeStart, $rangeEnd, $dayCount);
        $chartStart = $chartBars[0]['start']->copy()->min($rangeStart);   // one day: its chart starts a week before

        $departments = $departmentIds === [] ? collect() : Department::query()->where('is_active', true)
            ->when($departmentIds !== null, fn ($query) => $query->whereIn('id', $departmentIds))
            ->orderBy('name')->get(['id', 'name', 'color', 'icon']);
        $shownDepartmentIds = $departments->pluck('id')->all();

        $movedSubtasks = $this->movedSubtasks($shownDepartmentIds, $chartStart, $rangeEnd);
        $receivedSubtasks = $movedSubtasks->filter(fn ($subtask) => $subtask->assigned_at && $subtask->assigned_at->between($chartStart, $rangeEnd));
        $completions = $movedSubtasks
            ->map(fn ($subtask) => ['subtask' => $subtask, 'completed' => $this->completion($subtask)])
            ->filter(fn ($completion) => $completion['completed'] && $completion['completed']['at']->between($chartStart, $rangeEnd));
        $newCabinets = $this->newCabinets($departmentIds, $shownDepartmentIds, $rangeStart, $rangeEnd);
        $pendingSubtasks = $this->pendingSubtasks($shownDepartmentIds);

        $receivedBetween = fn (Collection $subtasks, Carbon $start, Carbon $end) => $subtasks
            ->filter(fn ($subtask) => $subtask->assigned_at->between($start, $end));
        $completedBetween = fn (Collection $completions, Carbon $start, Carbon $end) => $completions
            ->filter(fn ($completion) => $completion['completed']['at']->between($start, $end))
            ->map(fn ($completion) => $completion['subtask']);

        $departmentRows = $departments->map(function (Department $department) use (
            $receivedSubtasks, $completions, $pendingSubtasks, $chartBars, $rangeStart, $rangeEnd, $today, $receivedBetween, $completedBetween
        ) {
            $received = $receivedSubtasks->where('department_id', $department->id);
            $completed = $completions->filter(fn ($completion) => $completion['subtask']->department_id === $department->id);
            $pending = $pendingSubtasks->where('department_id', $department->id);
            $receivedInRange = $receivedBetween($received, $rangeStart, $rangeEnd);
            $completedInRange = $completedBetween($completed, $rangeStart, $rangeEnd);
            $waitingCount = $pending->where('assignment_status', CabinetSubtask::ASSIGNMENT_ASSIGNED)->count();

            $row = [
                'id' => $department->id,
                'name' => (string) $department->name,
                'icon' => (string) $department->icon,
                'received' => $receivedInRange->count(),
                'receivedCabinets' => $this->cabinetQuantity($receivedInRange),
                'completed' => $completedInRange->count(),
                'completedCabinets' => $this->cabinetQuantity($completedInRange),
                'waiting' => $waitingCount,
                'inProgress' => $pending->count() - $waitingCount,
                'overdue' => $pending->filter(fn ($subtask) => $subtask->isLateOn($today))->count(),
                'pending' => $pending->count(),
                'unacknowledged' => $pending->filter(fn ($subtask) => ! $subtask->is_active)->count(),
                'chart' => array_map(fn ($bar) => [
                    'date' => $bar['start']->format('Y-m-d'),
                    'shortLabel' => $bar['shortLabel'],
                    'label' => $bar['label'],
                    'isHighlighted' => $bar['isHighlighted'],
                    'received' => $receivedBetween($received, $bar['start'], $bar['end'])->count(),
                    'completed' => $completedBetween($completed, $bar['start'], $bar['end'])->count(),
                ], $chartBars),
            ];
            $row['isQuiet'] = $row['received'] + $row['completed'] + $row['pending'] === 0;

            return $row;
        });

        // departments with work first, each group in name order, so cards stay put between refreshes
        $departmentRows = $departmentRows->sortBy(fn ($row) => [$row['isQuiet'] ? 1 : 0, $row['name']])->values();
        [$feed, $workEventCount] = $this->feed($movedSubtasks, $newCabinets, $rangeStart, $rangeEnd, $dayCount > 1);
        $shiftRange = fn (int $days) => [
            'from' => $rangeStart->copy()->addDays($days)->format('Y-m-d'),
            'to' => $lastDay->copy()->addDays($days)->format('Y-m-d'),
        ];
        $chartValues = $departmentRows->flatMap(fn ($row) => array_merge(array_column($row['chart'], 'received'), array_column($row['chart'], 'completed')));

        return [
            'range' => [
                'from' => $rangeStart->format('Y-m-d'),
                'to' => $lastDay->format('Y-m-d'),
                'dayCount' => $dayCount,
                'label' => $this->rangeLabel($rangeStart, $lastDay),
                'isLive' => $lastDay->isSameDay($now),                         // ends today: keep it live
                'isToday' => $dayCount === 1 && $rangeStart->isSameDay($now),
                'previous' => $shiftRange(-$dayCount),                          // the same length, just before / after
                'next' => $lastDay->copy()->addDays($dayCount)->lte($now) ? $shiftRange($dayCount) : null,
                'chartLabel' => $this->chartLabel($dayCount, $chartBars[0]['unit']),
            ],
            'today' => $now->format('Y-m-d'),
            'updatedAt' => $now->format('H:i:s'),
            'totals' => [
                'received' => $departmentRows->sum('received'),
                'receivedCabinets' => $this->cabinetQuantity($receivedBetween($receivedSubtasks, $rangeStart, $rangeEnd)),
                'completed' => $departmentRows->sum('completed'),
                'completedCabinets' => $this->cabinetQuantity($completedBetween($completions, $rangeStart, $rangeEnd)),
                'newCabinetQuantity' => (int) $newCabinets->sum(fn ($cabinet) => max(1, (int) $cabinet->quantity)),
                'newCabinetCount' => $newCabinets->count(),
                'newCabinetProjectCount' => $newCabinets->unique('project_id')->count(),
                'waiting' => $departmentRows->sum('waiting'),
                'inProgress' => $departmentRows->sum('inProgress'),
                'overdue' => $departmentRows->sum('overdue'),
                'pending' => $departmentRows->sum('pending'),
                'unacknowledged' => $departmentRows->sum('unacknowledged'),
                'activeDepartmentCount' => $departmentRows->filter(fn ($row) => $row['received'] + $row['completed'] > 0)->count(),
            ],
            'chartMax' => max(1, (int) $chartValues->max()),
            'departments' => $departmentRows->all(),
            'feed' => $feed,
            'workEventCount' => $workEventCount,
            'newWork' => $this->newWork($shownDepartmentIds),
        ];
    }

    /**
     * Someone opened work that came in: it is seen ("มีงานเข้า" goes away) for everyone. Only work within the viewer's
     * department scope, and not on a closed project (read only). A plain update: no activity log, updated_at untouched.
     *
     * @param  list<int>|null  $departmentIds  the viewer's scope (null = every department)
     * @return bool false = not the viewer's work, gone, or on a closed project
     */
    public function acknowledge(?array $departmentIds, int $subtaskId): bool
    {
        $subtask = CabinetSubtask::query()
            ->whereKey($subtaskId)
            ->whereNotNull('department_id')
            ->when($departmentIds !== null, fn ($query) => $query->whereIn('department_id', $departmentIds))
            ->whereHas('cabinetTask.cabinet.project', fn ($query) => $query->whereNotIn('status', Project::LOCKED_STATUSES))
            ->first(['id', 'is_active']);
        if (! $subtask) {
            return false;
        }
        if (! $subtask->is_active) {
            CabinetSubtask::query()->whereKey($subtask->id)->toBase()->update(['is_active' => true]);
        }

        return true;
    }

}
