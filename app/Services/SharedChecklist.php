<?php

namespace App\Services;

use App\Exceptions\PlanningRejected;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One list for a pair (SubtaskPairs): a Sub Task comparing with another (e.g. ผลิต "ตรวจรับอุปกรณ์เข้า" ← จัดซื้อ
 * "สั่งซื้ออุปกรณ์") holds a copy of each of that one's items (cabinet_checklists.source_checklist_id), so both work through
 * the same list and each sees how far the other is. Each side ticks its own copy, but a receiver ticking its copy ticks the
 * source's item too (copyTicked): goods received means goods ordered.
 *
 *  - the source owns the list: adding, renaming, ordering and deleting there reach every copy; a copy's name, place and
 *    removal can't be changed on the receiving side (its tick, remark and date are its own);
 *  - an item a receiver already ticked can't be deleted at the source;
 *  - a receiver whose department pressed "เสร็จงาน" gets no new items (it would reopen closed work); one whose list is
 *    merely all ticked does - a new item is new work; it may always add items of its own;
 *  - the receiver's list: the copies first in the source's order, then its own items;
 *  - otherwise only a Sub Task's own items are shared - a copy is not copied on (no chains, no loops between two Sub Tasks);
 *  - a pair "ใช้รายการเดียวกัน" (own_ticks) gets the copies like any, but its ticks stay its own (copyTicked); and it
 *    gets the WHOLE list of the one it uses - that one's copies too (Production takes what คลัง checked and got ready, part
 *    of it คลัง's copies of จัดซื้อ's items): one step further, never back to an item of its own (sharedItems);
 *  - a pair "ดูอย่างเดียว" (view_only) shares nothing: that Sub Task only follows the list (HandoffService), and the copies it
 *    got before it was set so go - all but the ones it ticked, which stay as its own items.
 *
 * Writes here fire no model events (the callers log one line per operation); the Sub Tasks whose lists changed are kept for
 * the caller to work their progress out again (takeTouched()).
 */
class SharedChecklist
{
    /** @var list<int> */
    private array $touched = [];

    /** The Sub Tasks whose lists changed since the last call: their progress must be worked out again. @return list<int> */
    public function takeTouched(): array
    {
        $ids = array_values(array_unique($this->touched));
        $this->touched = [];

        return $ids;
    }

    // ---- guards ----

    /** A copy belongs to its source: refuse changing its name / place / removing it here. @param iterable<CabinetChecklist> $items */
    public function guardOwn(iterable $items): void
    {
        foreach ($items as $item) {
            $source = $item->source_checklist_id ? CabinetChecklist::with('subtask.department')->find($item->source_checklist_id) : null;
            if ($source) {
                throw new PlanningRejected('"'.$item->name.'" เป็นรายการจาก '.($source->subtask->department?->name ?? 'ต้นทาง')
                    .' ("'.$source->subtask->name.'") — แก้ชื่อ / ลบ / ย้าย ได้ที่ต้นทาง');
            }
        }
    }

    /** Before deleting: none of these may be ticked yet by a Sub Task comparing with it. @param Collection<int, CabinetChecklist> $items */
    public function assertDeletable(Collection $items): void
    {
        $ticked = CabinetChecklist::whereKey($this->copyIds($items->pluck('id')))->where('is_completed', true)->with('subtask.department')->first();
        if ($ticked) {
            throw new PlanningRejected('ลบไม่ได้ — '.($ticked->subtask->department?->name ?? $ticked->subtask->name).' ติ๊ก "'.$ticked->name.'" แล้ว');
        }
    }

    /** Moving items to another Sub Task: not a copy, and not one that others hold copies of. @param Collection<int, CabinetChecklist> $items */
    public function assertMovable(Collection $items): void
    {
        $this->guardOwn($items);
        if (CabinetChecklist::whereIn('source_checklist_id', $items->pluck('id'))->exists()) {
            throw new PlanningRejected('ย้ายไม่ได้ — รายการนี้ใช้ร่วมกับแผนกที่เทียบอยู่ (ลบแล้วเพิ่มใหม่ใน Sub Task ที่ต้องการแทน)');
        }
    }

    // ---- the source changed its list ----

