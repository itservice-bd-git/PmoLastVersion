<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * "ความคืบหน้าสัปดาห์นี้" on the ภาพรวมผู้บริหาร tab: every project on the week's board (PmWeek, the สัปดาห์นี้ tab)
 * and how much of it was made during the week - its production % on Monday 00:00 against now (or the week's end),
 * the Checklist items ticked, the Sub Tasks and cabinets finished, and the departments that did the work.
 *
 * Production % at a moment is worked out again from the Checklist's tick times, by ProgressService's own rollup
 * (Checklist → Sub Task → Task → Cabinet, a built and packed cabinet = 100%). An item ticked then unticked later counts
 * as not done; a Sub Task without a Checklist keeps its % unless it was finished in the week.
 */
class WeekProgress
{
    private const DAY_SHORT = ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.'];

    private const MONTHS = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];

    public function __construct(private PmWeek $pmWeek) {}

    /** @param int $offset 0 = this week, -1 = last week (no later: a week to come has done nothing yet) */
    public function build(User $user, int $offset = 0, ?Carbon $now = null): array
    {
        $now ??= now();
        $offset = max(-8, min(0, $offset));
        $from = $now->copy()->startOfDay()->startOfWeek(Carbon::MONDAY)->addWeeks($offset);
        $end = $from->copy()->addWeek();
        $until = $now->lt($end) ? $now->copy() : $end;

        // the projects on that week's board, one entry each (its งานผลิต card when it has one, else its FAT card)
        $board = $this->pmWeek->build($user, false, $now, $offset)['board'];
        $cards = collect($board['days'])->flatMap(fn ($d) => $d['items'])->merge($board['pending'])
            ->sortBy(fn ($c) => $c['kind'] === 'work' ? 0 : 1)->unique('projectId')->keyBy('projectId');

        $projects = Project::whereIn('id', $cards->keys())
            ->with(['projectManager:id,name', 'cabinets.tasks.subtasks.department:id,name',
                'cabinets.tasks.subtasks.checklists:id,cabinet_subtask_id,name,is_completed,completed_at'])
            ->get();

        $rows = $projects->map(function (Project $p) use ($cards, $from, $until) {
            $card = $cards[$p->id];
            $cabs = $p->cabinets;
            $items = $cabs->flatMap->tasks->flatMap(fn ($t) => $t->subtasks->flatMap(fn (CabinetSubtask $s) => $s->checklists->each->setRelation('subtask', $s)));
            $ticked = $items->filter(fn (CabinetChecklist $c) => $c->is_completed && $c->completed_at && $c->completed_at->gte($from) && $c->completed_at->lt($until));
            $before = $cabs->mapWithKeys(fn (Cabinet $c) => [$c->id => $this->cabinetAt($c, $from)]);
            $after = $cabs->mapWithKeys(fn (Cabinet $c) => [$c->id => $this->cabinetAt($c, $until)]);
            $start = $cabs->isEmpty() ? 0 : (int) round($before->avg());
            $now = $cabs->isEmpty() ? 0 : (int) round($after->avg());

            return [
                'id' => $p->id, 'no' => $p->project_no, 'name' => $p->project_name, 'url' => route('projects.show', $p),
                'pm' => $p->projectManager?->name, 'jobType' => $card['jobType'] ?? null,
                'tag' => $card['tag'], 'ev' => $card['ev'], 'when' => $card['when'],
                'start' => $start, 'now' => $now, 'added' => max(0, $now - $start),
                'ticks' => $ticked->count(),
                'subtasksDone' => $cabs->flatMap->tasks->flatMap->subtasks
                    ->filter(fn (CabinetSubtask $s) => $s->isDone() && $s->completed_at && $s->completed_at->gte($from) && $s->completed_at->lt($until))->count(),
                'cabinetsDone' => $cabs->filter(fn (Cabinet $c) => $before[$c->id] < 100 && $after[$c->id] >= 100)->count(),
                'cabinets' => $cabs->count(), 'cabinetsMade' => $after->filter(fn ($v) => $v >= 100)->count(),
                'departments' => $ticked->groupBy(fn ($c) => $c->subtask->department?->name ?? 'ยังไม่จ่ายงาน')->map->count()->sortDesc()->take(3)->all(),
                // opened in place on the row: each cabinet, what was done in it this week (by Task) and what is left
                'cabinetList' => $cabs->sortBy('sequence')->values()->map(function (Cabinet $c) use ($before, $after, $ticked) {
                    $mine = fn ($t) => $t->subtasks->flatMap->checklists;

                    return [
                        'mo' => $c->mo_no ?: '-', 'name' => $c->cabinet_name, 'start' => $before[$c->id], 'now' => $after[$c->id],
                        'done' => $c->tasks->sortBy('sequence')->mapWithKeys(fn ($t) => [$t->name => $mine($t)->intersect($ticked)->count()])->filter()->all(),
                        'left' => $c->tasks->sortBy('sequence')->mapWithKeys(fn ($t) => [$t->name => $mine($t)->where('is_completed', false)->count()])->filter()->all(),
                    ];
                })->all(),
            ];
        })->sortBy(fn ($r) => [-$r['added'], -$r['ticks'], $r['no']])->values();

        $moved = $rows->filter(fn ($r) => $r['added'] > 0 || $r['ticks'] > 0);
        $last = $until->copy()->subSecond();

        // the week in one sentence, and the projects that did not move by name
        $avg = $moved->isEmpty() ? 0 : (int) round($moved->avg('added'));
        $sentence = $rows->isEmpty() ? null : ($moved->isEmpty()
            ? 'ยังไม่มีโครงการใดมีความคืบหน้าในช่วงนี้'
            : $moved->count().' จาก '.$rows->count().' โครงการมีความคืบหน้า ผลิตเพิ่มเฉลี่ย +'.$avg.'%'
                .($rows->sum('cabinetsDone') ? ' ตู้ผลิตเสร็จ '.$rows->sum('cabinetsDone').' ตู้' : ''));

        return [
            'offset' => $offset,
            'sentence' => $sentence,
            'still' => $rows->reject(fn ($r) => $r['added'] > 0 || $r['ticks'] > 0)->map(fn ($r) => $r['no'])->values()->all(),
            'label' => $this->short($from).' – '.$this->short($from->copy()->addDays(6)),
            'span' => $offset === 0 ? 'จ. '.$this->short($from).' ถึงวันนี้ '.self::DAY_SHORT[$last->dayOfWeekIso - 1].' '.$this->short($last).' '.$now->format('H:i').' น.' : 'ทั้งสัปดาห์ จ. – อา.',
            'rows' => $rows->all(),
            'totals' => [
                'projects' => $rows->count(),
                'moved' => $moved->count(),
                'still' => $rows->count() - $moved->count(),
                'ticks' => $rows->sum('ticks'),
                'subtasks' => $rows->sum('subtasksDone'),
                'cabinets' => $rows->sum('cabinetsDone'),
                'avgAdded' => $avg,
            ],
        ];
    }

    /** A cabinet's production % at $at, by ProgressService's rollup over what was ticked before it. */
    private function cabinetAt(Cabinet $c, Carbon $at): int
    {
        $doneBy = fn (CabinetChecklist $i) => $i->is_completed && (! $i->completed_at || $i->completed_at->lt($at));
        $tasks = $c->tasks->map(function ($t) use ($at, $doneBy) {
            $subs = $t->subtasks->map(function (CabinetSubtask $s) use ($at, $doneBy) {
                if ($s->checklists->isNotEmpty()) {
                    return (int) round($s->checklists->filter($doneBy)->count() / $s->checklists->count() * 100);
                }

                return $s->completed_at && $s->completed_at->gte($at) ? 0 : (int) $s->progress;
            });

            return $subs->isEmpty() ? 0 : (int) round($subs->avg());
        });
        $progress = $tasks->isEmpty() ? 0 : (int) round($tasks->avg());

        // built and packed (ProgressService::builtAndPacked): every item ticked but the delivery steps after packing
        $items = $c->tasks->flatMap->subtasks->flatMap->checklists->map(fn ($i) => [DeliveryChecklist::stepOf($i->name), $doneBy($i)]);
        $packed = $items->first(fn ($i) => $i[0] === DeliveryChecklist::PACK);
        if ($progress < 100 && $packed && $packed[1]
            && $items->every(fn ($i) => $i[1] || in_array($i[0], [DeliveryChecklist::BILL, DeliveryChecklist::DEPART, DeliveryChecklist::DELIVER], true))) {
            $progress = 100;
        }

        return $progress;
    }

    private function short(Carbon $d): string
    {
        return $d->day.' '.self::MONTHS[$d->month - 1];
    }
}
