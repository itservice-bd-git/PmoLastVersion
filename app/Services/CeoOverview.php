<?php

namespace App\Services;

use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The dashboard's "ภาพรวม CEO" view: the business side of the work in hand - its contract value and when it is due,
 * on-time deliveries, what is late and what it is worth, next month's deliveries and how far they are made, the largest
 * customers, and where the late work sits. Read only; the projects this person may see. Money is left out (null) for a
 * person without the data.finance permission.
 */
class CeoOverview
{
    private const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    private const DAYS = ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.'];

    /** Next month's projects made less than this (%) are the ones to push. */
    private const LOW_PROGRESS = 30;

    public function __construct(private ExecutiveReport $executive) {}

    public function build(User $user, ?Carbon $now = null): array
    {
        $now ??= now();
        $today = $now->copy()->startOfDay();
        $money = $user->allows('data.finance');
        $exec = $this->executive->build($user, $now);

        $visible = fn () => Project::visibleTo($user)->where('status', '!=', Project::STATUS_CANCELLED);
        $active = $visible()->whereIn('status', [Project::STATUS_IN_PROGRESS, Project::STATUS_ON_HOLD])
            ->with(['jobType:id,name', 'deliveries:id,project_id,status,departed_at,delivered_at'])->withAvg('cabinets', 'progress')->get();
        $value = fn (Collection $list) => $money ? (float) $list->sum('contract_value') : null;

        // late: running, past its due date, not put off (Project::scopeRunningLate) - and those already out of the factory
        $late = $active->filter(fn (Project $p) => $p->status === Project::STATUS_IN_PROGRESS && $p->due_date && $p->due_date->lt($today) && ! $p->isDuePending())
            ->sortByDesc(fn (Project $p) => (float) $p->contract_value)->values();
        $shipped = fn (Project $p) => $p->deliveries->contains(fn (ProjectDelivery $d) => in_array($d->status, [ProjectDelivery::DEPARTED, ProjectDelivery::DELIVERED], true));
        $lateRows = $late->map(fn (Project $p) => [
            'no' => $p->project_no, 'name' => $p->project_name, 'customer' => $this->customer($p->customer_name), 'url' => route('projects.show', $p),
            'value' => $money ? (float) $p->contract_value : null, 'progress' => $p->production_progress,
            'days' => (int) $p->due_date->diffInDays($today), 'shipped' => $shipped($p),
        ]);
        $closable = $lateRows->where('shipped', true)->values();

        // contract value due per month, from last month to four ahead: closed, and still to deliver
        $from = $today->copy()->startOfMonth()->subMonth();
        $due = $visible()->whereNotNull('due_date')->where('due_date', '>=', $from)->where('due_date', '<', $from->copy()->addMonths(6))
            ->withAvg('cabinets', 'progress')->get(['id', 'status', 'due_date', 'contract_value', 'due_pending_at']);
        $months = collect(range(0, 5))->map(function ($i) use ($from, $due, $today, $money) {
            $m = $from->copy()->addMonths($i);
            $list = $due->filter(fn ($p) => $p->due_date->isSameMonth($m));
            $open = $list->where('status', '!=', Project::STATUS_COMPLETED);

            return ['label' => self::MONTHS[$m->month - 1], 'current' => $m->isSameMonth($today), 'next' => $m->isSameMonth($today->copy()->addMonthNoOverflow()),
                'projects' => $list->count(), 'value' => $money ? (float) $list->sum('contract_value') : null,
                'closed' => $money ? (float) $list->where('status', Project::STATUS_COMPLETED)->sum('contract_value') : null,
                'progress' => $open->isEmpty() ? null : (int) round($open->avg(fn ($p) => $p->production_progress))];
        })->values();

        // next month: what must go out, and how far it is made
        $next = $today->copy()->addMonthNoOverflow()->startOfMonth();
        $nextList = $visible()->whereNotIn('status', [Project::STATUS_COMPLETED])->whereBetween('due_date', [$next, $next->copy()->endOfMonth()])
            ->withAvg('cabinets', 'progress')->get();
        $band = fn (int $a, int $b) => $nextList->filter(fn ($p) => $p->production_progress >= $a && $p->production_progress <= $b);
        $low = $nextList->filter(fn ($p) => $p->production_progress < self::LOW_PROGRESS);
        $firstWeek = $nextList->filter(fn ($p) => $p->due_date->day <= 7);
        $focus = [
            'month' => self::MONTHS[$next->month - 1], 'projects' => $nextList->count(), 'value' => $value($nextList),
            'progress' => $nextList->isEmpty() ? 0 : (int) round($nextList->avg(fn ($p) => $p->production_progress)),
            'low' => $low->count(), 'lowValue' => $value($low), 'lowLimit' => self::LOW_PROGRESS,
            'bands' => [
                ['label' => 'ยังไม่เริ่มผลิต', 'tone' => 'red', 'projects' => $band(0, 0)->count(), 'value' => $value($band(0, 0))],
                ['label' => 'ผลิต 1–29%', 'tone' => 'pink', 'projects' => $band(1, 29)->count(), 'value' => $value($band(1, 29))],
                ['label' => 'ผลิต 30–69%', 'tone' => 'navy', 'projects' => $band(30, 69)->count(), 'value' => $value($band(30, 69))],
                ['label' => 'ผลิต 70% ขึ้นไป', 'tone' => 'green', 'projects' => $band(70, 100)->count(), 'value' => $value($band(70, 100))],
            ],
            'firstWeek' => ['projects' => $firstWeek->count(), 'value' => $value($firstWeek),
                'progress' => $firstWeek->isEmpty() ? 0 : (int) round($firstWeek->avg(fn ($p) => $p->production_progress))],
            'top' => $nextList->sortByDesc(fn ($p) => $money ? (float) $p->contract_value : -$p->production_progress)->take(5)->map(fn (Project $p) => [
                'no' => $p->project_no, 'name' => $p->project_name, 'customer' => $this->customer($p->customer_name), 'url' => route('projects.show', $p),
                'due' => $p->due_date->day.' '.self::MONTHS[$p->due_date->month - 1], 'progress' => $p->production_progress,
                'value' => $money ? (float) $p->contract_value : null,
            ])->values()->all(),
        ];

        // deliveries made this month, on time = arrived on or before the round's last day
        $delivered = ProjectDelivery::whereIn('project_id', $visible()->select('id'))->whereNotNull('delivered_at')
            ->where('delivered_at', '>=', $today->copy()->startOfMonth())->get(['delivery_date', 'delivery_end_date', 'delivered_at']);
        $onTime = $delivered->filter(fn ($d) => $d->delivered_at->copy()->startOfDay()->lte(($d->delivery_end_date ?? $d->delivery_date)));

        $closed = $visible()->where('status', Project::STATUS_COMPLETED)->where('updated_at', '>=', $today->copy()->startOfMonth())->get(['id', 'contract_value']);
        $byType = $active->groupBy(fn (Project $p) => $p->jobType?->name ?? 'ไม่ระบุประเภท')
            ->map(fn ($list, $name) => ['name' => $name, 'projects' => $list->count(), 'value' => $value($list)])
            ->sortByDesc(fn ($t) => $t['value'] ?? $t['projects'])->values();
        $customers = $active->groupBy(fn (Project $p) => $this->customer($p->customer_name))
            ->map(fn ($list, $name) => ['name' => $name, 'projects' => $list->count(), 'value' => $value($list)])
            ->sortByDesc(fn ($c) => $c['value'] ?? $c['projects'])->take(6)->values();

        $departments = $this->departments($visible, $today);
        $k = $exec['kpis'];
        $depts = collect($exec['departments']);
        $running = $active->where('status', Project::STATUS_IN_PROGRESS);

        return [
            'asOf' => $now,
            'money' => $money,
            'backlog' => ['value' => $value($active), 'projects' => $active->count(), 'valued' => $active->whereNotNull('contract_value')->where('contract_value', '>', 0)->count()],
            'months' => $months->all(),
            'onTime' => ['rounds' => $delivered->count(), 'onTime' => $onTime->count(),
                'rate' => $delivered->isEmpty() ? null : (int) round($onTime->count() / $delivered->count() * 100), 'month' => self::MONTHS[$today->month - 1]],
            'late' => ['projects' => $late->count(), 'value' => $value($late), 'rows' => $lateRows->all()],
            'closable' => ['projects' => $closable->count(), 'value' => $money ? (float) $closable->sum('value') : null, 'rows' => $closable->all(),
                'leftProjects' => $late->count() - $closable->count(), 'leftValue' => $money ? (float) ($lateRows->sum('value') - $closable->sum('value')) : null],
            'closed' => ['projects' => $closed->count(), 'value' => $value($closed)],
            'cabinets' => ['planned' => $k['monthPlanned'], 'done' => $k['monthDone'], 'overdue' => $k['monthOverdue'], 'month' => $k['monthLabel']],
            'pending' => $active->filter(fn (Project $p) => $p->isDuePending())->count(),
            'focus' => $focus,
            'types' => $byType->all(),
            'customers' => $customers->all(),
            'health' => $exec['statusCounts'] + ['progress' => $running->isEmpty() ? 0 : (int) round($running->avg(fn ($p) => $p->production_progress))],
            'departments' => $departments,
            'bottlenecks' => [
                'rows' => $depts->take(3)->all(), 'late' => $k['lateSubtasks'], 'cabinets' => $k['lateCabinets'],
                'share' => $k['lateSubtasks'] ? (int) round($depts->take(2)->sum('late') / $k['lateSubtasks'] * 100) : 0,
                'top' => $depts->take(2)->pluck('name')->all(),
            ],
        ];
    }