    /** Items added to a Sub Task: the Sub Tasks comparing with it (not finished) get them too. @param Collection<int, CabinetChecklist> $items */
    public function added(CabinetSubtask $source, Collection $items): void
    {
        $items = $items->filter(fn ($c) => ! $c->source_checklist_id)->values();
        if ($items->isEmpty()) {
            return;
        }
        foreach ($this->receiversOf($source) as $receiver) {
            if (! self::closed($receiver)) {
                $this->link($receiver, $items, true);
            }
            // ... and the ones using that receiver's list for their own work get its new copies (คลัง → Production)
            $copies = CabinetChecklist::where('cabinet_subtask_id', $receiver->id)->whereIn('source_checklist_id', $items->pluck('id'))->get();
            $users = $copies->isEmpty() ? collect() : $this->ownReceiversOf($receiver)->reject(fn ($u) => self::closed($u) || $u->id === $source->id);
            if ($users->isNotEmpty()) {   // in the order คลัง's list has them (its copies first)
                $this->arrange($receiver);
                $users->each(function ($user) use ($copies) {
                    $this->link($user, $copies, true);
                    $this->arrange($user);
                });
            }
        }
    }

    /** An item renamed at its source: its copies take the name. */
    public function renamed(CabinetChecklist $item): void
    {
        CabinetChecklist::whereKey($this->copyIds(collect([$item->id])))->where('name', '!=', $item->name)->update(['name' => $item->name]);
    }

    /** Items deleted (soft) at their source: their copies go too, with the same time, so a restore brings both back. @param Collection<int, CabinetChecklist> $items */
    public function deleted(Collection $items): void
    {
        foreach ($items as $item) {
            $copies = CabinetChecklist::whereKey($this->copyIds(collect([$item->id])));
            array_push($this->touched, ...(clone $copies)->pluck('cabinet_subtask_id')->all());
            $copies->update(['deleted_at' => $item->deleted_at ?? now()]);
        }
    }

    /** Items restored at their source: the copies deleted with them come back. @param Collection<int, CabinetChecklist> $items as they were, still deleted */
    public function restored(Collection $items): void
    {
        foreach ($items->filter(fn ($c) => $c->deleted_at) as $item) {
            $copies = CabinetChecklist::onlyTrashed()->whereKey($this->copyIds(collect([$item->id]), true))->where('deleted_at', $item->deleted_at);
            array_push($this->touched, ...(clone $copies)->pluck('cabinet_subtask_id')->all());
            $copies->update(['deleted_at' => null]);
        }
    }

    /** The source's order changed: the copies follow it. */
    public function reordered(CabinetSubtask $source): void
    {
        $this->receiversOf($source)->each(function ($r) {
            $this->arrange($r);
            $this->ownReceiversOf($r)->each(fn ($u) => $this->arrange($u));
        });
    }

    // ---- the receiver ticked its copy ----

    /**
     * A receiver dealing with an item means the source's part of it is done (คลังรับของ → จัดซื้อสั่งซื้อแล้ว): ticking a copy
     * ticks the source's item too. Unticking takes back only a tick made that way (by someone of the receiver's department),
     * and only while no other copy of it is still ticked. Returns the source item when it changed (for the caller to log).
     */
    public function copyTicked(CabinetChecklist $copy, bool $done, ?User $user): ?CabinetChecklist
    {
        $source = $copy->source_checklist_id ? CabinetChecklist::with('subtask.cabinetTask.cabinet', 'subtask.department')->find($copy->source_checklist_id) : null;
        if (! $source || (bool) $source->is_completed === $done) {
            return null;
        }
        // "ใช้รายการเดียวกัน": this Sub Task ticks its copies for its own work (Production assembling) - the source is not its
        if (DB::table('cabinet_subtask_pairs')->where('cabinet_subtask_id', $copy->cabinet_subtask_id)->where('source_subtask_id', $source->cabinet_subtask_id)->where('own_ticks', true)->exists()) {
            return null;
        }
        if (! $done) {
            $receiverDept = $copy->subtask->department_id;
            $byReceiver = $receiverDept && $source->completed_by && (bool) User::find($source->completed_by)?->inDepartment((int) $receiverDept);
            if (! $byReceiver || CabinetChecklist::where('source_checklist_id', $source->id)->where('is_completed', true)->exists()) {
                return null;
            }
        }
        // same project as the copy, whose save already passed ProjectLock
        CabinetChecklist::whereKey($source->id)->update(['is_completed' => $done, 'completed_by' => $done ? $user?->id : null, 'completed_at' => $done ? now() : null]);

        return $source->refresh();
    }

