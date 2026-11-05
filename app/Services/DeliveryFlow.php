<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * การจัดส่ง, one round from booking to arrival:
 *  - PM (canDispatchWork) may keep it as a draft first (ร่าง: nobody hears it; from the new-project page its cabinets are
 *    none = every cabinet), then sends it with the cabinets, the kind of vehicle, how many people and a window of days
 *    → รอกำหนดวัน, the delivery department hears it
 *  - the delivery department (Access 'edit.delivery') picks the day in the window, with the time, the vehicle and the
 *    people who go → กำหนดวันแล้ว (another kind of vehicle than PM asked for is allowed: PM hears it); or, while it still waits for a day, sends it back with a reason → ไม่รับงาน (as a
 *    department refuses work before accepting it: picking the day is accepting); PM fixes it and sends it again
 *  - it leaves the factory, then arrives (who received it, the delivery note, a problem on site)
 * Every step re-reads the row under lockForUpdate, checks who acts and from which state, logs one line and notifies.
 */
class DeliveryFlow
{
    public function __construct(private NotificationService $notices) {}

    public static function canBook(User $user): bool
    {
        return $user->canDispatchWork();
    }

    public static function canDeliver(User $user): bool
    {
        return $user->allows('edit.delivery');
    }

    /** PM: a new round kept as a draft (ร่าง): on the project page and Planning only, the delivery department does not see it. */
    public function draft(Project $project, array $data, User $actor): ProjectDelivery
    {
        $this->assert(self::canBook($actor), 'นัดส่งได้เฉพาะ Admin / PM');

        return DB::transaction(function () use ($project, $data, $actor) {
            $round = (int) $project->deliveries()->max('round') + 1;
            $d = $project->deliveries()->create($data + ['round' => $round, 'status' => ProjectDelivery::DRAFT, 'created_by' => $actor->id]);
            $this->log($actor, $d, "ร่างการจัดส่ง รอบที่ {$round}: ".$this->describe($d));

            return $d;
        });
    }

    /** PM: a new round (data = ProjectDeliveryController's fields), straight to the delivery department. */
    public function send(Project $project, array $data, User $actor): ProjectDelivery
    {
        $this->assert(self::canBook($actor), 'นัดส่งได้เฉพาะ Admin / PM');

        return DB::transaction(function () use ($project, $data, $actor) {
            $round = (int) $project->deliveries()->max('round') + 1;
            $d = $project->deliveries()->create($data + ['round' => $round, 'status' => ProjectDelivery::WAITING, 'sent_by' => $actor->id, 'created_by' => $actor->id]);
            $this->log($actor, $d, "ส่งให้แผนกจัดส่ง รอบที่ {$round}: ".$this->describe($d));
            $this->notices->deliveryForDepartment($d, $actor, 'งานจัดส่งใหม่ · เลือกวันส่ง');

            return $d;
        });
    }

