<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "สรุปงานรายโครงการ": each project's production % over time, against its plan. Nothing stores % by day, so it is worked
 * out again from when each Checklist item was ticked, by ProgressService's own rule: a Sub Task = ticked / items (one
 * without a Checklist: 0 until it was done), a Task = the average of its Sub Tasks, a cabinet = the average of its Tasks
 * (or 100 once built and packed), the project = the average of its cabinets. Today's point is the project's real
 * production_progress, so the line ends on the number the card shows. The cabinets and items are today's: work added
 * later counts as not done on the days before it came.
 *
 * Plan = a straight line from the start (start_date, else the day the project was made) to 100% on the due date.
 */
class ProductionCurve
{
    /** at most this many points per line, evenly over the days, today the last */
    public const POINTS = 24;

    /** the bars under the line (Checklist items ticked per day): at most this many - a long project's days are put together */
    public const BARS = 28;

    /**
     * @param  Collection<int, Project>  $projects  with production_progress (withAvg cabinets progress)
     * @param  Carbon|null  $since  also work out how much the % rose since this moment ('gain': now minus then)
     * @return array<int, array> project id => the curve (see curve())
     */
    public function build(Collection $projects, Carbon $today, ?Carbon $since = null): array
    {
        if ($projects->isEmpty()) {
            return [];
        }
        $today = $today->copy()->startOfDay();
        $cabinets = Cabinet::query()->whereIn('project_id', $projects->modelKeys())->get(['id', 'project_id'])->groupBy('project_id');
        $tasks = CabinetTask::query()->whereIn('cabinet_id', $cabinets->flatten()->pluck('id'))->get(['id', 'cabinet_id'])->groupBy('cabinet_id');
        $subtasks = CabinetSubtask::query()->whereIn('cabinet_task_id', $tasks->flatten()->pluck('id'))
            ->get(['id', 'cabinet_task_id', 'completed_at', 'completed_date'])->groupBy('cabinet_task_id');
        $items = CabinetChecklist::query()->whereIn('cabinet_subtask_id', $subtasks->flatten()->pluck('id'))
            ->get(['cabinet_subtask_id', 'name', 'is_completed', 'completed_at'])
            ->map(fn ($item) => [
                'subtask' => $item->cabinet_subtask_id,
                'step' => DeliveryChecklist::stepOf($item->name),
                // ticked: when (an old tick with no time counts from the start)
                'at' => $item->is_completed ? ($item->completed_at?->getTimestamp() ?? 0) : null,
            ])->groupBy('subtask');

        $curves = [];
        foreach ($projects as $project) {
            $projectCabinets = $cabinets->get($project->id, collect());
            $curves[$project->id] = $this->curve($project, $projectCabinets, $tasks, $subtasks, $items, $today)
                + ['gain' => $since ? (int) $project->production_progress - $this->progressOn($since->getTimestamp(), $projectCabinets, $tasks, $subtasks, $items) : null];
        }

        return $curves;
    }

    /**
     * Days are counted from the start. The x axis runs from the start to the later of today and the due date.
     *
     * @return array{start: string, startLabel: string, endLabel: string, due: ?string, axisDays: int, todayDay: int, dueDay: ?int, actual: list<array{0: int, 1: int}>, planToday: ?int, gap: ?int, bars: list<int>, barDays: int, gain?: ?int}
     */
    private function curve(Project $project, Collection $cabinets, Collection $tasks, Collection $subtasks, Collection $items, Carbon $today): array
    {
        $start = ($project->start_date ?? $project->created_at ?? $today)->copy()->startOfDay()->min($today);
        $due = $project->due_date && ! $project->isDuePending() ? $project->due_date->copy()->startOfDay() : null;
        $todayDay = (int) $start->diffInDays($today);
        $dueDay = $due ? (int) $start->diffInDays($due, false) : null;

        $days = $todayDay === 0 ? [0] : array_values(array_unique(array_map(
            fn ($i) => (int) round($i * $todayDay / (self::POINTS - 1)), range(0, self::POINTS - 1))));
        $values = $this->progressAt(array_map(fn ($day) => $start->copy()->addDays($day)->endOfDay()->getTimestamp(), $days), $cabinets, $tasks, $subtasks, $items);
        $actual = array_map(fn ($day, $value) => [$day, $day === $todayDay ? (int) $project->production_progress : $value], $days, $values);

        $planToday = $dueDay === null ? null : ($dueDay <= 0 ? 100 : (int) round(min(1, $todayDay / $dueDay) * 100));

        $axisEnd = $due && $due->gt($today) ? $due : $today;
        [$bars, $barDays] = $this->bars($start, $todayDay, $cabinets, $tasks, $subtasks, $items);

        return [
            'start' => $start->format('Y-m-d'),
            'startLabel' => $this->dayAndMonth($start),
            'endLabel' => $axisEnd->equalTo($today) ? 'วันนี้' : 'ส่ง '.$this->dayAndMonth($axisEnd),
            'due' => $due?->format('Y-m-d'),
            'axisDays' => max(1, $todayDay, (int) $dueDay),
            'todayDay' => $todayDay,
            'dueDay' => $dueDay,
            'actual' => $actual,
            'planToday' => $planToday,
            'gap' => $planToday === null ? null : (int) $project->production_progress - $planToday,   // < 0: behind the plan
            'bars' => $bars,         // items ticked per bar, from the start to today
            'barDays' => $barDays,   // days a bar covers (1 = a day each)
        ];
    }