    // ---- bringing a receiver in line (pairs set, changed, or sent to the cabinets made earlier) ----

    /** @param Collection<int, int>|array<int> $receiverIds */
    public function syncReceivers(Collection|array $receiverIds): void
    {
        $ids = collect($receiverIds)->map(fn ($id) => (int) $id)->unique()->values();
        if ($ids->isNotEmpty()) {
            Model::withoutEvents(fn () => CabinetSubtask::whereKey($ids)->orderBy('id')->get()->each(fn ($r) => $this->syncReceiver($r)));
        }
    }

    /** Every Sub Task comparing with another in these cabinets. @param Collection<int, int> $cabinetIds */
    public function syncCabinets(Collection $cabinetIds): void
    {
        $this->syncReceivers(DB::table('cabinet_subtask_pairs as p')->join('cabinet_subtasks as r', 'r.id', '=', 'p.cabinet_subtask_id')
            ->join('cabinet_tasks as t', 't.id', '=', 'r.cabinet_task_id')->whereIn('t.cabinet_id', $cabinetIds)
            ->whereNull('r.deleted_at')->distinct()->pluck('p.cabinet_subtask_id'));
    }

    /**
     * One receiver: an item of the same name it already has becomes the copy (its tick kept), a missing one is added (not
     * when its work is done), names follow the source, and items whose source it no longer compares with stay as its own.
     */
    public function syncReceiver(CabinetSubtask $receiver): void
    {
        $pairs = DB::table('cabinet_subtask_pairs')->where('cabinet_subtask_id', $receiver->id)->get(['source_subtask_id', 'view_only', 'own_ticks']);
        $watched = $pairs->where('view_only', true)->pluck('source_subtask_id');
        $sourceIds = $pairs->where('view_only', false)->pluck('source_subtask_id');
        $ownIds = $pairs->where('view_only', false)->where('own_ticks', true)->pluck('source_subtask_id');
        if ($watched->isNotEmpty()) {
            $copies = CabinetChecklist::where('cabinet_subtask_id', $receiver->id)->where('is_completed', false)
                ->whereIn('source_checklist_id', CabinetChecklist::withTrashed()->whereIn('cabinet_subtask_id', $watched)->select('id'));
            if ((clone $copies)->exists()) {
                $copies->update(['deleted_at' => now()]);
                $this->touched[] = $receiver->id;
            }
        }
        $sources = CabinetSubtask::whereKey($sourceIds)->orderBy('id')->get();
        $shared = $sources->mapWithKeys(fn ($source) => [$source->id => $this->sharedItems($source, $receiver, $ownIds->contains($source->id))]);
        CabinetChecklist::where('cabinet_subtask_id', $receiver->id)->whereNotNull('source_checklist_id')
            ->whereNotIn('source_checklist_id', $shared->flatten(1)->pluck('id')->push(0))
            ->update(['source_checklist_id' => null]);

        $create = ! self::closed($receiver);
        foreach ($sources as $source) {
            $items = $shared[$source->id];
            $items->each(fn ($c) => CabinetChecklist::where('source_checklist_id', $c->id)->where('name', '!=', $c->name)->update(['name' => $c->name]));
            $this->link($receiver, $items, $create);
        }
        $this->arrange($receiver);
    }

    // ---- helpers ----

    /** The receiving department closed its work ("เสร็จงาน"), as ChecklistBulk skips it: no new items for it. */
    private static function closed(CabinetSubtask $s): bool
    {
        return $s->assignment_status === CabinetSubtask::ASSIGNMENT_COMPLETED;
    }

