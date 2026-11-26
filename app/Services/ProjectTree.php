<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\ProjectFat;
use Illuminate\Support\Carbon;

/**
 * "สรุปงานรายโครงการ"'s panel: one project as its levels - ตู้ → Task → Sub Task → Checklist - each with its % (the
 * stored progress, ProgressService's) and nothing to compare it with. Asked for when the panel opens a project
 * (DailyWorkController::projectTree), not with the page's numbers, so the page stays light.
 * A cabinet also says where its delivery and its FAT stand, in a word: the latest round it is in (a round listing no
 * cabinets is every cabinet's, as ProjectFat::latestFor reads it).
 */
class ProjectTree
{
    /** @return list<array> the cabinets, each with its tasks, their sub tasks and their checklist items */
    public function build(Project $project): array
    {
        $cabinets = Cabinet::query()->where('project_id', $project->id)->orderBy('sequence')->orderBy('id')
            ->with([
                'tasks:id,cabinet_id,name,progress,due_date,sequence',
                'tasks.subtasks:id,cabinet_task_id,name,progress,status,assignment_status,department_id,due_date,sequence',
                'tasks.subtasks.department:id,name',
                'tasks.subtasks.checklists:id,cabinet_subtask_id,name,is_completed,sequence',
            ])
            ->get(['id', 'project_id', 'mo_no', 'cabinet_name', 'quantity', 'progress', 'due_date', 'sequence']);
        $today = today();
        $deliveries = ProjectDelivery::query()->where('project_id', $project->id)->orderBy('round')->get(['id', 'round', 'status', 'method', 'cabinet_ids', 'delivery_date', 'delivery_end_date', 'delivery_time', 'date_pending_at']);
        $fats = ProjectFat::query()->where('project_id', $project->id)->orderBy('round')
            ->get(['id', 'project_id', 'round', 'status', 'fat_date', 'fat_end_date', 'date_pending_at', 'cabinet_ids']);

        return $cabinets->map(fn (Cabinet $cabinet) => $this->result((int) $cabinet->progress, $cabinet->due_date, $today) + [
            'id' => $cabinet->id,
            'name' => (string) $cabinet->cabinet_name,
            'moNo' => (string) $cabinet->mo_no,
            'quantity' => max(1, (int) $cabinet->quantity),
            'progress' => (int) $cabinet->progress,
            'url' => route('cabinets.show', $cabinet->id),
            'delivery' => $this->deliveryOf($deliveries, $cabinet->id),
            'deliveryChip' => $this->deliveryChipOf($deliveries, $cabinet->id),
            'fat' => $this->fatOf($fats, $cabinet->id),
            'tasks' => $cabinet->tasks->map(fn ($task) => $this->result((int) $task->progress, $task->due_date, $today) + [
                'id' => $task->id,
                'name' => (string) $task->name,
                'progress' => (int) $task->progress,
                'subtasks' => $task->subtasks->map(fn ($subtask) => $this->result($subtask->isDone() ? 100 : (int) $subtask->progress, $subtask->due_date, $today) + [
                    'id' => $subtask->id,
                    'name' => (string) $subtask->name,
                    'progress' => $subtask->isDone() ? 100 : (int) $subtask->progress,
                    'department' => (string) $subtask->department?->name,
                    'checklist' => $subtask->checklists->map(fn ($item) => ['id' => $item->id, 'name' => (string) $item->name, 'done' => (bool) $item->is_completed])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
        ])->values()->all();
    }

    /**
     * The panel's "ข้อมูลโครงการ": who it is for and who runs it, its dates, its cabinets, and where its next delivery and
     * its FAT stand - as [label, value] lines, the empty ones left out. Not its money (that needs its own permission).
     *
     * @return list<array{0: string, 1: string}>
     */
    public function info(Project $project): array
    {
        $project->loadMissing(['projectManager:id,name', 'salesPerson:id,name,team_id', 'salesPerson.team:id,name', 'jobType:id,name']);
        $cabinets = Cabinet::query()->where('project_id', $project->id)->get(['id', 'quantity']);
        $deliveries = ProjectDelivery::query()->where('project_id', $project->id)->orderBy('round')
            ->get(['id', 'round', 'status', 'method', 'delivery_date', 'delivery_end_date', 'date_pending_at', 'site']);
        $fat = ProjectFat::query()->where('project_id', $project->id)->orderByDesc('round')
            ->first(['id', 'project_id', 'round', 'status', 'fat_date', 'fat_end_date', 'date_pending_at']);
        $date = fn ($day) => $day ? $day->thaiDate() : null;

        $sales = $project->salesPerson?->name ?: $project->sales_person;
        $team = $project->salesPerson?->team?->name;

        $lines = [
            ['ลูกค้า', $project->customer_name],
            ['ผู้จัดการโครงการ', $project->projectManager?->name],
            ['เซล', $sales ? $sales.($team ? ' · '.$team : '') : null],
            ['ประเภทงาน', $project->jobType?->name],
            ['เลข PO', $project->po_no],
            ['เลข AVT', $project->avt_no],
            ['ระยะเวลา', ($date($project->start_date) ?? '—').' → '.($project->isDuePending() ? Project::PENDING_LABEL : ($date($project->due_date) ?? '—'))],
            ['ตู้', $cabinets->isEmpty() ? null : $cabinets->count().' รายการ · '.$cabinets->sum(fn ($c) => max(1, (int) $c->quantity)).' ตู้'],
            ['FAT', $fat ? 'รอบ '.$fat->round.' · '.($fat->date_pending_at || ! $fat->fat_date ? 'ยังไม่ลงวัน' : $fat->dateRange()).' · '.$fat->statusLabel() : null],
        ];

        return array_values(array_filter($lines, fn ($line) => $line[1] !== null && trim((string) $line[1]) !== ''));
    }

    /**
     * The panel's "การจัดส่ง": every delivery round in order - when, its state as งานจัดส่ง says it (name and colour), how it
     * goes, its cabinets - and its four steps after the vehicles (ห่อ, เปิดบิล, ออกจากโรงงาน, ส่งถึง) with their days; on top,
     * how many of the project's cabinets have arrived (a round listing none is all of them).
     *
     * @return array{arrived: int, cabinets: int, rounds: list<array>}
     */
    public function deliveries(Project $project): array
    {
        $cabinetNames = Cabinet::query()->where('project_id', $project->id)->orderBy('sequence')->orderBy('id')->get(['id', 'cabinet_name', 'mo_no'])
            ->mapWithKeys(fn ($c) => [(int) $c->id => (string) ($c->cabinet_name ?: $c->mo_no)]);
        $cabinetIds = $cabinetNames->keys()->all();
        $rounds = ProjectDelivery::query()->where('project_id', $project->id)->orderBy('round')->get();
        $arrivedIds = [];
        $day = fn ($at) => $at ? Carbon::parse($at)->format('j/n') : null;

        $list = $rounds->map(function (ProjectDelivery $d) use ($cabinetIds, $cabinetNames, &$arrivedIds, $day) {
            $ids = $d->cabinet_ids ? array_map('intval', $d->cabinet_ids) : $cabinetIds;
            $gone = in_array($d->status, [ProjectDelivery::DEPARTED, ProjectDelivery::DELIVERED], true);
            if ($d->status === ProjectDelivery::DELIVERED) {
                $arrivedIds = array_merge($arrivedIds, $ids);
            }
            $packed = $d->cabinet_ids ? $d->packedCount() : 0;

            return [
                'id' => $d->id,
                'round' => (int) $d->round,
                'when' => $d->date_pending_at || ! $d->delivery_date ? 'ยังไม่ลงวัน' : $d->when(),
                'dated' => ! $d->date_pending_at && (bool) $d->delivery_date,
                'state' => $this->stateOf($d)[0],
                'color' => $this->stateOf($d)[1],
                'cabinetIds' => array_values(array_filter($cabinetIds, fn ($id) => in_array($id, $ids, true))),   // the panel groups its cabinets by round
                // the cabinets in it, in the project's order (a round listing none: every cabinet)
                'cabinetNames' => array_values(array_map(fn ($id) => $cabinetNames[$id], array_filter($cabinetIds, fn ($id) => in_array($id, $ids, true)))),
                'meta' => implode(' · ', array_filter([
                    count($ids).' ตู้',
                    $d->method && ! in_array($d->status, [ProjectDelivery::DRAFT, ProjectDelivery::WAITING, ProjectDelivery::REJECTED], true) ? ProjectDelivery::methodName($d->method) : null,
                    trim((string) $d->site) ?: null,
                ])),
                'steps' => [
                    ['label' => 'ห่อ'.($d->cabinet_ids ? ' '.$packed.'/'.count($d->cabinet_ids) : ''), 'done' => $d->allPacked() || $gone || (bool) $d->billed_at, 'at' => null],
                    ['label' => 'เปิดบิล', 'done' => (bool) $d->billed_at || $gone, 'at' => $day($d->billed_at)],
                    ['label' => 'ออกจากโรงงาน', 'done' => $gone, 'at' => $day($d->departed_at)],
                    ['label' => 'ส่งถึง', 'done' => $d->status === ProjectDelivery::DELIVERED, 'at' => $day($d->delivered_at)],
                ],
            ];
        })->values()->all();

        return [
            'arrived' => count(array_intersect($cabinetIds, array_unique($arrivedIds))),
            'cabinets' => count($cabinetIds),
            'rounds' => $list,
        ];
    }

    /**
     * How a level's work came out, for its bar's colour: done (100%), late (past its due date, not done), going, or not
     * started (0%) - with the due date when it is late, for the words beside the colour.
     *
     * @return array{result: string, dueLabel: ?string}
     */
    private function result(int $progress, $due, $today): array
    {
        $result = match (true) {
            $progress >= 100 => 'done',
            $due !== null && $due->lt($today) => 'late',
            $progress > 0 => 'going',
            default => 'notstarted',
        };

        return ['result' => $result, 'dueLabel' => $result === 'late' ? $due->thaiDate() : null];
    }

    /** the latest delivery round the cabinet is in, in a word ("ส่งถึงแล้ว", "จัดรถแล้ว", "ร่าง"); null = in none */
    private function deliveryOf($deliveries, int $cabinetId): ?string
    {
        $round = $deliveries->sortByDesc('round')->first(fn ($d) => ! $d->cabinet_ids || in_array($cabinetId, array_map('intval', $d->cabinet_ids), true));

        return $round ? 'ส่งรอบ '.$round->round.' · '.ProjectDelivery::statusName($round->status) : null;
    }

    /** the latest delivery round the cabinet is in, as the panel's chip: "ส่งรอบ 1 · 20/11/2569 · ส่งถึงแล้ว" in the round's colour */
    private function deliveryChipOf($deliveries, int $cabinetId): ?array
    {
        $round = $deliveries->sortByDesc('round')->first(fn ($d) => ! $d->cabinet_ids || in_array($cabinetId, array_map('intval', $d->cabinet_ids), true));
        if (! $round) {
            return null;
        }
        [$state, $color] = $this->stateOf($round);
        $when = $round->date_pending_at || ! $round->delivery_date ? null : $round->when();

        return ['text' => implode(' · ', array_filter(['ส่งรอบ '.$round->round, $when, $state])), 'color' => $color,
            // the icon: arrived, on the way, or not gone yet
            'step' => match ($round->status) {
                ProjectDelivery::DELIVERED => 'arrived',
                ProjectDelivery::DEPARTED => 'gone',
                default => 'waiting',
            }];
    }

    /** a round's state as งานจัดส่ง says it, with its colour; a draft: not sent to the delivery team yet */
    private function stateOf(ProjectDelivery $d): array
    {
        return $d->status === ProjectDelivery::DRAFT ? ['ยังไม่ส่งให้ฝ่ายจัดส่ง', '#64748b'] : [$d->stateLabel(), $d->stateColor()];
    }

    /** the latest FAT round the cabinet is in: "FAT 04/11/2569 · ผ่าน"; null = in none */
    private function fatOf($fats, int $cabinetId): ?string
    {
        $fat = ProjectFat::latestFor($fats, $cabinetId);
        if (! $fat) {
            return null;
        }
        $when = $fat->date_pending_at || ! $fat->fat_date ? 'ยังไม่ลงวัน' : $fat->dateRange();

        return 'FAT '.$when.' · '.$fat->statusLabel();
    }
}