    /**
     * PM changes a round before it leaves. A draft stays one, or goes to the delivery department ($send). Sent back
     * (ไม่รับงาน) = sent again. A picked day that no longer fits the new window = back to รอกำหนดวัน for the delivery
     * department to pick again; one that fits stays.
     */
    public function revise(ProjectDelivery $delivery, array $data, User $actor, bool $send = false): ProjectDelivery
    {
        $this->assert(self::canBook($actor), 'แก้รอบส่งได้เฉพาะ Admin / PM');

        return DB::transaction(function () use ($delivery, $data, $actor, $send) {
            $d = $this->lock($delivery);
            $this->assert(in_array($d->status, [ProjectDelivery::DRAFT, ProjectDelivery::WAITING, ProjectDelivery::REJECTED, ProjectDelivery::SCHEDULED], true),
                'รอบนี้ออกจากโรงงานแล้ว แก้ช่วงวัน / รถไม่ได้ · ย้อนสถานะได้ที่หน้า "งานจัดส่ง"');
            if ($d->status === ProjectDelivery::DRAFT) {
                $d->fill($data + ($send ? ['status' => ProjectDelivery::WAITING, 'sent_by' => $actor->id] : []))->save();
                $this->log($actor, $d, ($send ? "ส่งให้แผนกจัดส่ง รอบที่ {$d->round}: " : "แก้ร่างการจัดส่ง รอบที่ {$d->round}: ").$this->describe($d));
                if ($send) {
                    $this->notices->deliveryForDepartment($d, $actor, 'งานจัดส่งใหม่ · เลือกวันส่ง');
                }

                return $d;
            }
            $before = ['window' => $d->windowText(), 'transport' => $d->transport(), 'request' => $d->request(), 'cabinets' => $d->cabinet_ids, 'status' => $d->status, 'when' => $d->when()];

            $d->fill($data);
            $resent = $d->status === ProjectDelivery::REJECTED;
            $unpicked = $d->status === ProjectDelivery::SCHEDULED && ($d->delivery_date->lt($d->window_from) || $d->delivery_date->gt($d->window_to));
            if ($resent) {
                $d->fill(['status' => ProjectDelivery::WAITING, 'sent_by' => $actor->id, 'rejected_by' => null, 'rejected_at' => null, 'rejected_reason' => null, 'rejected_note' => null]);
            } elseif ($unpicked) {
                $d->fill(['status' => ProjectDelivery::WAITING, 'sent_by' => $actor->id, 'delivery_date' => null, 'delivery_time' => null, 'scheduled_by' => null, 'scheduled_at' => null]);
            }
            $d->save();
            $d->load('vehicle');

            $changes = array_filter([
                $before['window'] !== $d->windowText() ? 'ช่วงวัน '.$d->windowText() : null,
                $before['transport'] !== $d->transport() ? 'รถ: '.($d->transport() ?? '-') : null,
                $before['request'] !== $d->request() && $d->request() ? 'ขอ '.$d->request() : null,
                $before['cabinets'] !== $d->cabinet_ids ? count($d->cabinet_ids).' ตู้' : null,
                $unpicked ? "วันส่งเดิม {$before['when']} ไม่อยู่ในช่วงใหม่ · รอแผนกจัดส่งเลือกวันใหม่" : null,
            ]);
            $this->log($actor, $d, "การจัดส่งรอบที่ {$d->round}: ".($resent ? 'ส่งให้จัดส่งอีกครั้ง · '.$this->describe($d) : (implode(' · ', $changes) ?: 'แก้รายละเอียด')));

            if ($resent || $unpicked) {
                $this->notices->deliveryForDepartment($d, $actor, $resent ? 'PM ส่งรอบส่งให้อีกครั้ง · เลือกวันส่ง' : 'PM เปลี่ยนช่วงวัน · เลือกวันส่งใหม่');
            } elseif ($changes) {
                $this->notices->deliveryForDepartment($d, $actor, 'PM แก้รอบส่ง: '.implode(' · ', $changes), todo: false);
            }

            return $d;
        });
    }

