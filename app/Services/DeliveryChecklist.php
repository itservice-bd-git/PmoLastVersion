<?php

namespace App\Services;

use App\Models\CabinetChecklist;
use App\Models\ProjectDelivery;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The delivery department works on "งานจัดส่ง" only; the cabinets' Checklist follows it. Each step of a round ticks (or,
 * stepped back, unticks) the item of the same name in each of its cabinets, wherever it sits in the cabinet's tree
 * (Task 14 แพ็กสินค้าและจัดคิวรถ / Task 15 ส่งมอบ in the template). The tick goes through ProgressService as a hand's
 * would - progress, the pair's copies, notices - and Automation may move the Sub Task on.
 *
 * While a cabinet is in a round that was sent (not a draft) those items are the round's: ticking them by hand is refused
 * (assertManual), so the Checklist never says something else than the round. A cabinet without a round keeps them as
 * ordinary items.
 */
class DeliveryChecklist
{
    public const PACK = 'pack';

    public const BILL = 'bill';

    public const DEPART = 'depart';

    public const DELIVER = 'deliver';

    /** step => the item's name in the cabinet's Checklist (compared without spaces) */
    public const ITEMS = [self::PACK => 'ห่อตู้เสร็จสิ้น', self::BILL => 'แจ้งเปิดบิล', self::DEPART => 'ดำเนินการส่งมอบ', self::DELIVER => 'ส่งมอบเสร็จสิ้น'];

    public function __construct(private ProgressService $progress, private AutomationService $automation) {}

    /**
     * Tick ($done) or untick the step's item in each of the cabinets. Returns how many items changed.
     *
     * @param  list<int>  $cabinetIds
     */
    public function mark(array $cabinetIds, string $step, bool $done, ?User $actor): int
    {
        $n = 0;
        foreach ($this->items($cabinetIds, [$step]) as $item) {
            if ((bool) $item->is_completed === $done) {
                continue;
            }
            $this->progress->toggleChecklist($item, $done, $actor);
            if ($done) {
                $this->automation->afterChecklistTicked($item->subtask->fresh(), $actor);
            }
            $n++;
        }

        return $n;
    }

    /** Is this item one of the steps' items in a cabinet that is in a sent round (so the round ticks it, not a hand)? */
    public function lockedBy(CabinetChecklist $item): ?ProjectDelivery
    {
        if (! self::stepOf($item->name)) {
            return null;
        }
        $cabinet = $item->subtask?->cabinetTask?->cabinet;

        return $cabinet ? ProjectDelivery::where('project_id', $cabinet->project_id)->where('status', '!=', ProjectDelivery::DRAFT)->get()
            ->first(fn (ProjectDelivery $d) => in_array($cabinet->id, array_map('intval', $d->cabinet_ids ?? []), true)) : null;
    }

    /** Refuse a hand's tick of an item a round ticks (CabinetChecklistController, PlanningSync, ChecklistStatuses). */
    public function assertManual(CabinetChecklist $item): void
    {
        if ($d = $this->lockedBy($item)) {
            throw new \App\Exceptions\PlanningRejected('"'.$item->name.'" ติ๊กจากหน้า "งานจัดส่ง" (รอบที่ '.$d->round.') · Checklist นี้ดูได้อย่างเดียว');
        }
    }

    /** The step whose item has this name, or null. */
    public static function stepOf(?string $name): ?string
    {
        $flat = self::flat((string) $name);
        foreach (self::ITEMS as $step => $item) {
            if ($flat === self::flat($item)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The steps' items in these cabinets.
     *
     * @param  list<int>  $cabinetIds
     * @param  list<string>  $steps
     * @return Collection<int, CabinetChecklist>
     */
    public function items(array $cabinetIds, array $steps): Collection
    {
        if (! $cabinetIds) {
            return collect();
        }
        $names = array_map(fn ($s) => self::flat(self::ITEMS[$s]), $steps);

        return CabinetChecklist::with('subtask.cabinetTask.cabinet')
            ->whereHas('subtask.cabinetTask', fn ($q) => $q->whereIn('cabinet_id', $cabinetIds))
            ->where(function ($q) use ($steps) {   // a first cut by SQL, the exact name below
                foreach ($steps as $s) {
                    $q->orWhere('name', 'like', '%'.mb_substr(self::ITEMS[$s], 0, 4).'%');
                }
            })
            ->get()->filter(fn (CabinetChecklist $c) => in_array(self::flat($c->name), $names, true))->values();
    }

    private static function flat(string $s): string
    {
        return preg_replace('/\s+/u', '', $s);
    }
}