    /**
     * What a source shares with this receiver: its own items; and to one using its list for its own work ("ใช้รายการเดียวกัน")
     * its copies too - of an item that is not a copy itself, and not one of this receiver's (one step, no way back).
     *
     * @return Collection<int, CabinetChecklist>
     */
    private function sharedItems(CabinetSubtask $source, CabinetSubtask $receiver, bool $own): Collection
    {
        return CabinetChecklist::where('cabinet_subtask_id', $source->id)
            ->where(fn ($q) => $q->whereNull('source_checklist_id')->when($own, fn ($q) => $q->orWhereIn('source_checklist_id',
                CabinetChecklist::whereNull('source_checklist_id')->where('cabinet_subtask_id', '!=', $receiver->id)->select('id'))))
            ->orderBy('sequence')->orderBy('id')->get();
    }

    /** The copies of these items, and their copies in turn (a "ใช้รายการเดียวกัน" one step further). @param Collection<int, int> $ids @return list<int> */
    private function copyIds(Collection $ids, bool $trashed = false): array
    {
        $q = fn () => $trashed ? CabinetChecklist::withTrashed() : CabinetChecklist::query();
        $first = $q()->whereIn('source_checklist_id', $ids)->pluck('id');

        return $first->merge($first->isEmpty() ? [] : $q()->whereIn('source_checklist_id', $first)->pluck('id'))->unique()->values()->all();
    }

    /** The Sub Tasks using this one's list for their own work ("ใช้รายการเดียวกัน"). @return Collection<int, CabinetSubtask> */
    private function ownReceiversOf(CabinetSubtask $source): Collection
    {
        return CabinetSubtask::whereKey(DB::table('cabinet_subtask_pairs')->where('source_subtask_id', $source->id)->where('view_only', false)->where('own_ticks', true)->pluck('cabinet_subtask_id'))->orderBy('id')->get();
    }

    /** @return Collection<int, CabinetSubtask> */
    private function receiversOf(CabinetSubtask $source): Collection
    {
        return CabinetSubtask::whereKey(DB::table('cabinet_subtask_pairs')->where('source_subtask_id', $source->id)->where('view_only', false)->pluck('cabinet_subtask_id'))->orderBy('id')->get();
    }

    /**
     * Give the receiver a copy of each item: its own item of the same name (one nobody copies from) becomes the copy, else a
     * new one goes at the end ($create).
     *
     * @param  Collection<int, CabinetChecklist>  $items
     */
    private function link(CabinetSubtask $receiver, Collection $items, bool $create): void
    {
        $mine = CabinetChecklist::where('cabinet_subtask_id', $receiver->id)->withCount('copies')->get();
        $seq = (int) $mine->max('sequence');
        foreach ($items as $item) {
            if ($mine->contains('source_checklist_id', $item->id)) {
                continue;
            }
            $same = $mine->first(fn ($c) => ! $c->source_checklist_id && ! $c->copies_count && SubtaskPairs::norm($c->name) === SubtaskPairs::norm($item->name));
            if ($same) {
                CabinetChecklist::whereKey($same->id)->update(['source_checklist_id' => $item->id, 'name' => $item->name]);
                $same->source_checklist_id = $item->id;
            } elseif ($create) {
                $mine->push(Model::withoutEvents(fn () => CabinetChecklist::forceCreate(['cabinet_subtask_id' => $receiver->id, 'source_checklist_id' => $item->id,
                    'checklist_template_id' => null, 'name' => $item->name, 'is_completed' => false, 'sequence' => ++$seq])));
                $this->touched[] = $receiver->id;
            }
        }
    }

    /** The receiver's list: the copies first, in their sources' order, then its own items in theirs. */
    private function arrange(CabinetSubtask $receiver): void
    {
        $list = CabinetChecklist::where('cabinet_subtask_id', $receiver->id)->with('sourceItem:id,cabinet_subtask_id,sequence')
            ->orderBy('sequence')->orderBy('id')->get(['id', 'sequence', 'source_checklist_id']);
        [$copies, $own] = $list->partition(fn ($c) => $c->sourceItem !== null);
        $copies = $copies->sortBy(fn ($c) => sprintf('%09d-%09d-%09d', $c->sourceItem->cabinet_subtask_id, $c->sourceItem->sequence ?? 0, $c->sourceItem->id));
        foreach ($copies->concat($own)->values() as $i => $c) {
            if ((int) $c->sequence !== $i + 1) {
                CabinetChecklist::whereKey($c->id)->update(['sequence' => $i + 1]);
            }
        }
    }
}