    /**
     * The delivery department picks the day (inside PM's window), the time, the vehicle / carrier and the people who go
     * (the driver, then the others). Picking again moves it.
     *
     * @param  array{delivery_date: string, delivery_time?: ?string, vehicle_id?: ?int, driver_name?: ?string, crew?: list<?string>, carrier?: ?string}  $data
     */
    public function schedule(ProjectDelivery $delivery, array $data, User $actor): ProjectDelivery
    {
        $this->assert(self::canDeliver($actor), 'เลือกวันส่งได้เฉพาะแผนกจัดส่ง');

        return DB::transaction(function () use ($delivery, $data, $actor) {
            $d = $this->lock($delivery);
            $this->assert(in_array($d->status, [ProjectDelivery::WAITING, ProjectDelivery::SCHEDULED], true), match ($d->status) {
                ProjectDelivery::REJECTED => 'รอบนี้ส่งกลับให้ PM แล้ว · รอ PM ส่งมาอีกครั้ง',
                default => 'รอบนี้ออกจากโรงงานแล้ว',
            });
            $day = Carbon::parse($data['delivery_date'])->startOfDay();
            if ($day->lt($d->window_from) || $day->gt($d->window_to)) {
                throw ValidationException::withMessages(['delivery_date' => 'เลือกวันในช่วงที่ PM ให้: '.ProjectDelivery::range($d->window_from, $d->window_to)]);
            }
            $before = ['status' => $d->status, 'when' => $d->when(), 'transport' => $d->transport(), 'other' => $d->otherType() ? $d->vehicle_id : null];

            $vehicle = $d->method === ProjectDelivery::COMPANY ? $this->vehicle($data['vehicle_id'] ?? $d->vehicle_id, $d) : null;
            $text = fn (string $k) => trim((string) ($data[$k] ?? '')) ?: null;
            $driver = $d->method === ProjectDelivery::PICKUP ? null : ($text('driver_name') ?? $vehicle?->driver_name);
            $crew = $d->method === ProjectDelivery::PICKUP ? [] : collect($data['crew'] ?? [])->map(fn ($n) => mb_substr(trim((string) $n), 0, 100))
                ->filter(fn ($n) => $n !== '' && $n !== $driver)->unique()->values()->all();
            $d->fill([
                'status' => ProjectDelivery::SCHEDULED,
                'delivery_date' => $day, 'delivery_time' => $text('delivery_time'),
                'vehicle_id' => $vehicle?->id,
                'driver_name' => $driver, 'crew' => $crew ?: null,
                'carrier' => $d->method === ProjectDelivery::EXTERNAL ? ($text('carrier') ?? $d->carrier) : null,
                'scheduled_by' => $actor->id, 'scheduled_at' => now(),
            ])->save();
            $d->load('vehicle');

            $moved = $before['status'] === ProjectDelivery::SCHEDULED;
            // PM hears of a vehicle of another kind than it asked for (once per vehicle), or of a vehicle changed after the day was picked
            $other = $d->otherType() && $before['other'] !== $d->vehicle_id ? 'PM ขอ '.$d->vehicle_type.' · ได้ '.$d->vehicle->type.' ('.$d->vehicle->label().')' : null;
            $car = $moved && $before['transport'] !== $d->transport() ? 'เปลี่ยนรถจาก '.($before['transport'] ?? '-').' เป็น '.($d->transport() ?? '-') : null;
            $this->log($actor, $d, "การจัดส่งรอบที่ {$d->round}: ".($moved ? "เลื่อนวันส่งจาก {$before['when']} เป็น {$d->when()}" : 'แผนกจัดส่งกำหนดวันส่ง '.$d->when())
                .' · '.($d->transport() ?? '-').($d->people() ? ' · คน '.$d->peopleText() : '').($other ? ' · '.$other : ''));

            if (! $moved || $before['when'] !== $d->when()) {
                $this->notices->deliveryForManager($d, $actor, $moved ? 'แผนกจัดส่งเลื่อนวันส่ง' : 'แผนกจัดส่งกำหนดวันส่งแล้ว', $other);
            } elseif ($other) {
                $this->notices->deliveryForManager($d, $actor, 'แผนกจัดส่งใช้รถคนละประเภท', $other);
            }
            if ($car && ! $other) {
                $this->notices->deliveryForManager($d, $actor, 'แผนกจัดส่งเปลี่ยนรถ', $car);
            }

            return $d;
        });
    }

