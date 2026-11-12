<?php

namespace App\Services;

use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Checklists compared side by side: a Sub Task paired with another in the same cabinet (set on the cabinet template,
 * SubtaskPairs) shows that one's checklist next to its own - read only, the same rows, never a copy - with the date its
 * department gives each item (Settings > แผนก, e.g. จัดซื้อ "วันของเข้า"), and its department hears when those dates are
 * set or moved and items ticked. Nothing is blocked: this only lets people follow the work; real stock stays in the ERP.
 */
class HandoffService
{
    use Concerns\HandoffNotices;

    private ?Collection $labels = null;

    /** @var array<int, Collection> cabinet id => its pairs [to => receiving sub task id, from => source sub task id] */
    private array $pairs = [];

    private ?array $targets = null;

    /** The date a department fills in per item for the ones comparing with it ("วันของเข้า"), or null when it has none. */
    public function dateLabel(?int $deptId): ?string
    {
        $this->labels ??= Department::whereNotNull('handoff_date_label')->where('handoff_date_label', '!=', '')->pluck('handoff_date_label', 'id');

        return $deptId ? ($this->labels[$deptId] ?? null) : null;
    }

    /** The date label a Sub Task's items carry: its department's (Settings > แผนก), whether or not another Sub Task compares with it. */
    public function dateLabelFor(CabinetSubtask $subtask): ?string
    {
        return $this->dateLabel($subtask->department_id);
    }

    /** @return list<int> the departments whose Sub Tasks compare with $deptId's somewhere in a template (Planning) */
    public function targetsFor(?int $deptId): array
    {
        $this->targets ??= DB::table('cabinet_subtask_template_pairs as p')
            ->join('cabinet_subtask_templates as r', 'r.id', '=', 'p.cabinet_subtask_template_id')
            ->join('cabinet_subtask_templates as s', 's.id', '=', 'p.source_subtask_template_id')
            ->whereNotNull('r.department_id')->whereNotNull('s.department_id')->whereColumn('r.department_id', '!=', 's.department_id')
            ->distinct()->get(['s.department_id as from', 'r.department_id as to'])
            ->groupBy('from')->map(fn ($g) => $g->pluck('to')->map(fn ($id) => (int) $id)->unique()->values()->all())->all();

        return $deptId ? ($this->targets[$deptId] ?? []) : [];
    }

    private static function norm(string $s): string
    {
        return SubtaskPairs::norm($s);
    }

    /**
     * Has the receiving side dealt with this item: the receiver ticked its copy of it (SharedChecklist), else an item of the
     * same name, else everything it has.
     *
     * @param  Collection<int, CabinetChecklist>  $receiverItems  the receiving Sub Task's items
     */
    public static function received(CabinetChecklist $item, Collection $receiverItems): bool
    {
        if ($receiverItems->isEmpty()) {
            return false;
        }
        $copies = $receiverItems->where('source_checklist_id', $item->id);
        if ($copies->isNotEmpty()) {
            return $copies->contains('is_completed', true);
        }
        $same = $receiverItems->filter(fn ($r) => self::norm($r->name) === self::norm($item->name));

        return $same->isNotEmpty() ? $same->contains('is_completed', true) : $receiverItems->every('is_completed');
    }

    // ---- the pairs ----

    /** Load the pairs of these cabinets in one query (Planning has many). */
    public function preload(Collection $cabinetIds): void
    {
        $missing = $cabinetIds->map(fn ($id) => (int) $id)->reject(fn ($id) => isset($this->pairs[$id]))->values();
        if ($missing->isEmpty()) {
            return;
        }
        $rows = DB::table('cabinet_subtask_pairs as p')
            ->join('cabinet_subtasks as r', 'r.id', '=', 'p.cabinet_subtask_id')
            ->join('cabinet_tasks as t', 't.id', '=', 'r.cabinet_task_id')
            ->whereIn('t.cabinet_id', $missing)->whereNull('r.deleted_at')
            ->get(['t.cabinet_id as cabinet', 'p.cabinet_subtask_id as to', 'p.source_subtask_id as from', 'p.view_only as view', 'p.own_ticks as own'])
            ->groupBy('cabinet');
        foreach ($missing as $id) {
            $this->pairs[$id] = ($rows->get($id) ?? collect())->map(fn ($r) => ['to' => (int) $r->to, 'from' => (int) $r->from, 'view' => (bool) $r->view, 'own' => (bool) $r->own])->values();
        }
    }

    private function pairsIn(int $cabinetId): Collection
    {
        $this->preload(collect([$cabinetId]));

        return $this->pairs[$cabinetId];
    }

    private function sourceIds(CabinetSubtask $s): Collection
    {
        return $this->pairsIn((int) $s->cabinetTask->cabinet_id)->where('to', $s->id)->pluck('from');
    }