    /** Work done on time (completed on or before its due day) counts as on time; this many points and under reads "ต้องติดตาม". */
    private const ON_TIME_WATCH = 60;

    /**
     * How each department works, over the last 30 days against the 30 before: Sub Tasks finished and how many on time,
     * and now: what it still holds in open projects, what of that is late and the longest wait. Departments with nothing
     * in either period are left out.
     */
    private function departments(callable $visible, Carbon $today): array
    {
        $from = $today->copy()->subDays(30);
        $prevFrom = $from->copy()->subDays(30);
        $subs = CabinetSubtask::query()->whereNotNull('cabinet_subtasks.department_id')
            ->join('cabinet_tasks as t', 't.id', '=', 'cabinet_subtasks.cabinet_task_id')
            ->join('cabinets as c', 'c.id', '=', 't.cabinet_id')
            ->whereIn('c.project_id', $visible()->select('id'))
            ->join('projects as p', 'p.id', '=', 'c.project_id')
            ->get(['cabinet_subtasks.id', 'cabinet_subtasks.department_id', 'cabinet_subtasks.status', 'cabinet_subtasks.assignment_status',
                'cabinet_subtasks.due_date', 'cabinet_subtasks.completed_at', 'c.id as cabinet', 'p.status as project_status']);
        $names = Department::whereIn('id', $subs->pluck('department_id')->unique())->pluck('name', 'id');

        $rows = $subs->groupBy('department_id')->map(function (Collection $list, $id) use ($from, $prevFrom, $today, $names) {
            $done = $list->filter(fn ($s) => $s->isDone() && $s->completed_at && $s->completed_at->gte($from));
            $prev = $list->filter(fn ($s) => $s->isDone() && $s->completed_at && $s->completed_at->gte($prevFrom) && $s->completed_at->lt($from));
            $dated = $done->filter(fn ($s) => $s->due_date);
            $onTime = $dated->filter(fn ($s) => $s->completed_at->copy()->startOfDay()->lte($s->due_date));
            $open = $list->filter(fn ($s) => ! $s->isDone() && $s->project_status !== Project::STATUS_COMPLETED);
            $late = $open->filter(fn ($s) => $s->isLateOn($today));
            $rate = $dated->isEmpty() ? null : (int) round($onTime->count() / $dated->count() * 100);

            return [
                'id' => (int) $id, 'name' => $names[$id] ?? '-', 'url' => route('dashboard', ['view' => 'dept', 'department' => $id]),
                'done' => $done->count(), 'prev' => $prev->count(), 'onTime' => $onTime->count(), 'dated' => $dated->count(), 'rate' => $rate,
                'open' => $open->count(), 'late' => $late->count(), 'lateCabinets' => $late->pluck('cabinet')->unique()->count(),
                'oldest' => $late->isEmpty() ? 0 : (int) $late->max(fn ($s) => $s->due_date->diffInDays($today)),
                'state' => match (true) {
                    $late->count() >= 20 => 'jam',
                    $late->isNotEmpty() || ($rate !== null && $rate < self::ON_TIME_WATCH) => 'watch',
                    default => 'ok',
                },
            ];
        })->filter(fn ($r) => $r['done'] || $r['prev'] || $r['open'])
            ->sortBy(fn ($r) => [-$r['late'], -$r['open'], $r['name']])->values();

        $dated = $rows->sum('dated');

        return [
            'rows' => $rows->all(),
            'done' => $rows->sum('done'), 'prev' => $rows->sum('prev'), 'open' => $rows->sum('open'), 'late' => $rows->sum('late'),
            'rate' => $dated ? (int) round($rows->sum('onTime') / $dated * 100) : null, 'watch' => self::ON_TIME_WATCH,
        ];
    }

    /** "บริษัท ฤทธา จำกัด" → "ฤทธา": the name a person says. */
    private function customer(?string $name): string
    {
        $name = trim(preg_replace(['/^บริษัท\s*/u', '/\s*จำกัด\s*(\(มหาชน\))?$/u'], '', (string) $name));

        return $name !== '' ? $name : 'ไม่ระบุลูกค้า';
    }

    /** Day name and date for a heading ("จ. 23 พ.ย."). */
    public static function short(Carbon $d): string
    {
        return self::DAYS[$d->dayOfWeekIso - 1].' '.$d->day.' '.self::MONTHS[$d->month - 1];
    }
}