    /** "ไม่รับงาน": only while it waits for a day (picking the day is accepting it), with a reason; back to PM. */
    public function reject(ProjectDelivery $delivery, string $reason, ?string $note, User $actor): ProjectDelivery
    {
        $this->assert(self::canDeliver($actor), 'ไม่รับงานได้เฉพาะแผนกจัดส่ง');
        $reason = mb_substr(trim($reason), 0, 100);
        $note = mb_substr(trim((string) $note), 0, 500) ?: null;
        if ($reason === '' || ($reason === 'อื่น ๆ' && ! $note)) {
            throw ValidationException::withMessages(['reason' => $reason === '' ? 'เลือกเหตุผลที่ไม่รับงาน' : 'พิมพ์รายละเอียดของเหตุผล']);
        }

        return DB::transaction(function () use ($delivery, $reason, $note, $actor) {
            $d = $this->lock($delivery);
            $this->assert($d->status === ProjectDelivery::WAITING, $d->status === ProjectDelivery::REJECTED
                ? 'รอบนี้ส่งกลับให้ PM แล้ว' : 'ไม่รับงานได้เฉพาะรอบที่ยังรอกำหนดวัน — รอบนี้กำหนดวันแล้ว · ติดต่อ PM เพื่อแก้หรือยกเลิก');
            $d->fill(['status' => ProjectDelivery::REJECTED, 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejected_reason' => $reason, 'rejected_note' => $note])->save();

            $why = $reason.($note ? ' · '.$note : '');
            $this->log($actor, $d, "การจัดส่งรอบที่ {$d->round}: แผนกจัดส่งไม่รับงาน — เหตุผล: {$why}");
            $this->notices->deliveryForManager($d, $actor, 'แผนกจัดส่งไม่รับงาน', "เหตุผล: {$why}", todo: true);

            return $d;
        });
    }

    /** ออกจากโรงงาน */
    public function depart(ProjectDelivery $delivery, User $actor): ProjectDelivery
    {
        $this->assert(self::canDeliver($actor), 'บันทึกได้เฉพาะแผนกจัดส่ง');

        return DB::transaction(function () use ($delivery, $actor) {
            $d = $this->lock($delivery);
            $this->assert($d->status === ProjectDelivery::SCHEDULED, $d->isOpen() ? 'รอบนี้ยังไม่ได้กำหนดวันส่ง' : 'รอบนี้ออกจากโรงงานแล้ว');
            $d->fill(['status' => ProjectDelivery::DEPARTED, 'departed_at' => now()])->save();

            $this->log($actor, $d, "การจัดส่งรอบที่ {$d->round}: ".ProjectDelivery::statusName(ProjectDelivery::DEPARTED).' '.now()->format('H:i').' น. · '.$this->describe($d));
            $this->notices->deliveryForManager($d, $actor, ProjectDelivery::statusName(ProjectDelivery::DEPARTED).' '.now()->format('H:i').' น.');

            return $d;
        });
    }

    /**
     * ส่งถึงแล้ว: when, who received it, the delivery note, a problem on site, and the cabinets that arrived (the rest leave
     * the round, free to book again). Saved again = a correction.
     *
     * @param  array{delivered_date: string, delivered_time?: ?string, receiver: string, doc_no?: ?string, issue?: ?string, cabinet_ids: list<int>}  $data
     */
    public function deliver(ProjectDelivery $delivery, array $data, User $actor): ProjectDelivery
    {
        $this->assert(self::canDeliver($actor), 'บันทึกได้เฉพาะแผนกจัดส่ง');

        return DB::transaction(function () use ($delivery, $data, $actor) {
            $d = $this->lock($delivery);
            $this->assert(! $d->isOpen(), 'รอบนี้ยังไม่ได้กำหนดวันส่ง');
            $ids = array_values(array_intersect($d->cabinet_ids ?? [], array_map('intval', $data['cabinet_ids'] ?? [])));
            if (! $ids) {
                throw ValidationException::withMessages(['cabinet_ids' => 'ติ๊กตู้ที่ส่งถึงอย่างน้อย 1 ตู้']);
            }
            $left = array_diff($d->cabinet_ids ?? [], $ids);
            $again = $d->status === ProjectDelivery::DELIVERED;
            $at = Carbon::parse($data['delivered_date'].' '.(($data['delivered_time'] ?? null) ?: '00:00'));
            $text = fn (string $k) => trim((string) ($data[$k] ?? '')) ?: null;
            $d->fill(['status' => ProjectDelivery::DELIVERED, 'cabinet_ids' => $ids, 'departed_at' => $d->departed_at ?? $at, 'delivered_at' => $at,
                'receiver' => $text('receiver'), 'doc_no' => $text('doc_no'), 'issue' => $text('issue')])->save();

            $line = ($again ? 'แก้การส่งถึง' : ProjectDelivery::statusName(ProjectDelivery::DELIVERED)).' '.$at->thaiDate().($at->format('H:i') !== '00:00' ? ' '.$at->format('H:i').' น.' : '')
                .' · '.$this->describe($d).' · ผู้รับ '.$d->receiver.($d->doc_no ? ' · ใบส่งของ '.$d->doc_no : '').($d->issue ? ' · ปัญหา: '.$d->issue : '')
                .($left ? ' · ไม่ได้ส่ง '.$this->names($left)->implode(', ') : '');
            $this->log($actor, $d, "การจัดส่งรอบที่ {$d->round}: {$line}");
            if (! $again || $d->issue) {
                $this->notices->deliveryForManager($d, $actor, $d->issue ? 'ส่งถึงแล้ว · มีปัญหาที่หน้างาน' : ProjectDelivery::statusName(ProjectDelivery::DELIVERED),
                    $d->issue ? 'ปัญหา: '.$d->issue : ($left ? 'ไม่ได้ส่ง '.$this->names($left)->implode(', ') : null), todo: (bool) $d->issue);
            }

            return $d;
        });
    }

    /** ย้อนสถานะ (pressed by mistake): ส่งถึงแล้ว → ออกจากโรงงาน, ออกจากโรงงาน → กำหนดวันแล้ว. */
    public function stepBack(ProjectDelivery $delivery, User $actor): ProjectDelivery
    {
        $this->assert(self::canDeliver($actor), 'ย้อนสถานะได้เฉพาะแผนกจัดส่ง');

        return DB::transaction(function () use ($delivery, $actor) {
            $d = $this->lock($delivery);
            $this->assert(in_array($d->status, [ProjectDelivery::DEPARTED, ProjectDelivery::DELIVERED], true), 'ไม่มีสถานะให้ย้อน');
            $from = $d->status;
            $d->fill($from === ProjectDelivery::DELIVERED
                ? ['status' => ProjectDelivery::DEPARTED, 'delivered_at' => null, 'receiver' => null, 'issue' => null]
                : ['status' => ProjectDelivery::SCHEDULED, 'departed_at' => null])->save();
            $this->log($actor, $d, "การจัดส่งรอบที่ {$d->round}: ย้อนสถานะ ".ProjectDelivery::statusName($from).' → '.$d->stateLabel());

            return $d;
        });
    }

    /** PM takes the round back; a round still on the delivery department's list leaves it. */
    public function cancel(ProjectDelivery $delivery, User $actor): void
    {
        $this->assert(self::canBook($actor), 'ยกเลิกรอบส่งได้เฉพาะ Admin / PM');
        DB::transaction(function () use ($delivery, $actor) {
            $d = $this->lock($delivery);
            $this->log($actor, $d, "ยกเลิกการจัดส่งรอบที่ {$d->round} ({$d->when()})");
            if (in_array($d->status, [ProjectDelivery::WAITING, ProjectDelivery::SCHEDULED], true)) {
                $this->notices->deliveryForDepartment($d, $actor, 'PM ยกเลิกรอบส่ง', todo: false);
            }
            $d->delete();
        });
    }

    /** "18–22/11/2569 · 2 ตู้ (WH/MO/01-1, WH/MO/01-2) · 6 ล้อเครน · 3กข-1234 · 3 คน"; a draft without cabinets: "ทุกตู้" */
    public function describe(ProjectDelivery $d): string
    {
        $names = $this->names($d->cabinet_ids ?? []);

        return $d->when().' · '.($names->isEmpty() ? 'ทุกตู้' : $names->count().' ตู้ ('.$names->implode(', ').')').' · '.($d->transport() ?? '-')
            .($d->crew_size ? ' · '.$d->crew_size.' คน' : '');
    }

    private function names(array $ids)
    {
        return Cabinet::whereIn('id', $ids)->orderBy('sequence')->orderBy('id')->get(['mo_no', 'cabinet_name'])->map(fn ($c) => $c->mo_no ?: $c->cabinet_name);
    }

    /** A company vehicle to go on: an active one, or the one the round already has. */
    private function vehicle(mixed $id, ProjectDelivery $d): Vehicle
    {
        $v = $id ? Vehicle::find((int) $id) : null;
        if (! $v || (! $v->is_active && $v->id !== $d->getOriginal('vehicle_id'))) {
            throw ValidationException::withMessages(['vehicle_id' => 'เลือกรถ']);
        }

        return $v;
    }

    private function lock(ProjectDelivery $d): ProjectDelivery
    {
        return ProjectDelivery::with('project', 'vehicle')->whereKey($d->id)->lockForUpdate()->firstOrFail();
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
