<?php

namespace App\Services;

use App\Exceptions\PlanningRejected;
use App\Models\ActivityLog;
use App\Models\CabinetChecklist;
use App\Models\ChecklistStatus;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Settings > สีสถานะ Checklist: coloured tags on a cabinet's checklist items, several per item (e.g. "ของสั่งนอก" + "รอของ").
 *
 * - Statuses sharing a group are exclusive: picking one replaces the other ("รอของ" → "ของเข้าแล้ว").
 * - A status may be limited to some departments (the department of the item's Sub Task).
 * - A switched-off status stays on the items that have it (and can be taken off) but cannot be picked again.
 * - Each item keeps its own: a pair's copy (SharedChecklist) does not follow the source's tags.
 * - Who may tag = who may edit the list (isChecklistEditableBy), checked by the callers (ChecklistEditor, PlanningSync).
 */
class ChecklistStatuses
{
    /** @var Collection<int, ChecklistStatus>|null in the order every menu shows them */
    private ?Collection $all = null;

    /** @return Collection<int, ChecklistStatus> keyed by id */
    public function all(): Collection
    {
        return $this->all ??= ChecklistStatus::orderBy('sort')->orderBy('id')->get()->keyBy('id');
    }

    /** What the pages draw chips and menus from. */
    public function catalog(): array
    {
        return $this->all()->values()->map->payload()->all();
    }

    /**
     * item id => its status ids, in menu order (one query for any number of items).
     *
     * @param  iterable<int>  $itemIds
     * @return array<int, list<int>>
     */
    public function idsFor(iterable $itemIds): array
    {
        $ids = collect($itemIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty() || $this->all()->isEmpty()) {
            return [];
        }
        $order = $this->all()->keys()->flip();

        return $ids->chunk(800)->flatMap(fn ($chunk) => DB::table('cabinet_checklist_status')->whereIn('cabinet_checklist_id', $chunk)->get(['cabinet_checklist_id', 'checklist_status_id']))
            ->groupBy('cabinet_checklist_id')
            ->map(fn ($rows) => $rows->pluck('checklist_status_id')->map(fn ($id) => (int) $id)->sortBy(fn ($id) => $order[$id] ?? PHP_INT_MAX)->values()->all())
            ->all();
    }

    /**
     * Give one item exactly these statuses. Returns the Activity Log line, or null when nothing changed.
     *
     * @param  list<int|string>  $ids
     *
     * @throws PlanningRejected
     */
    public function set(CabinetChecklist $item, array $ids, User $user): ?string
    {
        $all = $this->all();
        $before = $this->idsFor([$item->id])[$item->id] ?? [];
        $want = collect($ids)->map(fn ($id) => (int) $id)->unique()->filter(fn ($id) => $all->has($id))->values();
        $deptId = $item->subtask?->department_id;

        foreach ($want->diff($before) as $id) {
            $s = $all[$id];
            if (! $s->is_active) {
                throw new PlanningRejected("สถานะ \"{$s->name}\" ปิดใช้แล้ว เลือกใหม่ไม่ได้");
            }
            if (! $s->fitsDepartment($deptId)) {
                throw new PlanningRejected("สถานะ \"{$s->name}\" ใช้กับงานของแผนกนี้ไม่ได้ (ตั้งค่า › สีสถานะ Checklist)");
            }
        }
        // one per group: the one just picked replaces the one the item had
        foreach ($want->filter(fn ($id) => $all[$id]->group_name)->groupBy(fn ($id) => $all[$id]->group_name) as $group => $inGroup) {
            if ($inGroup->count() < 2) {
                continue;
            }
            $fresh = $inGroup->diff($before)->values();
            if ($fresh->count() !== 1) {
                throw new PlanningRejected("กลุ่ม \"{$group}\" เลือกได้อย่างเดียว");
            }
            $want = $want->reject(fn ($id) => $inGroup->contains($id) && $id !== $fresh->first())->values();
        }

        $add = $want->diff($before)->values();
        $remove = collect($before)->diff($want)->values();
        if ($add->isEmpty() && $remove->isEmpty()) {
            return null;
        }

        $projectId = $item->subtask?->cabinetTask?->cabinet?->project_id;
        ProjectLock::assertOpen($projectId);
        $item->statuses()->detach($remove->all());
        $item->statuses()->attach($add->mapWithKeys(fn ($id) => [$id => ['created_by' => $user->id]])->all());
        CabinetChecklist::whereKey($item->id)->update(['updated_at' => now()]);   // the cabinet page's / board's stamps notice it

        $name = fn ($id) => '"'.$all[$id]->name.'"';
        $parts = [];
        $swapped = [];
        foreach ($add as $id) {   // a group's old status swapped for its new one reads as one change
            $old = $remove->first(fn ($r) => $all[$id]->group_name && $all[$r]->group_name === $all[$id]->group_name);
            if ($old) {
                $parts[] = 'เปลี่ยน '.$name($old).' → '.$name($id);
                $swapped[] = $old;
            }
        }
        $plain = $add->reject(fn ($id) => $remove->contains(fn ($r) => $all[$id]->group_name && $all[$r]->group_name === $all[$id]->group_name));
        if ($plain->isNotEmpty()) {
            $parts[] = 'ติด '.$plain->map($name)->implode(', ');
        }
        if (($off = $remove->diff($swapped))->isNotEmpty()) {
            $parts[] = 'เอาออก '.$off->map($name)->implode(', ');
        }
        $line = "สถานะของ Checklist \"{$item->name}\": ".implode(' · ', $parts);
        ActivityLog::record($projectId, $user->id, $item, 'checklist_status', $line);
        PlanningBoard::touch();

        return $line;
    }

    /**
     * How many items carry each status ("สถานะในตู้นี้", a Task's header): status payload + n + open (not ticked yet).
     *
     * @param  iterable<CabinetChecklist>  $items
     * @param  array<int, list<int>>  $map  idsFor() of those items
     */
    public function tally(iterable $items, array $map): array
    {
        $rows = [];
        foreach ($items as $c) {
            foreach ($map[$c->id] ?? [] as $id) {
                $rows[$id] ??= ['n' => 0, 'open' => 0];
                $rows[$id]['n']++;
                $rows[$id]['open'] += $c->is_completed ? 0 : 1;
            }
        }

        return $this->all()->only(array_keys($rows))->values()->map(fn ($s) => $s->payload() + $rows[$s->id])->all();
    }
}
