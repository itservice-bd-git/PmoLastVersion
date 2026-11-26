<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\ProjectFat;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The dashboard's "สัปดาห์นี้" view: what a PM must know on opening the program, in the order they ask it -
 *  1. this week (Mon - Sun): the FAT rounds, deliveries and due dates, each with whether its cabinets are ready,
 *     and the departments' work due this week
 *  2. the projects behind plan (ExecutiveReport's rule, so both views say the same)
 *  3. + 4. what is stuck, at which department, who holds it, and the next date it must be cleared by (the cabinet's
 *     next FAT / delivery / due date)
 *  5. next week: what is coming and which of it is not ready yet.
 * Open projects this person may see (Project::visibleTo); $mine = only the ones they are PM of. Read only.
 */
class PmWeek
{
    /** A project put off with no new dates yet (Project::isDuePending), as the dashboard says it: waiting on sales. */
    public const PENDING_STATE = 'รอเซลแจ้งวันส่ง';

    /** Work handed out this many days ago and still not accepted counts as stuck at that department. */
    public const WAIT_DAYS = 2;

    private const DAY_NAMES = ['จันทร์', 'อังคาร', 'พุธ', 'พฤหัสบดี', 'ศุกร์', 'เสาร์', 'อาทิตย์'];

    private const DAY_SHORT = ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.'];

    private const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    private Carbon $today;

    /** @var array<int, string> */
    private array $colors = [];

    /** @var array<int, array<string, ?Carbon>> cabinet id => delivery step (DeliveryChecklist) => when its item was ticked */
    private array $shipSteps = [];

    /** @var array<int, array<string, ?Carbon>> Task id => the delivery steps whose items sit in it => when ticked */
    private array $taskShip = [];

    /** @var array<int, true> cabinets made: their Checklist has items and every one ticked, the delivery steps' own aside */
    private array $made = [];

    /** A work card's chip: where the project's work is (stageOf). key => its words; the colours are the view's (_pm.blade.php) */
    public const STAGES = [
        'notstarted' => 'ยังไม่เริ่มผลิต', 'making' => 'กำลังผลิต', 'made' => 'พร้อมห่อ',
        'ready' => 'พร้อมส่ง', 'out' => 'ออกจากโรงงานแล้ว', 'there' => 'ส่งถึงแล้ว',
    ];

    /** The delivery steps as the panel lists them under a cabinet, and the word for the furthest one done. */
    private const SHIP_STEPS = [
        DeliveryChecklist::PACK => ['ห่อ', 'ห่อแล้ว'],
        DeliveryChecklist::BILL => ['แจ้งเปิดบิล', 'แจ้งเปิดบิลแล้ว'],
        DeliveryChecklist::DEPART => ['ออกจากโรงงาน', 'ออกจากโรงงานแล้ว'],
        DeliveryChecklist::DELIVER => ['ส่งถึง', 'ส่งถึงแล้ว'],
    ];

    public function __construct(private ExecutiveReport $executive) {}

    /** @param int $offset the week shown: 0 = this week, 1 = next, -1 = last (the dashboard's ‹ ›) */
    public function build(User $user, bool $mine = false, ?Carbon $now = null, int $offset = 0): array
    {
        $now ??= now();
        $this->today = $now->copy()->startOfDay();
        $this->colors = PlanningBoard::departmentColors();
        $monday = $this->today->copy()->startOfWeek(Carbon::MONDAY)->addWeeks($offset);
        $week = [$monday, $monday->copy()->addDays(6)];
        $next = [$week[0]->copy()->addWeek(), $week[1]->copy()->addWeek()];

        $projects = Project::visibleTo($user)
            ->whereNotIn('status', [Project::STATUS_COMPLETED, Project::STATUS_CANCELLED])
            ->when($mine, fn ($q) => $q->where('project_manager_id', $user->id))
            ->with(['projectManager:id,name', 'salesPerson:id,name,team_id', 'salesPerson.team:id,name', 'jobType:id,name', 'fats', 'deliveries', 'cabinets.tasks.subtasks.department:id,name', 'cabinets.tasks.subtasks.acceptedBy:id,name'])
            ->withAvg('cabinets', 'progress')->orderBy('due_date')->get();
        // each Sub Task knows its cabinet and project without asking again
        $projects->each(fn (Project $p) => $p->cabinets->each(fn (Cabinet $c) => $c->tasks->each(function ($t) use ($c, $p) {
            $c->setRelation('project', $p);
            $t->setRelation('cabinet', $c);
            $t->subtasks->each->setRelation('cabinetTask', $t);
        })));
        $subtasks = $projects->flatMap(fn (Project $p) => $p->cabinets->flatMap->tasks->flatMap->subtasks);
        $this->shipSteps = $this->shipSteps($projects->flatMap->cabinets->pluck('id')->all());
        $this->made = $this->madeCabinets($projects->flatMap->cabinets->pluck('id')->all());

        // every running project's line by ExecutiveReport's own rule (state, progress against time), so both views say the
        // same - made from the projects loaded above, not loaded a second time
        $rows = $this->executive->rows($projects, $this->today);
        $stuck = $this->stuck($projects, $subtasks, $next[1]);
        $weekData = $this->window($week, $projects, $subtasks, true);

        $ids = $projects->modelKeys();
        $stateOf = fn (Project $p) => $rows->get($p->id)['state'] ?? 'notstarted';

        $board = $this->board($weekData, $projects, $rows, collect($stuck));

        return [
            'asOf' => $now,
            'mine' => $mine,
            'offset' => $offset,
            // the Kanban (จ. - ศ.) and how many open projects stand in each state against plan
            'board' => $board,
            'overview' => $this->overview($rows, $projects, $weekData, collect($stuck), $board),
            'stateCounts' => $projects->countBy($stateOf)->all() + ['late' => 0, 'watch' => 0, 'ok' => 0, 'close' => 0, 'notstarted' => 0],
            'myProjects' => Project::visibleTo($user)->whereNotIn('status', [Project::STATUS_COMPLETED, Project::STATUS_CANCELLED])
                ->where('project_manager_id', $user->id)->count(),
            'projectCount' => $projects->count(),
            'week' => $weekData,
            'stuck' => $stuck,
            'stuckPlaces' => collect($stuck)->groupBy('place')->map(fn ($g, $place) => [
                'place' => $place, 'color' => $g->first()['color'], 'n' => $g->sum('n'), 'projects' => $g->pluck('projectId')->unique()->count(),
            ])->sortByDesc('n')->values()->all(),
        ];
    }

    /** A week: its days with the dated events, and the departments' unfinished work due in it. */
    private function window(array $range, Collection $projects, Collection $subtasks, bool $isThisWeek): array
    {
        [$from, $to] = $range;
        $events = $projects->flatMap(fn (Project $p) => $this->events($p, $from, $to))
            // next week lists only what still has to happen, and starts in it (a round running on from this week is listed there)
            ->when(! $isThisWeek, fn ($e) => $e->where('done', false)->filter(fn ($x) => $x['starts']->gte($from)))
            ->sortBy(fn ($e) => [$e['date']->format('Ymd'), $e['order']])->values();

        $days = collect(range(0, 6))->map(function ($i) use ($from, $events) {
            $d = $from->copy()->addDays($i);

            return [
                'date' => $d, 'name' => self::DAY_NAMES[$i], 'short' => self::DAY_SHORT[$i], 'label' => $d->day.' '.self::MONTHS[$d->month - 1],
                'today' => $d->equalTo($this->today), 'past' => $d->lt($this->today),
                'events' => $events->filter(fn ($e) => $e['date']->equalTo($d))->values()->all(),
            ];
        })->all();

        $due = $subtasks->filter(fn (CabinetSubtask $s) => ! $s->isDone() && $s->due_date && $s->due_date->betweenIncluded($from, $to));
        $load = $due->groupBy(fn ($s) => (int) $s->department_id)->map(function ($list, $id) {
            $notAccepted = $list->whereIn('assignment_status', [CabinetSubtask::ASSIGNMENT_UNASSIGNED, CabinetSubtask::ASSIGNMENT_ASSIGNED])->count();

            return [
                'name' => $id ? ($list->first()->department?->name ?? '-') : 'ยังไม่จ่ายงาน',
                'color' => $id ? ($this->colors[$id] ?? '#94a3b8') : '#94a3b8',
                'n' => $list->count(),
                'notStarted' => $list->whereIn('assignment_status', [CabinetSubtask::ASSIGNMENT_UNASSIGNED, CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED])->count(),
                'notAccepted' => $notAccepted,
                'late' => $list->filter(fn ($s) => $s->isLateOn($this->today))->count(),
                'cabinets' => $list->map(fn ($s) => $s->cabinetTask->cabinet_id)->unique()->count(),
            ];
        })->sortByDesc('n')->values()->all();

        return [
            'from' => $from, 'to' => $to,
            'label' => $this->span($from, $to),
            'days' => $days,
            'events' => $events->all(),
            'eventCount' => $events->count(),
            'notReady' => $events->where('done', false)->filter(fn ($e) => $e['tone'] === 'red' || $e['tone'] === 'amber')->count(),
            'load' => $load,
            'workDue' => $due->count(),
        ];
    }

    /** One project's FAT rounds, deliveries, own due date and cabinets due, between $from and $to. */
    private function events(Project $p, Carbon $from, Carbon $to): Collection
    {
        $out = collect();
        $base = ['projectId' => $p->id, 'project' => $p->project_name, 'no' => $p->project_no, 'url' => route('projects.show', $p), 'pm' => $p->projectManager?->name];
        $finished = fn (Collection $cabs) => $cabs->where('status', Cabinet::STATUS_COMPLETED)->count();

        foreach ($p->fats as $f) {
            if (! $this->overlaps($f->fat_date, $f->lastDay(), $from, $to)) {
                continue;
            }
            $cabs = $this->roundCabinets($p, $f->cabinet_ids);
            [$status, $tone, $done] = match (true) {
                $f->status === ProjectFat::PASSED => ['ผ่านแล้ว', 'green', true],
                $f->status !== ProjectFat::SCHEDULED => [$f->statusLabel(), 'red', true],
                (bool) $f->date_pending_at => ['รอยืนยันวัน', 'amber', false],
                $f->lastDay()->lt($this->today) => ['ยังไม่บันทึกผล', 'amber', false],
                default => $this->readiness($cabs, $f->fat_date),
            };
            $out->push($base + [
                'kind' => 'fat', 'order' => 0, 'title' => 'FAT รอบ '.$f->round, 'date' => $f->fat_date->copy()->max($from), 'starts' => $f->fat_date->copy(),
                'until' => $f->fat_end_date && $f->fat_end_date->gt($f->fat_date) ? $this->short($f->fat_end_date) : null,
                'status' => $status, 'tone' => $tone, 'done' => $done, 'cabinets' => $cabs->count(), 'cabDone' => $finished($cabs), 'cabinetIds' => $cabs->pluck('id')->all(),
            ]);
        }

        foreach ($p->deliveries as $d) {
            if (! $this->overlaps($d->firstDay(), $d->lastDay(), $from, $to)) {
                continue;
            }
            $cabs = $this->roundCabinets($p, $d->cabinet_ids);
            [$status, $tone, $done] = match ($d->status) {
                ProjectDelivery::DELIVERED => ['ส่งถึงแล้ว', 'green', true],
                ProjectDelivery::DEPARTED => ['ออกจากโรงงานแล้ว', 'green', true],
                ProjectDelivery::REJECTED => ['ฝ่ายจัดส่งตีกลับ', 'red', false],
                ProjectDelivery::DRAFT => ['ยังไม่ส่งให้ฝ่ายจัดส่ง', 'amber', false],
                ProjectDelivery::WAITING => ['รอจัดรถ', 'amber', false],
                default => $d->firstDay()->lt($this->today) ? ['เลยวัน ยังไม่ออกจากโรงงาน', 'red', false] : $this->readiness($cabs, $d->firstDay(), 'จัดรถแล้ว'),
            };
            $out->push($base + [
                'kind' => 'delivery', 'order' => 1, 'title' => 'ส่งมอบรอบ '.$d->round, 'date' => $d->firstDay()->copy()->max($from), 'starts' => $d->firstDay()->copy(),
                'until' => $d->lastDay()->gt($d->firstDay()) ? $this->short($d->lastDay()) : null,
                'status' => $status, 'tone' => $tone, 'done' => $done, 'cabinets' => $cabs->count(), 'cabDone' => $finished($cabs), 'cabinetIds' => $cabs->pluck('id')->all(),
                'site' => trim((string) $d->site) ?: null, 'map' => $d->map_url ?: null,
                // what the delivery department set it to, as the งานจัดส่ง page says it (a draft has not reached them yet)
                'dept' => $d->status === ProjectDelivery::DRAFT ? ['ยังไม่ส่งให้ฝ่ายจัดส่ง', '#64748b']
                    : [$d->stateLabel().(in_array($d->status, [ProjectDelivery::WAITING, ProjectDelivery::SCHEDULED], true) && $d->firstDay()->lt($this->today) ? ' · เลยวันส่ง' : ''), $d->stateColor()],
            ]);
        }

        if ($p->due_date && $p->due_date->betweenIncluded($from, $to)) {
            $open = $p->cabinets->where('status', '!=', Cabinet::STATUS_COMPLETED);
            [$status, $tone] = match (true) {
                $p->isDuePending() => ['รอยืนยันวันส่ง', 'amber'],
                $p->cabinets->isNotEmpty() && $open->isEmpty() => ['ผลิตครบแล้ว', 'green'],
                default => ['ตู้ยังไม่เสร็จ '.$open->count().'/'.$p->cabinets->count(), $p->due_date->lte($this->today->copy()->addDays(2)) ? 'red' : 'amber'],
            };
            $out->push($base + ['kind' => 'due', 'order' => 2, 'title' => 'ครบกำหนดส่ง', 'date' => $p->due_date->copy(), 'starts' => $p->due_date->copy(), 'until' => null,
                'status' => $status, 'tone' => $tone, 'done' => $tone === 'green', 'cabinets' => $p->cabinets->count(), 'cabDone' => $finished($p->cabinets)]);
        }

        // cabinets due on a day (not the project's own due date - that is above), one line per project and day
        $p->cabinets->filter(fn (Cabinet $c) => $c->status !== Cabinet::STATUS_COMPLETED && $c->due_date && $c->due_date->betweenIncluded($from, $to)
            && ! ($p->due_date && $c->due_date->equalTo($p->due_date)))
            ->groupBy(fn ($c) => $c->due_date->format('Y-m-d'))
            ->each(function ($list) use ($out, $base) {
                $avg = (int) round($list->avg('progress'));
                $out->push($base + ['kind' => 'cabinet', 'order' => 3, 'title' => 'ตู้ครบกำหนด '.$list->count().' ตู้', 'date' => $list->first()->due_date->copy(), 'starts' => $list->first()->due_date->copy(), 'until' => null,
                    'status' => 'ผลิตเฉลี่ย '.$avg.'%', 'tone' => $avg >= 100 ? 'green' : ($list->first()->due_date->lt($this->today) ? 'red' : 'amber'),
                    'done' => false, 'cabinets' => $list->count(), 'cabDone' => 0]);
            });

        return $out;
    }

    /**
     * The week as Kanban columns, Monday to Friday (Saturday and Sunday go into Friday, marked with their day): every
     * FAT round and delivery of the day, worst project first. Each item carries what its card shows (the
     * appointment, the project's state against plan, cabinets finished of the round) and what its panel adds (production
     * against plan, the PM, and the project's stuck work: where, who, by when).
     */
    private function board(array $win, Collection $projects, Collection $rows, Collection $stuck): array
    {
        $from = $win['from'];
        $byId = $projects->keyBy('id');
        $issues = $stuck->groupBy('projectId');
        $kinds = ['fat' => 'FAT', 'delivery' => 'ขนส่ง'];
        $states = ['late' => 'เลยวันส่ง', 'watch' => 'ช้ากว่าแผน', 'ok' => 'ตามแผน', 'close' => 'ผลิตครบ', 'notstarted' => 'ยังไม่เริ่ม'];
        $rank = ['late' => 0, 'watch' => 1, 'ok' => 2, 'close' => 3, 'notstarted' => 4];

        $days = collect(range(0, 4))->map(function ($i) use ($from) {
            $d = $from->copy()->addDays($i);

            return ['short' => self::DAY_SHORT[$i], 'name' => self::DAY_NAMES[$i], 'n' => $d->day, 'today' => $d->equalTo($this->today), 'past' => $d->lt($this->today), 'items' => []];
        })->all();

        // what every card carries: the project, how it stands against plan, its cabinets (with their Tasks, for the panel)
        $card = function (Project $p, array $e, Collection $cabs, Carbon $date) use ($rows, $issues, $states, $rank) {
            $row = $rows->get($p->id);
            $state = $row['state'] ?? 'notstarted';

            return $e + [
                'projectId' => $p->id, 'name' => $p->project_name, 'no' => $p->project_no, 'url' => route('projects.show', $p), 'pm' => $p->projectManager?->name ?: 'ยังไม่ระบุ',
                'sales' => $p->salesPerson?->name ?: (trim((string) $p->sales_person) ?: null),
                'team' => $p->salesPerson?->team?->name,   // the project's team is its sales person's (the โครงการ list's ทีม)
                'jobType' => $p->jobType?->name,
                'site' => null, 'map' => null,
                'state' => $state, 'stateText' => $states[$state], 'rank' => $rank[$state],
                // why it reads behind plan / late (ExecutiveReport's rule): its late Sub Tasks and where, or production behind the time used
                'why' => $this->why($row),
                'made' => (int) ($row['progress'] ?? $p->production_progress), 'plan' => $row['timeElapsed'] ?? null,
                'done' => $cabs->where('status', Cabinet::STATUS_COMPLETED)->count(), 'total' => $cabs->count(),
                'cabinetList' => $cabs->sortBy('sequence')->values()->map(fn (Cabinet $c) => [
                    'mo' => $c->mo_no ?: '-', 'name' => $c->cabinet_name, 'url' => route('cabinets.show', $c),
                    'progress' => (int) $c->progress, 'done' => $c->status === Cabinet::STATUS_COMPLETED,
                    // ห่อตู้ → แจ้งเปิดบิล → ออกจากโรงงาน → ส่งถึง, as its Checklist has them ticked (by งานจัดส่ง); none when it has no such items
                    ...$this->cabinetShipping($c->id),
                    // the delivery round it is in (its first), for the panel's table of cabinets × steps
                    ...$this->roundOf($p, $c->id),
                    'tasks' => $c->tasks->sortBy('sequence')->values()->map(fn ($t) => [
                        'name' => $t->name, 'progress' => (int) $t->progress,
                        'done' => $t->status === CabinetTask::STATUS_COMPLETED,
                        'late' => $t->subtasks->contains(fn (CabinetSubtask $s) => $s->isLateOn($this->today)),
                        'ship' => $this->stepsOf($this->taskShip[$t->id] ?? []),
                    ])->all(),
                ])->all(),
                'when' => $e['when'] ?? self::DAY_SHORT[$date->dayOfWeekIso - 1].' '.$this->short($date),
                'issues' => $issues->get($p->id, collect())->map(fn ($s) => [
                    'place' => $s['place'], 'color' => $s['color'], 'what' => $s['what'], 'who' => $s['who'], 'late' => $s['kind'] === 'late',
                    'by' => $s['deadline'] ? $s['deadline']['text'].' · '.$s['deadline']['label'] : 'เลยทุกกำหนดแล้ว',
                ])->values()->all(),
            ];
        };
        $col = fn (Carbon $d) => min(4, max(0, (int) $from->diffInDays($d)));

        // FAT rounds (blue): their own cards, each saying how the appointment stands (a delivery is said on the งานผลิต card)
        foreach ($win['events'] as $k => $e) {
            if ($e['kind'] !== 'fat') {
                continue;
            }
            $p = $byId[$e['projectId']];
            $date = $e['date'];
            $item = $card($p, [
                'id' => $e['kind'].'-'.$p->id.'-'.$k, 'kind' => $e['kind'], 'tag' => $e['kind'], 'kindName' => $kinds[$e['kind']], 'title' => $e['title'],
                'ev' => match (true) {
                    str_starts_with($e['status'], 'ตู้ยังไม่เสร็จ') => 'ตู้ยังไม่พร้อม',
                    str_contains($e['status'], 'ตู้เสร็จครบ') => 'ตู้พร้อม',
                    default => $e['status'],
                },
                'tone' => $e['tone'],
                'when' => self::DAY_SHORT[$date->dayOfWeekIso - 1].' '.$this->short($date).($e['until'] ? ' – '.$e['until'] : ''),
            ], $p->cabinets->whereIn('id', $e['cabinetIds'] ?? []), $date);
            $days[$col($date)]['items'][] = $item;
        }

        // งานผลิต: one card a delivery round, on its delivery day, of its cabinets only (workTag: its colour); a project's
        // work with no delivery date waits in รอแจ้งวันส่ง. The card says how the delivery department stands with it. This week, a card from before that is not
        // made yet waits on today.
        $end = $from->copy()->addDays(6);
        $carry = $this->today->betweenIncluded($from, $end);
        $pending = [];
        foreach ($projects as $p) {
            // รอฝ่ายขายแจ้งกำหนดส่ง: put off with no new dates yet - on no day (its old ones are not its dates any more) and
            // never late; listed apart above the week, the week of today only
            if ($p->isDuePending()) {
                if ($carry) {
                    // its state is waiting on sales, not late: the dates it is behind are not its dates any more
                    $pending[] = ['state' => 'pending', 'stateText' => self::PENDING_STATE] + $card($p, [
                        'id' => 'pending-'.$p->id, 'kind' => 'work', 'tag' => 'pending', 'kindName' => 'งานผลิต', 'title' => 'ตู้ '.$p->cabinets->count().' ตู้',
                        'ev' => self::PENDING_STATE, 'tone' => null,
                        'when' => 'เลื่อนเมื่อ '.$this->short($p->due_pending_at).' · ยังไม่มีวันส่งใหม่',
                        'ship' => 'รอวันส่งใหม่', 'shipColor' => '#64748b', 'evColor' => '#475569',
                    ], $p->cabinets, $this->today);
                }

                continue;
            }
            // a card a delivery round with its date, on its first day, of that round's cabinets only; what has no delivery
            // date (no round yet, a round with no date, cabinets in no round) waits in รอแจ้งวันส่ง (the week of today only)
            $due = $p->due_date?->copy()->startOfDay();
            $left = $due ? (int) $this->today->diffInDays($due, false) : null;
            $dueText = $due ? ' · จบโครงการ '.self::DAY_SHORT[$due->dayOfWeekIso - 1].' '.$this->short($due) : '';
            $dated = $p->deliveries->filter(fn (ProjectDelivery $d) => $d->delivery_date && ! $d->date_pending_at)->sortBy('round');
            $undated = $p->deliveries->reject(fn (ProjectDelivery $d) => $d->delivery_date && ! $d->date_pending_at)->sortBy('round');
            $inRound = $p->deliveries->flatMap(fn (ProjectDelivery $d) => $this->roundCabinets($p, $d->cabinet_ids)->pluck('id'))->unique();
            $loose = $p->cabinets->whereNotIn('id', $inRound->all())->values();

            foreach ($dated as $d) {
                $cabs = $this->roundCabinets($p, $d->cabinet_ids);
                $on = $d->firstDay()->copy()->startOfDay();
                $ship = $this->roundShip($d);
                $open = $cabs->where('status', '!=', Cabinet::STATUS_COMPLETED)->count();
                // from before this week and still to do (not made, not gone out of the factory): waits on today
                $before = ($open || $cabs->isEmpty()) && ! $ship['out'] && $carry && $on->lt($from);
                if (! $on->betweenIncluded($from, $end) && ! $before) {
                    continue;
                }
                [$tag, $ev] = $this->workTag($cabs, $ship['out']);
                $item = $card($p, [
                    'id' => 'work-'.$p->id.'-'.$d->id, 'kind' => 'work', 'tag' => $tag, 'kindName' => 'งานผลิต', 'title' => 'ส่งรอบ '.$d->round.' · ตู้ '.$cabs->count().' ตู้',
                    // the chip says where this round's work is (พร้อมส่ง, กำลังผลิต …)
                    ...$this->stageOf($p, $cabs, $left),
                    // green and pink: the chip says it (พร้อมห่อ, พร้อมส่ง, ออกจากโรงงานแล้ว); grey: its colour says on plan - no words
                    'ev' => in_array($tag, ['green', 'shipped', 'gray'], true) ? '' : $ev,
                    'tone' => ['red' => 'red', 'orange' => 'amber', 'green' => 'green'][$tag] ?? null,
                    'when' => 'ส่ง '.self::DAY_SHORT[$on->dayOfWeekIso - 1].' '.$this->short($on).$dueText,
                    'ship' => $ship['text'], 'shipColor' => $ship['color'],
                    'evColor' => $tag === 'shipped' ? '#15803d' : null,
                    // the round and its cabinets, on the card
                    'round' => (int) $d->round, 'roundCount' => $p->deliveries->count(), 'roundId' => $d->id,
                    'roundCabinets' => $cabs->sortBy('sequence')->map(fn (Cabinet $c) => (string) ($c->cabinet_name ?: $c->mo_no))->values()->all(),
                ], $cabs, $on);
                // this round's cabinets: their production, not the whole project's
                $item['made'] = (int) round($cabs->avg('progress') ?? 0);
                $item['site'] = $ship['site'];
                $item['map'] = $ship['map'];
                $days[$col($before ? $this->today : $on)]['items'][] = $item;
            }

            // no delivery date: the rounds with none, and the cabinets in no round (a project with no round: all of them)
            $waiting = $undated->flatMap(fn (ProjectDelivery $d) => $this->roundCabinets($p, $d->cabinet_ids))->merge($loose)->unique('id')->values();
            if ($carry && ($waiting->isNotEmpty() || $p->deliveries->isEmpty())) {
                $why = $p->deliveries->isEmpty() ? 'ยังไม่นัดส่ง'
                    : implode(' · ', array_filter([
                        $undated->isNotEmpty() ? 'รอบ '.$undated->pluck('round')->implode(', ').' ยังไม่ลงวันส่ง' : null,
                        $loose->isNotEmpty() ? $loose->count().' ตู้ยังไม่อยู่ในรอบส่ง' : null,
                    ]));
                $item = ['state' => 'pending', 'stateText' => self::PENDING_STATE] + $card($p, [
                    'id' => 'nodate-'.$p->id, 'kind' => 'work', 'tag' => 'pending', 'kindName' => 'งานผลิต', 'title' => 'ตู้ '.$waiting->count().' ตู้', 'roundId' => 'none',
                    'ev' => $why, 'tone' => null, 'when' => $why.$dueText,
                    'ship' => 'ยังไม่มีวันส่ง', 'shipColor' => '#64748b', 'evColor' => '#475569',
                ], $waiting, $this->today);
                $item['made'] = (int) round($waiting->avg('progress') ?? 0);
                $pending[] = $item;
            }
        }

        // a day: what needs a hand first (red, orange), then the appointments, then on plan, then made
        $order = ['red' => 0, 'orange' => 1, 'fat' => 2, 'gray' => 3, 'green' => 4, 'shipped' => 5];
        foreach ($days as &$d) {
            usort($d['items'], fn ($a, $b) => [$order[$a['tag']], $a['rank']] <=> [$order[$b['tag']], $b['rank']]);
        }
        unset($d);
        $tags = collect($days)->flatMap(fn ($d) => $d['items'])->countBy('tag')->all() + array_fill_keys(array_keys($order), 0);

        // today, when it falls in the week shown (a weekend day too, though its column is Friday's): said beside the week's name
        $today = $this->today->betweenIncluded($from, $from->copy()->addDays(6))
            ? self::DAY_NAMES[$this->today->dayOfWeekIso - 1].' '.$this->short($this->today) : null;

        return ['label' => $this->span($from, $from->copy()->addDays(4)), 'days' => $days, 'today' => $today, 'tags' => $tags, 'pending' => $pending];
    }

    /** @param list<int> $cabinetIds  each cabinet's delivery items (ห่อตู้เสร็จสิ้น …) and when they were ticked, in one query */
    private function shipSteps(array $cabinetIds): array
    {
        if (! $cabinetIds) {
            return [];
        }
        $out = [];
        CabinetChecklist::query()
            ->join('cabinet_subtasks as s', 's.id', '=', 'cabinet_checklists.cabinet_subtask_id')
            ->join('cabinet_tasks as t', 't.id', '=', 's.cabinet_task_id')
            ->whereIn('t.cabinet_id', $cabinetIds)
            ->where(fn ($q) => $q->where('cabinet_checklists.name', 'like', '%ห่อ%')->orWhere('cabinet_checklists.name', 'like', '%บิล%')->orWhere('cabinet_checklists.name', 'like', '%ส่งมอบ%'))
            ->get(['cabinet_checklists.name', 'cabinet_checklists.is_completed', 'cabinet_checklists.completed_at', 't.cabinet_id', 't.id as task_id'])
            ->each(function ($i) use (&$out) {
                if ($step = DeliveryChecklist::stepOf($i->name)) {
                    $at = $i->is_completed ? ($i->completed_at ?? $this->today) : null;
                    $out[$i->cabinet_id][$step] = $at;
                    $this->taskShip[$i->task_id][$step] = $at;
                }
            });

        return $out;
    }

    /**
     * A work card's colour and words, from its cabinets: green ('shipped') gone out of the factory or every one packed (a
     * cabinet done is built and packed, ProgressService::builtAndPacked), pink ('green') every one's Checklist done
     * (พร้อมห่อ), red a Task past its due date and not done (เกินกำหนด), orange a Task due within two days (ใกล้ครบกำหนด),
     * grey otherwise. The tags keep their old names; their colours are the dashboard's ($edges in dashboard/_pm).
     *
     * @return array{0: string, 1: string} [tag, words]
     */
    private function workTag(Collection $cabs, bool $out): array
    {
        $taskDays = $cabs->flatMap->tasks->filter(fn ($t) => $t->due_date && (int) $t->progress < 100)
            ->map(fn ($t) => (int) $this->today->diffInDays($t->due_date->copy()->startOfDay(), false));
        $packed = $cabs->isNotEmpty() && $cabs->every(fn (Cabinet $c) => $c->status === Cabinet::STATUS_COMPLETED || ($this->shipSteps[$c->id][DeliveryChecklist::PACK] ?? null) !== null);
        $made = $cabs->isNotEmpty() && $cabs->every(fn (Cabinet $c) => isset($this->made[$c->id]));

        return match (true) {
            $cabs->isNotEmpty() && $out => ['shipped', 'ออกจากโรงงานแล้ว'],
            $packed => ['shipped', 'ห่อตู้แล้ว'],
            $made => ['green', 'พร้อมห่อ'],
            $taskDays->contains(fn ($d) => $d < 0) => ['red', 'เกินกำหนด'],
            $taskDays->contains(fn ($d) => $d <= 2) => ['orange', 'ใกล้ครบกำหนด'],
            default => ['gray', 'ตามแผน'],
        };
    }

    /** One delivery round as its card says it: its state and day, gone out of the factory or not, where to. */
    private function roundShip(ProjectDelivery $d): array
    {
        $label = $d->status === ProjectDelivery::DRAFT ? 'ยังไม่ส่งให้ฝ่ายจัดส่ง'
            : $d->stateLabel().(in_array($d->status, [ProjectDelivery::WAITING, ProjectDelivery::SCHEDULED], true) && $d->firstDay()->lt($this->today) ? ' · เลยวันส่ง' : '');

        return [
            'text' => $label.' · '.self::DAY_SHORT[$d->firstDay()->dayOfWeekIso - 1].' '.$this->short($d->firstDay()),
            'color' => $d->status === ProjectDelivery::DRAFT ? '#64748b' : $d->stateColor(),
            'out' => in_array($d->status, [ProjectDelivery::DEPARTED, ProjectDelivery::DELIVERED], true),
            'site' => trim((string) $d->site) ?: null, 'map' => $d->map_url ?: null,
        ];
    }

    /** @param list<int> $cabinetIds  the cabinets whose Checklist has items and all of them ticked (the delivery steps' aside) */
    private function madeCabinets(array $cabinetIds): array
    {
        if (! $cabinetIds) {
            return [];
        }
        $open = [];
        CabinetChecklist::query()
            ->join('cabinet_subtasks as s', 's.id', '=', 'cabinet_checklists.cabinet_subtask_id')
            ->join('cabinet_tasks as t', 't.id', '=', 's.cabinet_task_id')
            ->whereIn('t.cabinet_id', $cabinetIds)
            ->get(['cabinet_checklists.name', 'cabinet_checklists.is_completed', 't.cabinet_id'])
            ->reject(fn ($i) => DeliveryChecklist::stepOf($i->name) !== null)
            ->each(function ($i) use (&$open) {
                $open[$i->cabinet_id] = ($open[$i->cabinet_id] ?? 0) + ($i->is_completed ? 0 : 1);
            });

        return array_map(fn () => true, array_filter($open, fn ($n) => $n === 0));
    }

    /**
     * Where a project's work is, over its cabinets - the furthest step every cabinet has reached, with how many when only
     * some have gone further (พร้อมส่ง 1/2) - and the time to its end date (the words beside the chip).
     */
    private function stageOf(Project $p, Collection $cabs, ?int $left): array
    {
        $of = $cabs->count();
        $step = fn (Cabinet $c, string $s) => ($this->shipSteps[$c->id][$s] ?? null) !== null;
        $there = $cabs->filter(fn ($c) => $this->roundOf($p, $c->id)['roundShip'][3]['done'] ?? false)->count();
        $out = $cabs->filter(fn ($c) => $this->roundOf($p, $c->id)['roundShip'][2]['done'] ?? false)->count();
        $ready = $cabs->filter(fn ($c) => $c->status === Cabinet::STATUS_COMPLETED || $step($c, DeliveryChecklist::PACK))->count();
        $made = $cabs->filter(fn ($c) => $c->status === Cabinet::STATUS_COMPLETED || isset($this->made[$c->id]))->count();
        $progress = (int) round($cabs->avg('progress') ?? 0);
        $of2 = fn (int $n) => $n < $of ? ' '.$n.'/'.$of : '';
        [$key, $count] = match (true) {
            ! $of => ['notstarted', ''],
            $there === $of => ['there', ''],
            $out > 0 => ['out', $of2($out)],
            $ready === $of => ['ready', ''],
            $ready > 0 => ['ready', $of2($ready)],
            $made === $of => ['made', ''],
            $progress === 0 => ['notstarted', ''],
            default => ['making', ''],
        };
        $time = in_array($key, ['out', 'there'], true) && ! $count ? '' : match (true) {
            $left === null => '',
            $left < 0 => 'เลยกำหนด '.(-$left).' วัน',
            $left === 0 => 'ครบกำหนดวันนี้',
            $left === 1 => 'ครบกำหนดพรุ่งนี้',
            default => 'เหลือ '.$left.' วัน',
        };

        return ['stage' => $key, 'kindName' => self::STAGES[$key].$count, 'ev' => $time];
    }

    /** A cabinet's delivery steps for the panel, and the furthest one done (ห่อแล้ว, ส่งถึงแล้ว …). */
    private function cabinetShipping(int $cabinetId): array
    {
        $have = $this->shipSteps[$cabinetId] ?? [];
        $stage = null;
        foreach (self::SHIP_STEPS as $step => [, $doneWord]) {
            if (($have[$step] ?? null) !== null) {
                $stage = $doneWord;
            }
        }

        // step => done and the day (null: this cabinet's Checklist has no such item)
        $map = [];
        foreach (array_keys(self::SHIP_STEPS) as $step) {
            $map[$step] = array_key_exists($step, $have) ? ['done' => $have[$step] !== null, 'at' => $have[$step]?->format('j/n')] : null;
        }

        return ['steps' => $this->stepsOf($have), 'stage' => $stage, 'packed' => ($have[DeliveryChecklist::PACK] ?? null) !== null, 'shipMap' => $map];
    }

    /**
     * The delivery round a cabinet is in (its first) and where that round stands for it, from the round itself (as
     * งานจัดส่ง records it): this cabinet packed, the round billed, gone out of the factory, arrived - with the day.
     */
    private function roundOf(Project $p, int $cabinetId): array
    {
        $d = $p->deliveries->sortBy('round')->first(fn (ProjectDelivery $d) => $this->roundCabinets($p, $d->cabinet_ids)->contains('id', $cabinetId));
        if (! $d) {
            return ['round' => null, 'roundDraft' => false, 'roundShip' => []];
        }
        $day = fn ($at) => $at ? Carbon::parse($at)->format('j/n') : null;
        $packed = $d->isPacked($cabinetId);
        $packedAt = $packed ? (($d->packed ?? [])[$cabinetId] ?? null) : null;
        $gone = in_array($d->status, [ProjectDelivery::DEPARTED, ProjectDelivery::DELIVERED], true);

        return [
            'round' => $d->round, 'roundDraft' => $d->status === ProjectDelivery::DRAFT,
            'roundShip' => [
                ['key' => 'pack', 'label' => 'ห่อ', 'done' => $packed, 'at' => is_string($packedAt) ? $day($packedAt) : null],
                ['key' => 'bill', 'label' => 'เปิดบิล', 'done' => (bool) $d->billed_at, 'at' => $day($d->billed_at)],
                ['key' => 'depart', 'label' => 'ออกรถ', 'done' => $gone, 'at' => $day($d->departed_at)],
                ['key' => 'deliver', 'label' => 'ส่งถึง', 'done' => $d->status === ProjectDelivery::DELIVERED, 'at' => $day($d->delivered_at)],
            ],
        ];
    }

    /** @param array<string, ?Carbon> $have  step => when ticked: as the panel lists them, in step order */
    private function stepsOf(array $have): array
    {
        $out = [];
        foreach (self::SHIP_STEPS as $step => [$label]) {
            if (array_key_exists($step, $have)) {
                $at = $have[$step];
                $out[] = ['label' => $label, 'done' => $at !== null, 'at' => $at ? $this->short($at).' '.$at->format('H:i') : null];
            }
        }

        return $out;
    }

    /**
     * The reason a project reads ช้ากว่าแผน / เลยวันส่ง: its late Sub Tasks and where; null otherwise (production behind the
     * time used is not said - the card shows the production % itself).
     */
    private function why(?array $row): ?string
    {
        if (! $row || ! in_array($row['state'], ['late', 'watch'], true) || $row['lateSubtasks'] <= 0) {
            return null;
        }

        return 'งานย่อยเลยกำหนด '.$row['lateSubtasks'].' งาน'.($row['lateDepartments'] ? ' ('.implode(', ', $row['lateDepartments']).')' : '');
    }

    /**
     * The panel beside the board when no card is open: how many projects run and how they stand, this week's deliveries,
     * the projects furthest behind the time used (production against plan), and the stuck work to chase first.
     */
    private function overview(Collection $rows, Collection $projects, array $week, Collection $stuck, array $board): array
    {
        $ids = $projects->modelKeys();
        $running = $rows->filter(fn ($r) => in_array($r['id'], $ids, true));
        $deliveries = collect($week['events'])->where('kind', 'delivery');

        return [
            'running' => $running->count(),
            'notStarted' => $projects->count() - $running->count(),
            'onPlan' => $running->where('state', 'ok')->count(),
            'late' => $running->where('state', 'late')->count(),
            'watch' => $running->where('state', 'watch')->count(),
            'deliveries' => $deliveries->count(),
            'delivered' => $deliveries->where('done', true)->count(),
            // furthest behind first: the time used minus what is produced
            'progress' => $running->where('state', '!=', 'close')
                ->sortByDesc(fn ($r) => ($r['timeElapsed'] ?? 0) - $r['progress'])->take(6)
                ->map(fn ($r) => ['name' => $r['name'], 'url' => $r['url'], 'made' => (int) $r['progress'], 'plan' => $r['timeElapsed'], 'state' => $r['state']])
                ->values()->all(),
            // the other projects being made: running, not on this week's board, not every cabinet delivered yet
            'others' => $this->others($running, $projects, $board),
            'urgent' => $stuck->take(6)->map(fn ($s) => [
                'project' => $s['project'], 'url' => $s['url'], 'what' => $s['what'], 'who' => str_starts_with($s['place'], 'PM') ? $s['who'] : $s['place'].' · '.$s['who'], 'color' => $s['color'],
                'by' => $s['deadline'] ? $s['deadline']['text'] : 'เร่งทันที', 'late' => $s['kind'] === 'late' || ! $s['deadline'],
            ])->values()->all(),
        ];
    }

    /**
     * The running projects with no FAT round or delivery on this week's board, leaving out those whose cabinets have all
     * been delivered: worst state first, then the soonest due date.
     */
    private function others(Collection $running, Collection $projects, array $board): array
    {
        $onBoard = collect($board['days'])->flatMap(fn ($d) => $d['items'])->merge($board['pending'])->map(fn ($i) => (int) explode('-', $i['id'])[1])->unique()->all();
        $rank = ['late' => 0, 'watch' => 1, 'ok' => 2, 'close' => 3];

        return $running->reject(fn ($r) => in_array($r['id'], $onBoard, true))
            ->filter(function ($r) use ($projects) {
                $p = $projects->find($r['id']);
                $sent = $p->deliveries->where('status', ProjectDelivery::DELIVERED)->flatMap(fn ($d) => $d->cabinet_ids ?? [])->unique();

                return $p->cabinets->isEmpty() || $p->cabinets->pluck('id')->diff($sent)->isNotEmpty();
            })
            ->sortBy(fn ($r) => [$rank[$r['state']] ?? 4, $r['dueKey']])
            ->map(fn ($r) => ['name' => $r['name'], 'url' => $r['url'], 'made' => (int) $r['progress'], 'state' => $r['state'], 'due' => $r['dueKey'] === '9999' ? '-' : $this->short(Carbon::parse($r['dueKey']))])
            ->values()->all();
    }

    /**
     * What is stuck, one line per project, place (department) and kind: work past its date, work handed out but not
     * accepted, work due soon that nobody was given, a delivery or FAT that slipped. Who holds it and the next date it
     * must be cleared by (the soonest FAT / delivery / due date ahead for its cabinets), soonest first.
     */
    private function stuck(Collection $projects, Collection $subtasks, Carbon $horizon): array
    {
        $waitLimit = $this->today->copy()->subDays(self::WAIT_DAYS);
        $rows = collect();

        foreach ($subtasks as $s) {
            $kind = match (true) {
                $s->isLateOn($this->today) => 'late',
                $s->isDone() => null,
                $s->assignment_status === CabinetSubtask::ASSIGNMENT_UNASSIGNED && $s->rejected_at !== null => 'rejected',
                $s->assignment_status === CabinetSubtask::ASSIGNMENT_ASSIGNED && $s->assigned_at && $s->assigned_at->lte($waitLimit) => 'waiting',
                $s->assignment_status === CabinetSubtask::ASSIGNMENT_UNASSIGNED && $s->due_date && $s->due_date->lte($horizon) => 'unassigned',
                default => null,
            };
            if ($kind) {
                $rows->push(['s' => $s, 'kind' => $kind]);
            }
        }

        $groups = $rows->groupBy(fn ($r) => $r['s']->cabinetTask->cabinet->project_id.'|'.$r['kind'].'|'.(int) $r['s']->department_id)
            ->map(function (Collection $list) {
                /** @var CabinetSubtask $first */
                $first = $list->first()['s'];
                $kind = $list->first()['kind'];
                $p = $first->cabinetTask->cabinet->project;
                $items = $list->pluck('s');
                $cabinets = $items->map(fn ($s) => $s->cabinetTask->cabinet)->unique('id')->values();
                $dept = $first->department;
                $toPm = in_array($kind, ['unassigned', 'rejected'], true);   // nobody holds it yet: the PM does
                $people = $items->map(fn ($s) => $s->acceptedBy?->name)->filter()->unique()->values();
                $daysLate = (int) $items->filter(fn ($s) => $s->due_date)->max(fn ($s) => $s->due_date->diffInDays($this->today));
                $waited = (int) $items->max(fn ($s) => $s->assigned_at?->diffInDays($this->today) ?? 0);
                $ownDue = $items->pluck('due_date')->filter()->min();
                $deadline = $this->deadline($cabinets, $p, in_array($kind, ['late'], true) ? null : $ownDue);

                return [
                    'projectId' => $p->id, 'project' => $p->project_name, 'no' => $p->project_no, 'url' => route('projects.show', $p),
                    'kind' => $kind,
                    'what' => match ($kind) {
                        'late' => 'งานเลยกำหนด '.$items->count().' งาน · เลยมา '.$daysLate.' วัน',
                        'waiting' => 'จ่ายงานแล้ว ยังไม่มีคนรับ '.$items->count().' งาน · รอมา '.$waited.' วัน',
                        'unassigned' => 'ยังไม่จ่ายงาน '.$items->count().' งาน · ครบกำหนด '.$this->short($ownDue),
                        'rejected' => ($first->rejectedDepartment?->name ?? 'แผนก').' ไม่รับงาน '.$items->count().' งาน',
                    },
                    'tasks' => $items->pluck('name')->unique()->take(2)->implode(', ').($items->pluck('name')->unique()->count() > 2 ? ' และอีก '.($items->pluck('name')->unique()->count() - 2).' งาน' : ''),
                    'cabinets' => $cabinets->take(2)->map(fn ($c) => ['mo' => $c->mo_no ?: $c->cabinet_name, 'url' => route('cabinets.show', $c)])->all(),
                    'moreCabinets' => max(0, $cabinets->count() - 2),
                    'place' => $toPm ? 'PM (ยังไม่จ่ายงาน)' : ($dept?->name ?? 'ยังไม่จ่ายงาน'),
                    'color' => $toPm || ! $dept ? '#94a3b8' : ($this->colors[$dept->id] ?? '#94a3b8'),
                    'who' => $toPm ? ($p->projectManager?->name ? 'PM '.$p->projectManager->name : 'PM ของโครงการ (ยังไม่ได้ระบุ)')
                        : ($people->isNotEmpty() ? $people->take(2)->implode(', ').($people->count() > 2 ? ' +'.($people->count() - 2) : '') : 'ยังไม่มีคนรับงาน'),
                    'pm' => $p->projectManager?->name,
                    'deadline' => $deadline,
                    'n' => $items->count(),
                    'severity' => ['late' => 0, 'rejected' => 1, 'waiting' => 2, 'unassigned' => 3][$kind],
                    'days' => $kind === 'late' ? $daysLate : $waited,
                ];
            });

        foreach ($projects as $p) {
            foreach ($this->eventIssues($p) as $issue) {
                $groups->push($issue);
            }
        }

        return $groups->sortBy(fn ($g) => [$g['deadline'] ? $g['deadline']['date']->format('Ymd') : '00000000', $g['severity'], -$g['days']])->values()->all();
    }

    /** A FAT or a delivery that slipped: still without a result after its days, failed with no new round, sent back. */
    private function eventIssues(Project $p): array
    {
        $out = [];
        $base = fn (string $what, string $place, string $kind, ?array $deadline) => [
            'projectId' => $p->id, 'project' => $p->project_name, 'no' => $p->project_no, 'url' => route('projects.show', $p),
            'kind' => $kind, 'what' => $what, 'tasks' => '', 'cabinets' => [], 'moreCabinets' => 0,
            'place' => $place, 'color' => '#94a3b8',
            'who' => $p->projectManager?->name ? 'PM '.$p->projectManager->name : 'PM ของโครงการ (ยังไม่ได้ระบุ)', 'pm' => $p->projectManager?->name,
            'deadline' => $deadline, 'n' => 1, 'severity' => 1, 'days' => 0,
        ];
        $projectDue = $p->due_date && $p->due_date->gte($this->today) ? ['date' => $p->due_date->copy(), 'label' => 'กำหนดส่งโครงการ', 'text' => $this->dueText($p->due_date)] : null;

        $latest = $p->fats->sortByDesc('round')->first();
        foreach ($p->fats->where('status', ProjectFat::SCHEDULED) as $f) {
            if ($f->lastDay()->lt($this->today) && ! $f->date_pending_at) {
                $out[] = $base('FAT รอบ '.$f->round.' ('.$this->short($f->fat_date).') ผ่านไปแล้ว ยังไม่บันทึกผล', 'PM (FAT)', 'fat', ['date' => $this->today->copy(), 'label' => 'บันทึกผล', 'text' => 'วันนี้']);
            }
        }
        if ($latest && $latest->status === ProjectFat::FAILED) {
            $out[] = $base('FAT รอบ '.$latest->round.' ไม่ผ่าน · ยังไม่นัดรอบใหม่', 'PM (FAT)', 'fat', $projectDue);
        }
        foreach ($p->deliveries as $d) {
            if ($d->status === ProjectDelivery::REJECTED) {
                $out[] = $base('ฝ่ายจัดส่งตีกลับ ส่งมอบรอบ '.$d->round.($d->rejected_reason ? ' · '.$d->rejected_reason : ''), 'PM (การจัดส่ง)', 'delivery',
                    ['date' => $d->firstDay()->copy()->max($this->today), 'label' => 'ส่งมอบรอบ '.$d->round, 'text' => $this->dueText($d->firstDay()->copy()->max($this->today))]);
            } elseif (in_array($d->status, [ProjectDelivery::DRAFT, ProjectDelivery::WAITING, ProjectDelivery::SCHEDULED], true) && $d->firstDay()->lt($this->today) && ! $d->date_pending_at) {
                $out[] = $base('ส่งมอบรอบ '.$d->round.' ('.$this->short($d->firstDay()).') เลยวันแล้ว ยังไม่ออกจากโรงงาน', 'ฝ่ายจัดส่ง', 'delivery',
                    ['date' => $this->today->copy(), 'label' => 'ส่งมอบ', 'text' => 'วันนี้']);
            }
        }

        return $out;
    }

    /**
     * The soonest date ahead that the work must be clear by: its cabinets' next FAT (production done before it), next
     * delivery, the cabinet's or project's due date - and, for work not late yet, its own due date. null = every date has passed.
     */
    private function deadline(Collection $cabinets, Project $p, ?Carbon $ownDue): ?array
    {
        $ids = $cabinets->pluck('id')->all();
        $in = fn ($list) => ! $list || array_intersect($ids, array_map('intval', (array) $list));
        $options = collect();
        foreach ($p->fats->where('status', ProjectFat::SCHEDULED) as $f) {
            if ($f->fat_date->gte($this->today) && $in($f->cabinet_ids)) {
                $options->push(['date' => $f->fat_date, 'label' => 'FAT รอบ '.$f->round]);
            }
        }
        foreach ($p->deliveries->whereNotIn('status', [ProjectDelivery::DEPARTED, ProjectDelivery::DELIVERED]) as $d) {
            if ($d->firstDay()->gte($this->today) && $in($d->cabinet_ids)) {
                $options->push(['date' => $d->firstDay(), 'label' => 'ส่งมอบรอบ '.$d->round]);
            }
        }
        foreach ($cabinets as $c) {
            if ($c->due_date && $c->due_date->gte($this->today)) {
                $options->push(['date' => $c->due_date, 'label' => 'กำหนดตู้ '.($c->mo_no ?: $c->cabinet_name)]);
            }
        }
        if ($p->due_date && $p->due_date->gte($this->today)) {
            $options->push(['date' => $p->due_date, 'label' => 'กำหนดส่งโครงการ']);
        }
        if ($ownDue && $ownDue->gte($this->today)) {
            $options->push(['date' => $ownDue, 'label' => 'กำหนดงาน']);
        }
        $first = $options->sortBy(fn ($o) => $o['date']->format('Ymd'))->first();

        return $first ? ['date' => $first['date']->copy(), 'label' => $first['label'], 'text' => $this->dueText($first['date'])] : null;
    }

    /** A FAT / delivery's cabinets (an empty list = every cabinet of the project). */
    /**
     * How the delivery department stands with these cabinets: the round still to go first (soonest), or the last one gone;
     * "out" once every one of them left the factory (ออกจากโรงงานแล้ว / ส่งถึงแล้ว). The words and colour are the
     * งานจัดส่ง page's own; a round PM has not sent yet has not reached them.
     *
     * @return array{text: string, color: string, out: bool, site: ?string, map: ?string}
     */
    private function shipping(Project $p, Collection $cabs): array
    {
        $ids = $cabs->pluck('id');
        $gone = [ProjectDelivery::DEPARTED, ProjectDelivery::DELIVERED];
        $rounds = $p->deliveries->filter(fn (ProjectDelivery $d) => $this->roundCabinets($p, $d->cabinet_ids)->pluck('id')->intersect($ids)->isNotEmpty())
            ->sortBy(fn (ProjectDelivery $d) => $d->firstDay()->format('Ymd'));
        if ($rounds->isEmpty()) {
            return ['text' => 'ยังไม่นัดส่ง', 'color' => '#64748b', 'out' => false, 'site' => null, 'map' => null];
        }
        $sent = $rounds->whereIn('status', $gone)->flatMap(fn ($d) => $this->roundCabinets($p, $d->cabinet_ids)->pluck('id'));
        $d = $rounds->first(fn ($d) => ! in_array($d->status, $gone, true)) ?? $rounds->last();
        $label = $d->status === ProjectDelivery::DRAFT ? 'ยังไม่ส่งให้ฝ่ายจัดส่ง'
            : $d->stateLabel().(in_array($d->status, [ProjectDelivery::WAITING, ProjectDelivery::SCHEDULED], true) && $d->firstDay()->lt($this->today) ? ' · เลยวันส่ง' : '');

        return [
            'text' => $label.' · '.self::DAY_SHORT[$d->firstDay()->dayOfWeekIso - 1].' '.$this->short($d->firstDay()),
            'color' => $d->status === ProjectDelivery::DRAFT ? '#64748b' : $d->stateColor(),
            'out' => $ids->diff($sent)->isEmpty(),
            'site' => trim((string) $d->site) ?: null, 'map' => $d->map_url ?: null,
        ];
    }

    private function roundCabinets(Project $p, $ids): Collection
    {
        $ids = array_map('intval', (array) $ids);

        return $ids ? $p->cabinets->whereIn('id', $ids)->values() : $p->cabinets;
    }

    /** Are a round's cabinets ready: every one finished (green), or how many are not, red when it is two days away or less. */
    private function readiness(Collection $cabinets, Carbon $date, string $ready = 'ตู้พร้อม'): array
    {
        $open = $cabinets->where('status', '!=', Cabinet::STATUS_COMPLETED)->count();
        if ($cabinets->isNotEmpty() && $open === 0) {
            return [$ready.' · ตู้เสร็จครบ', 'green', false];
        }

        return ['ตู้ยังไม่เสร็จ '.$open.'/'.$cabinets->count(), $date->lte($this->today->copy()->addDays(2)) ? 'red' : 'amber', false];
    }

    private function overlaps(Carbon $start, Carbon $end, Carbon $from, Carbon $to): bool
    {
        return $start->lte($to) && $end->gte($from);
    }

    private function dueText(Carbon $date): string
    {
        $days = (int) $this->today->diffInDays($date, false);

        return $this->short($date).' · '.match (true) {
            $days === 0 => 'วันนี้',
            $days === 1 => 'พรุ่งนี้',
            default => 'อีก '.$days.' วัน',
        };
    }

    private function short(?Carbon $d): string
    {
        return $d ? $d->day.' '.self::MONTHS[$d->month - 1] : '-';
    }

    private function span(Carbon $from, Carbon $to): string
    {
        return $from->month === $to->month
            ? $from->day.'–'.$to->day.' '.self::MONTHS[$to->month - 1].' '.($to->year + 543)
            : $this->short($from).' – '.$this->short($to).' '.($to->year + 543);
    }
}