    /**
     * @param  string|null  $kind  null = all; 'take' = the ones receiving the items (their tick is "received": คลัง);
     *                             'follow' = the ones following them only ("ดูอย่างเดียว", or "ใช้รายการเดียวกัน" ticking their own)
     */
    private function receiverIds(CabinetSubtask $s, ?string $kind = null): Collection
    {
        return $this->pairsIn((int) $s->cabinetTask->cabinet_id)->where('from', $s->id)
            ->when($kind !== null, fn ($p) => $p->filter(fn ($r) => ($r['view'] || $r['own']) === ($kind === 'follow')))->pluck('to');
    }

    /** @return array{to: int, from: int, view: bool, own: bool} */
    private function pairOf(CabinetSubtask $receiver, CabinetSubtask $source): array
    {
        return $this->pairsIn((int) $receiver->cabinetTask->cabinet_id)->first(fn ($p) => $p['to'] === $receiver->id && $p['from'] === $source->id);
    }

    /** The Sub Tasks with their checklists as they are now (not deleted). @return Collection<int, CabinetSubtask> */
    private function load(Collection $ids): Collection
    {
        return $ids->isEmpty() ? collect() : CabinetSubtask::whereIn('id', $ids->unique()->values())
            ->with('department:id,name', 'checklists', 'cabinetTask:id,cabinet_id,name,sequence')->get();
    }

    /** Pairs were added or changed (SubtaskPairs): read them again. */
    public function forgetPairs(): void
    {
        $this->pairs = [];
        $this->targets = null;
        $this->labels = null;
    }

    private static function order(CabinetSubtask $s): string
    {
        return sprintf('%05d-%05d-%09d', $s->cabinetTask->sequence ?? 0, $s->sequence ?? 0, $s->id);
    }

    // ---- what each side sees ----

    /**
     * Under a Sub Task: the lists it compares with, each with whether this Sub Task has dealt with each item. A list it only
     * follows ("ดูอย่างเดียว", watch) says instead whether the Sub Tasks taking its items have (e.g. Production sees จัดซื้อ's
     * date and คลัง's "รับแล้ว" on one row): each item's receipts, and how many items have come (got). A watched list nobody
     * takes items from (e.g. Production watching คลัง itself) shows its own ticks ("คลัง ติ๊กแล้ว"), and a copy in it shows
     * the date its source gave (จัดซื้อ's วันของเข้า).
     *
     * @return list<array{id: string, deptId: string, deptName: string, title: string, dateLabel: ?string, watch: bool, items: list<array>, done: int, total: int}>
     */
    public function linkedForSubtask(CabinetSubtask $receiver): array
    {
        $sources = $this->load($this->sourceIds($receiver));
        $mine = $receiver->checklists;

        return $sources->sortBy(fn ($s) => self::order($s))->map(function (CabinetSubtask $s) use ($mine, $receiver) {
            ['view' => $watch, 'own' => $own] = $this->pairOf($receiver, $s);
            // the ones receiving its items (คลัง): "ดูอย่างเดียว" and "ใช้รายการเดียวกัน" show their "รับแล้ว"
            $takers = $watch || $own ? $this->load($this->receiverIds($s, 'take'))->sortBy(fn ($r) => self::order($r))->values() : collect();
            if ($watch || $own) {
                $s->checklists->loadMissing('sourceItem:id,cabinet_subtask_id,expected_date', 'sourceItem.subtask:id,department_id');
            }
            $ownName = (string) ($s->department?->name ?? $s->name);
            $tags = app(ChecklistStatuses::class)->idsFor($s->checklists->pluck('id'));   // its statuses, shown beside its items (read only)
            $items = $s->checklists->sortBy(fn ($c) => sprintf('%05d-%09d', $c->sequence ?? 0, $c->id))->map(function (CabinetChecklist $c) use ($mine, $watch, $own, $takers, $ownName, $tags) {
                $row = ['id' => 'k'.$c->id, 'text' => $c->name, 'done' => (bool) $c->is_completed, 'remark' => (string) $c->remark,
                    'expected' => ($c->expected_date ?? ($watch || $own ? $c->sourceItem?->expected_date : null))?->format('Y-m-d')]
                    + (isset($tags[$c->id]) ? ['tags' => $tags[$c->id]] : []);
                // whether the ones receiving it have it (คลัง); none receive from this list (it is คลัง's own): its own tick
                $receipts = fn () => $takers->isEmpty()
                    ? [['dept' => $ownName, 'at' => $c->is_completed ? $c->completed_at?->format('Y-m-d') : null, 'received' => (bool) $c->is_completed, 'verb' => 'ติ๊ก']]
                    : $takers->map(fn (CabinetSubtask $r) => ['dept' => (string) ($r->department?->name ?? $r->name),
                        'at' => self::receivedAt($c, $r->checklists)?->format('Y-m-d'), 'received' => self::received($c, $r->checklists)])->all();
                if (! $watch) {   // comparing: whether this Sub Task has it; using the list for its own work: also whether คลัง has
                    return $row + ['received' => self::received($c, $mine)] + ($own ? ['receipts' => $receipts()] : []);
                }
                $receipts = $receipts();

                return $row + ['received' => collect($receipts)->every('received'), 'receipts' => $receipts];
            })->values();
            // the date's name: its own department's, else (a watched list of copies) the one of the department they come from
            $label = $this->dateLabel($s->department_id)
                ?? ($watch || $own ? $this->dateLabel($s->checklists->map(fn ($c) => $c->sourceItem?->subtask?->department_id)->filter()->first()) : null);

            return ['id' => 's'.$s->id, 'deptId' => PlanningBoard::deptKey((int) $s->department_id), 'deptName' => (string) $s->department?->name,
                'title' => $s->name, 'dateLabel' => $label, 'watch' => $watch, 'own' => $own] + $this->whereIs($s) + [
                'items' => $items->all(), 'done' => $items->where('done', true)->count(), 'total' => $items->count(),
                'got' => $items->where('received', true)->count()];
        })->values()->all();
    }