    /**
     * Checklist items ticked (still ticked now) per bar, from the start to today: a bar a day, or $barDays days a bar so there
     * are at most BARS. A tick before the start, or with no time, counts on the first bar.
     *
     * @return array{0: list<int>, 1: int}
     */
    private function bars(Carbon $start, int $todayDay, Collection $cabinets, Collection $tasks, Collection $subtasks, Collection $items): array
    {
        $days = $todayDay + 1;
        $barDays = max(1, (int) ceil($days / self::BARS));
        $bars = array_fill(0, (int) ceil($days / $barDays), 0);
        $from = $start->getTimestamp();
        foreach ($cabinets as $cabinet) {
            foreach ($tasks->get($cabinet->id, collect()) as $task) {
                foreach ($subtasks->get($task->id, collect()) as $subtask) {
                    foreach ($items->get($subtask->id, collect()) as $item) {
                        if ($item['at'] === null) {
                            continue;
                        }
                        $day = max(0, intdiv(max(0, $item['at'] - $from), 86400));
                        $bars[min(count($bars) - 1, intdiv($day, $barDays))]++;
                    }
                }
            }
        }

        return [$bars, $barDays];
    }

    private function dayAndMonth(Carbon $date): string
    {
        return $date->day.' '.['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'][$date->month - 1];
    }

    /** the project's production % at the end of a moment (unix time), by ProgressService's rule */
    private function progressOn(int $at, Collection $cabinets, Collection $tasks, Collection $subtasks, Collection $items): int
    {
        return $this->progressAt([$at], $cabinets, $tasks, $subtasks, $items)[0];
    }

    /**
     * The project's production % at the end of each moment (unix times), by ProgressService's rule - its items gone
     * through once for all the moments, not once for each.
     *
     * @param  list<int>  $moments
     * @return list<int> the % at each moment, in their order
     */
    private function progressAt(array $moments, Collection $cabinets, Collection $tasks, Collection $subtasks, Collection $items): array
    {
        $count = count($moments);
        $afterPacking = [DeliveryChecklist::BILL, DeliveryChecklist::DEPART, DeliveryChecklist::DELIVER];
        // how many of these sorted times are at or before each moment
        $upTo = function (array $times) use ($moments): array {
            sort($times);

            return array_map(function (int $at) use ($times) {
                $n = 0;
                foreach ($times as $time) {
                    if ($time > $at) {
                        break;
                    }
                    $n++;
                }

                return $n;
            }, $moments);
        };
        $average = fn (array $rows) => $rows === [] ? array_fill(0, $count, 0)
            : array_map(fn ($i) => (int) round(array_sum(array_column($rows, $i)) / count($rows)), range(0, $count - 1));

        $cabinetValues = [];
        foreach ($cabinets as $cabinet) {
            $packAt = false;      // the first packing item's tick (null: not ticked; false: none)
            $requiredAt = 0;      // the latest tick of the items that must be ticked to count as built and packed (null: one is not)
            $taskValues = [];
            foreach ($tasks->get($cabinet->id, collect()) as $task) {
                $subtaskValues = [];
                foreach ($subtasks->get($task->id, collect()) as $subtask) {
                    $list = $items->get($subtask->id, collect());
                    if ($list->isEmpty()) {   // no Checklist: done or not
                        $doneAt = ($subtask->completed_at ?? $subtask->completed_date)?->getTimestamp();
                        $subtaskValues[] = array_map(fn ($at) => $doneAt !== null && $doneAt <= $at ? 100 : 0, $moments);

                        continue;
                    }
                    foreach ($list as $item) {
                        if ($item['step'] === DeliveryChecklist::PACK && $packAt === false) {
                            $packAt = $item['at'];
                        }
                        if (! in_array($item['step'], $afterPacking, true) && $requiredAt !== null) {
                            $requiredAt = $item['at'] === null ? null : max($requiredAt, $item['at']);
                        }
                    }
                    $ticks = $upTo($list->pluck('at')->filter(fn ($at) => $at !== null)->values()->all());
                    $total = $list->count();
                    $subtaskValues[] = array_map(fn ($n) => (int) round($n / $total * 100), $ticks);
                }
                $taskValues[] = $average($subtaskValues);
            }
            $values = $average($taskValues);
            // built and packed (ProgressService::builtAndPacked): the delivery steps after packing don't count
            foreach ($moments as $i => $at) {
                if ($values[$i] < 100 && is_int($packAt) && $packAt <= $at && $requiredAt !== null && $requiredAt <= $at) {
                    $values[$i] = 100;
                }
            }
            $cabinetValues[] = $values;
        }

        return $average($cabinetValues);
    }
}
