<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\ProjectDelivery;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Validation\ValidationException;

/**
 * การจัดส่ง, one round from booking to arrival:
 *  - PM (canDispatchWork) may keep it as a draft first (ร่าง: nobody hears it; from the new-project page its cabinets are
 *    none = every cabinet), then sends it with the cabinets, the day it goes, the kinds of vehicle (several) and how many
 *    people → รอจัดรถ, the delivery department hears it. The day is PM's alone: moved only by PM (logged "เลื่อนวันส่ง
 *    จาก … เป็น …"); the vehicles already put on it stay and the delivery department is told to check them.
 *  - the delivery department (Access 'edit.delivery') puts the vehicles on it, each with its driver and people → จัดรถแล้ว
 *    (a kind PM did not ask for / fewer people than asked is allowed: PM hears it); or, before that, sends it back with a
 *    reason → ไม่รับงาน, for PM to pick another day and send it again
 *  - each cabinet packed (ห่อตู้เสร็จสิ้น) → แจ้งเปิดบิล once all are (the billing department hears it) → it leaves the
 *    factory (ดำเนินการส่งมอบ) → arrives (ส่งมอบเสร็จสิ้น: who received it, the delivery note, a problem on site). Each of
 *    these ticks the cabinets' Checklist items of the same name (DeliveryChecklist); a step back unticks them.
 * Every step re-reads the row under lockForUpdate, checks who acts and from which state, logs one line and notifies.
 */
class DeliveryFlow
{
    use Concerns\DeliveryRequests;
    use Concerns\DeliverySteps;

    public function __construct(private NotificationService $notices, private DeliveryChecklist $checklist, private CommentAttachmentUploader $uploader) {}

    public static function canBook(User $user): bool
    {
        return $user->canDispatchWork();
    }

    public static function canDeliver(User $user): bool
    {
        return $user->allows('edit.delivery');
    }

    /** A round waiting with its project for sales to give the new dates (ProjectDates): packing may go on, the rest waits for the day. */
    public const PENDING_TEXT = 'รอบนี้รอฝ่ายขายแจ้งกำหนดส่ง · ห่อตู้ได้ แต่จัดรถ / แจ้งเปิดบิล / ออกรถได้เมื่อมีวันแล้ว';

    /** "20/11/2569 · 09:00 น. · 2 ตู้ (WH/MO/01-1, WH/MO/01-2) · 10 ล้อ + กระบะ · 5 คน"; a draft without cabinets: "ทุกตู้" */
    public function describe(ProjectDelivery $d): string
    {
        $names = $this->names($d->cabinet_ids ?? []);

        return $d->when().' · '.($names->isEmpty() ? 'ทุกตู้' : $names->count().' ตู้ ('.$names->implode(', ').')').' · '.($d->transport() ?? '-')
            .($d->crew_size && $d->method !== ProjectDelivery::PICKUP ? ' · '.$d->crew_size.' คน' : '');
    }

    private function names(array $ids)
    {
        return Cabinet::whereIn('id', $ids)->orderBy('sequence')->orderBy('id')->get(['mo_no', 'cabinet_name'])->map(fn ($c) => $c->mo_no ?: $c->cabinet_name);
    }

    /**
     * The vehicles as they are saved: a company round needs at least one company vehicle (an active one, or one the round
     * already had), each once; an outside carrier's trucks need a label or a driver; a pickup has none.
     *
     * @return list<array{vehicle_id: ?int, label: ?string, driver_name: ?string, crew: ?array}>
     */
    private function vehicleRows(ProjectDelivery $d, array $input): array
    {
        if ($d->method === ProjectDelivery::PICKUP) {
            return [];
        }
        $had = $d->vehicles->pluck('vehicle_id')->filter()->map(fn ($id) => (int) $id)->all();
        $text = fn ($v, int $max) => mb_substr(trim((string) $v), 0, $max) ?: null;
        $rows = [];
        $seen = [];
        foreach ($input as $i => $row) {
            $vehicle = null;
            if ($d->method === ProjectDelivery::COMPANY) {
                if (empty($row['vehicle_id'])) {
                    continue;   // an empty line of the window
                }
                $vehicle = Vehicle::find((int) $row['vehicle_id']);
                if (! $vehicle || (! $vehicle->is_active && ! in_array($vehicle->id, $had, true))) {
                    throw ValidationException::withMessages(["vehicles.{$i}.vehicle_id" => 'เลือกรถ']);
                }
                if (isset($seen[$vehicle->id])) {
                    throw ValidationException::withMessages(["vehicles.{$i}.vehicle_id" => $vehicle->label().' ใส่ซ้ำ']);
                }
                $seen[$vehicle->id] = true;
            }
            $driver = $text($row['driver_name'] ?? '', 100) ?? $vehicle?->driver_name;
            $crew = collect($row['crew'] ?? [])->map(fn ($n) => mb_substr(trim((string) $n), 0, 100))->filter(fn ($n) => $n !== '' && $n !== $driver)->unique()->values()->all();
            $label = $vehicle ? null : $text($row['label'] ?? '', 150);
            if (! $vehicle && ! $label && ! $driver && ! $crew) {
                continue;
            }
            $rows[] = ['vehicle_id' => $vehicle?->id, 'label' => $label, 'driver_name' => $driver, 'crew' => $crew ?: null];
        }
        if ($d->method === ProjectDelivery::COMPANY && ! $rows) {
            throw ValidationException::withMessages(['vehicles' => 'เลือกรถอย่างน้อย 1 คัน']);
        }

        return $rows;
    }

    private function lock(ProjectDelivery $d): ProjectDelivery
    {
        return ProjectDelivery::with('project', 'vehicles.vehicle')->whereKey($d->id)->lockForUpdate()->firstOrFail();
    }

    private function assert(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['delivery' => $message]);
        }
    }

    private function log(User $actor, ProjectDelivery $d, string $line): void
    {
        ActivityLog::record($d->project_id, $actor->id, $d->project, 'delivery', $line);
        PlanningBoard::touch();
    }
}