    /** When the receiving side ticked this item (its copy, else one of the same name), for "รับแล้ว 03/11". */
    private static function receivedAt(CabinetChecklist $item, Collection $receiverItems): ?Carbon
    {
        $copy = $receiverItems->where('source_checklist_id', $item->id)->firstWhere('is_completed', true)
            ?? $receiverItems->first(fn ($r) => $r->is_completed && ! $r->source_checklist_id && self::norm($r->name) === self::norm($item->name));

        return $copy?->completed_at;
    }

    /** The other side of a pair at a glance: where its work is, when it is due, and a link to it (so a stalled one can be chased). */
    private function whereIs(CabinetSubtask $s): array
    {
        return [
            'state' => $s->isDone() ? 'เสร็จงานแล้ว' : (CabinetSubtask::$assignmentStatuses[$s->assignment_status] ?? ''),
            'late' => $s->isLateOn(today()),
            'due' => $s->due_date?->format('Y-m-d'),
            'href' => route('cabinets.show', $s->cabinetTask->cabinet_id).'#subtask-'.$s->id,
        ];
    }

    /**
     * The source side: the Sub Tasks comparing with this one.
     *
     * @return list<array{title: string, deptName: string, state: string, late: bool, due: ?string, href: string}>
     */
    public function receiversOf(CabinetSubtask $source): array
    {
        return $this->load($this->receiverIds($source))->sortBy(fn ($s) => self::order($s))
            ->map(fn (CabinetSubtask $s) => ['title' => $s->name, 'deptName' => (string) $s->department?->name] + $this->whereIs($s))->values()->all();
    }

    /**
     * Planning: per cabinet, the lists the user's department compares with (PMO roles already see every checklist):
     * cabinet id => [ { id, deptId, title, for, items: [ {id, text, done, remark, expected, received} ], done, total } ].
     */
    public function linkedFor(User $user, Collection $cabinetIds): Collection
    {
        if ($user->canSeeAllWork() || ! $user->departmentIds() || $cabinetIds->isEmpty()) {
            return collect();
        }
        $this->preload($cabinetIds);
        $receiverIds = $cabinetIds->flatMap(fn ($id) => $this->pairs[(int) $id]->pluck('to'))->unique()->values();
        $mine = $this->load($receiverIds)->whereIn('department_id', $user->departmentIds());

        return $mine->groupBy(fn ($s) => $s->cabinetTask->cabinet_id)->map(fn (Collection $subs) => $subs->sortBy(fn ($s) => self::order($s))
            ->flatMap(fn ($s) => array_map(fn ($l) => $l + ['for' => $s->name, 'forId' => 's'.$s->id], $this->linkedForSubtask($s)))
            ->unique(fn ($l) => $l['id'].'>'.$l['forId'])->values()->all());
    }

    /**
     * The source side: for each item of this Sub Task, whether each Sub Task comparing with it has dealt with it yet.
     *
     * @return array<int, list<array{dept: string, received: bool}>> checklist id => receivers
     */
    public function receiptsFor(CabinetSubtask $source): array
    {
        $receivers = $this->load($this->receiverIds($source, 'take'));   // one only following (or using the list for its own work) receives nothing
        $out = [];
        foreach ($source->checklists as $item) {
            foreach ($receivers as $r) {
                $out[$item->id][] = ['dept' => (string) $r->department?->name, 'received' => self::received($item, $r->checklists)];
            }
        }

        return $out;
    }

    // ---- notices to the departments comparing (one per project, folded into its project notice) ----

}
