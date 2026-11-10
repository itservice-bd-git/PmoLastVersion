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
    public function __construct(private NotificationService $notices, private DeliveryChecklist $checklist) {}

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
            $this->notices->deliveryForDepartment($d, $actor, 'งานจัดส่งใหม่ · จัดรถ / คน');

            return $d;
        });
    }

    /**
     * PM changes a round before it leaves. A draft stays one, or goes to the delivery department ($send). Sent back
     * (ไม่รับงาน) = sent again (another day, usually). A round with its vehicles keeps them when the day moves - the
     * delivery department is told to check they are free that day; a new way of sending takes them off (รอจัดรถ again).
     * Cabinets taken out lose their packing; once แจ้งเปิดบิล the cabinets stay as they are.
     */
    /** A round waiting with its project for sales to give the new dates (ProjectDates): packing may go on, the rest waits for the day. */
    public const PENDING_TEXT = 'รอบนี้รอฝ่ายขายแจ้งกำหนดส่ง · ห่อตู้ได้ แต่จัดรถ / แจ้งเปิดบิล / ออกรถได้เมื่อมีวันแล้ว';

    public function revise(ProjectDelivery $delivery, array $data, User $actor, bool $send = false, array $move = []): ProjectDelivery
    {
        $this->assert(self::canBook($actor), 'แก้รอบส่งได้เฉพาะ Admin / PM');

        return DB::transaction(function () use ($delivery, $data, $actor, $send, $move) {
            $d = $this->lock($delivery);
            $this->assert(in_array($d->status, [ProjectDelivery::DRAFT, ProjectDelivery::WAITING, ProjectDelivery::REJECTED, ProjectDelivery::SCHEDULED], true),
                'รอบนี้ออกจากโรงงานแล้ว แก้วัน / รถไม่ได้ · ย้อนสถานะได้ที่หน้า "งานจัดส่ง"');
            if ($d->status === ProjectDelivery::DRAFT) {
                $d->fill($data + ['date_pending_at' => null] + ($send ? ['status' => ProjectDelivery::WAITING, 'sent_by' => $actor->id] : []))->save();   // saved with its day: no longer waiting
                $this->log($actor, $d, ($send ? "ส่งให้แผนกจัดส่ง รอบที่ {$d->round}: " : "แก้ร่างการจัดส่ง รอบที่ {$d->round}: ").$this->describe($d));
                if ($send) {
                    $this->notices->deliveryForDepartment($d, $actor, 'งานจัดส่งใหม่ · จัดรถ / คน');
                }

                return $d;
            }
            $before = ['days' => EventDates::snapshot($d), 'when' => $d->when(), 'request' => $d->request(), 'cabinets' => array_map('intval', $d->cabinet_ids ?? []), 'method' => $d->method, 'status' => $d->status];
            if ($d->billed_at && isset($data['cabinet_ids']) && array_values(array_map('intval', $data['cabinet_ids'])) != $before['cabinets']) {
                throw ValidationException::withMessages(['cabinet_ids' => 'รอบนี้แจ้งเปิดบิลแล้ว เปลี่ยนตู้ไม่ได้ · ให้แผนกจัดส่งย้อนขั้นก่อน']);
            }

            $d->fill($data + ['date_pending_at' => null]);   // PM saving the round sets its day: no longer waiting
            $resent = $d->status === ProjectDelivery::REJECTED;
            $newMethod = $d->status === ProjectDelivery::SCHEDULED && $d->method !== $before['method'];
            if ($resent) {
                $d->fill(['status' => ProjectDelivery::WAITING, 'sent_by' => $actor->id, 'rejected_by' => null, 'rejected_at' => null, 'rejected_reason' => null, 'rejected_note' => null]);
            } elseif ($newMethod) {
                $d->fill(['status' => ProjectDelivery::WAITING, 'scheduled_by' => null, 'scheduled_at' => null]);
            }
            $out = array_values(array_diff($before['cabinets'], array_map('intval', $d->cabinet_ids ?? [])));
            if ($out) {
                $d->packed = array_diff_key($d->packed ?? [], array_flip($out)) ?: null;
            }
            $d->save();
            if ($newMethod || $d->method === ProjectDelivery::PICKUP) {
                $d->vehicles()->delete();
            }
            if ($out) {
                $this->checklist->mark($out, DeliveryChecklist::PACK, false, $actor);
            }
            $d->load('vehicles.vehicle');

            $moved = $before['when'] !== $d->when();
            $kept = $moved ? app(EventDates::class)->record($d, $before['days'], $actor, $move) : null;   // ประวัติการเลื่อน
            $changes = array_filter([
                $moved ? "เลื่อนวันส่งจาก {$before['when']} เป็น {$d->when()}".EventDates::why($kept) : null,
                $before['method'] !== $d->method ? 'วิธีส่ง: '.ProjectDelivery::methodName($d->method) : null,
                $before['request'] !== $d->request() && $d->request() ? 'ขอ '.$d->request() : null,
                $before['cabinets'] !== array_map('intval', $d->cabinet_ids ?? []) ? count($d->cabinet_ids).' ตู้' : null,
            ]);
            $this->log($actor, $d, "การจัดส่งรอบที่ {$d->round}: ".($resent ? 'ส่งให้จัดส่งอีกครั้ง · '.$this->describe($d) : (implode(' · ', $changes) ?: 'แก้รายละเอียด')));

            if ($resent) {
                $this->notices->deliveryForDepartment($d, $actor, 'PM ส่งรอบส่งให้อีกครั้ง · จัดรถ / คน');
            } elseif ($newMethod) {
                $this->notices->deliveryForDepartment($d, $actor, 'PM เปลี่ยนวิธีส่ง · จัดรถ / คนใหม่');
            } elseif ($moved && $d->status === ProjectDelivery::SCHEDULED) {
                $this->notices->deliveryForDepartment($d, $actor, "PM เลื่อนวันส่งจาก {$before['when']} · ตรวจว่ารถ / คนยังว่าง");
            } elseif ($changes) {
                $this->notices->deliveryForDepartment($d, $actor, 'PM แก้รอบส่ง: '.implode(' · ', $changes), todo: false);
            }

            return $d;
        });
    }

    /**
     * The delivery department puts the vehicles on the round (PM's day; it does not pick one): each a company vehicle with
     * its driver and the people who go with it; an outside carrier gets its name and its trucks (label only); a pickup
     * needs nothing but this "รับงาน". Saved again = a correction.
     *
     * @param  array{vehicles?: list<array{vehicle_id?: ?int, label?: ?string, driver_name?: ?string, crew?: list<?string>}>, carrier?: ?string}  $data
     */
    public function assign(ProjectDelivery $delivery, array $data, User $actor): ProjectDelivery
    {
        $this->assert(self::canDeliver($actor), 'จัดรถได้เฉพาะแผนกจัดส่ง');

        return DB::transaction(function () use ($delivery, $data, $actor) {
            $d = $this->lock($delivery);
            $this->assert(! $d->date_pending_at, self::PENDING_TEXT);
            $this->assert(in_array($d->status, [ProjectDelivery::WAITING, ProjectDelivery::SCHEDULED], true), match ($d->status) {
                ProjectDelivery::REJECTED => 'รอบนี้ส่งกลับให้ PM แล้ว · รอ PM ส่งมาอีกครั้ง',
                ProjectDelivery::DRAFT => 'รอบนี้ยังเป็นร่างของ PM',
                default => 'รอบนี้ออกจากโรงงานแล้ว',
            });
            $again = $d->status === ProjectDelivery::SCHEDULED;
            $before = ['transport' => $d->transport(), 'people' => $d->peopleText(), 'other' => $d->otherTypes()];

            $rows = $this->vehicleRows($d, $data['vehicles'] ?? []);
            $d->vehicles()->delete();
            foreach ($rows as $i => $row) {
                $d->vehicles()->create($row + ['sequence' => $i + 1]);
            }
            $d->fill([
                'status' => ProjectDelivery::SCHEDULED,
                'carrier' => $d->method === ProjectDelivery::EXTERNAL ? (trim((string) ($data['carrier'] ?? '')) ?: $d->carrier) : null,
                'scheduled_by' => $actor->id, 'scheduled_at' => now(),
            ])->save();
            $d->load('vehicles.vehicle');

            $other = $d->otherTypes() && $d->otherTypes() !== $before['other'] ? 'PM ขอ '.$d->typesText().' · ใช้ '.implode(', ', $d->otherTypes()).' ด้วย' : null;
            $short = $d->shortOfPeople() ? 'คน '.count($d->people()).'/'.$d->crew_size.' (น้อยกว่าที่ขอ)' : null;
            $fewer = $d->shortOfVehicles() ? $d->vehiclesShortText().' (น้อยกว่าที่ขอ '.$d->typesText().')' : null;
            $what = ($d->transport() ?? '-').($d->people() ? ' · คน '.$d->peopleText() : '');
            if ($again && $before['transport'] === $d->transport() && $before['people'] === $d->peopleText()) {
                return $d;   // saved again with nothing new
            }
            $this->log($actor, $d, "การจัดส่งรอบที่ {$d->round}: ".($again ? 'แผนกจัดส่งแก้รถ / คน: ' : 'แผนกจัดส่งจัดรถแล้ว: ').$what.($other ? ' · '.$other : '').($fewer ? ' · '.$fewer : ''));
            $this->notices->deliveryForManager($d, $actor, $again ? 'แผนกจัดส่งเปลี่ยนรถ / คน' : ($d->method === ProjectDelivery::PICKUP ? 'แผนกจัดส่งรับงานแล้ว' : 'แผนกจัดส่งจัดรถแล้ว'),
                collect([$what, $other, $fewer, $short])->filter()->implode(' — '));

            return $d;
        });
    }

    /** "ไม่รับงาน": only before the vehicles are put on it (putting them on is accepting it), with a reason; back to PM for another day. */
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
                ? 'รอบนี้ส่งกลับให้ PM แล้ว' : 'ไม่รับงานได้เฉพาะรอบที่ยังไม่ได้จัดรถ — รอบนี้จัดรถแล้ว · ติดต่อ PM เพื่อเลื่อนหรือยกเลิก');
            $d->fill(['status' => ProjectDelivery::REJECTED, 'rejected_by' => $actor->id, 'rejected_at' => now(), 'rejected_reason' => $reason, 'rejected_note' => $note])->save();

            $why = $reason.($note ? ' · '.$note : '');
            $this->log($actor, $d, "การจัดส่งรอบที่ {$d->round}: แผนกจัดส่งไม่รับงาน — เหตุผล: {$why}");
            $this->notices->deliveryForManager($d, $actor, 'แผนกจัดส่งไม่รับงาน · เลือกวันใหม่', "เหตุผล: {$why}", todo: true);

            return $d;
        });
    }

    /**
     * ห่อตู้เสร็จสิ้น (or taken back): cabinets of the round, any time after PM sent it and before it leaves - packing waits
     * for the cabinet, not for the vehicle. Taken back only before แจ้งเปิดบิล.
     *
     * @param  list<int>  $cabinetIds
     */
    public function pack(ProjectDelivery $delivery, array $cabinetIds, bool $packed, User $actor): ProjectDelivery
    {
        $this->assert(self::canDeliver($actor), 'บันทึกได้เฉพาะแผนกจัดส่ง');

        return DB::transaction(function () use ($delivery, $cabinetIds, $packed, $actor) {
            $d = $this->lock($delivery);
            $this->assert($d->beforeDeparture(), $d->status === ProjectDelivery::DRAFT ? 'รอบนี้ยังเป็นร่างของ PM' : 'รอบนี้ออกจากโรงงานแล้ว');
            $this->assert($packed || ! $d->billed_at, 'แจ้งเปิดบิลแล้ว · ย้อนขั้นก่อนถึงจะเอาการห่อออกได้');
            $ids = array_values(array_filter(array_unique(array_map('intval', $cabinetIds)), fn ($id) => in_array($id, array_map('intval', $d->cabinet_ids ?? []), true)
                && $d->isPacked($id) !== $packed));
            if (! $ids) {
                return $d;
            }
            $map = $d->packed ?? [];
            foreach ($ids as $id) {
                if ($packed) {
                    $map[$id] = now()->format('Y-m-d H:i');
                } else {
                    unset($map[$id]);
                }
            }
            $d->packed = $map ?: null;
            $d->save();
            $this->checklist->mark($ids, DeliveryChecklist::PACK, $packed, $actor);

            $count = $d->packedCount().'/'.count($d->cabinet_ids);
            $this->log($actor, $d, "การจัดส่งรอบที่ {$d->round}: ".($packed ? 'ห่อตู้เสร็จสิ้น ' : 'เอาการห่อออก ').$this->names($ids)->implode(', ')." (ห่อแล้ว {$count})");
            if ($packed) {   // one notice per round, replaced as it goes (its group)
                $this->notices->deliveryForManager($d, $actor, $d->allPacked() ? 'ห่อตู้ครบแล้ว · รอแจ้งเปิดบิล' : "ห่อตู้แล้ว {$count}", 'ล่าสุด '.$this->names($ids)->implode(', '));
            }

            return $d;
        });
    }

    /** แจ้งเปิดบิล: once every cabinet of the round is packed; the billing department (Settings > รถขนส่ง) hears it. */
    public function bill(ProjectDelivery $delivery, User $actor): ProjectDelivery
    {
        $this->assert(self::canDeliver($actor), 'บันทึกได้เฉพาะแผนกจัดส่ง');

        return DB::transaction(function () use ($delivery, $actor) {
            $d = $this->lock($delivery);
            $this->assert(! $d->date_pending_at, self::PENDING_TEXT);
            $this->assert($d->beforeDeparture(), 'รอบนี้ออกจากโรงงานแล้ว');
            $this->assert(! $d->billed_at, 'รอบนี้แจ้งเปิดบิลแล้ว');
            $this->assert($d->allPacked(), 'ห่อตู้ให้ครบก่อน (ห่อแล้ว '.$d->packedCount().'/'.count($d->cabinet_ids ?? []).')');
            $d->fill(['billed_at' => now(), 'billed_by' => $actor->id])->save();
            $this->checklist->mark(array_map('intval', $d->cabinet_ids), DeliveryChecklist::BILL, true, $actor);

            $this->log($actor, $d, "การจัดส่งรอบที่ {$d->round}: แจ้งเปิดบิล · ".$this->describe($d));
            $this->notices->deliveryBilling($d, $actor);
            $this->notices->deliveryForManager($d, $actor, 'แจ้งเปิดบิลแล้ว');

            return $d;
        });
    }

    /** ออกจากโรงงาน: the vehicles are on it and แจ้งเปิดบิล is done. */
    public function depart(ProjectDelivery $delivery, User $actor): ProjectDelivery
    {
        $this->assert(self::canDeliver($actor), 'บันทึกได้เฉพาะแผนกจัดส่ง');

        return DB::transaction(function () use ($delivery, $actor) {
            $d = $this->lock($delivery);
            $this->assert(! $d->date_pending_at, self::PENDING_TEXT);
            $this->assert($d->status === ProjectDelivery::SCHEDULED, $d->isOpen() ? 'รอบนี้ยังไม่ได้จัดรถ' : 'รอบนี้ออกจากโรงงานแล้ว');
            $this->assert((bool) $d->billed_at, 'แจ้งเปิดบิลก่อนออกจากโรงงาน');
            $d->fill(['status' => ProjectDelivery::DEPARTED, 'departed_at' => now()])->save();
            $this->checklist->mark(array_map('intval', $d->cabinet_ids), DeliveryChecklist::DEPART, true, $actor);

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
            $this->assert(! $d->isOpen(), 'รอบนี้ยังไม่ได้จัดรถ');
            // straight from จัดรถแล้ว (it left unrecorded) still needs แจ้งเปิดบิล; one already out (rounds from before the step too) does not
            $this->assert($d->status !== ProjectDelivery::SCHEDULED || (bool) $d->billed_at, 'แจ้งเปิดบิลก่อน');
            $ids = array_values(array_intersect(array_map('intval', $d->cabinet_ids ?? []), array_map('intval', $data['cabinet_ids'] ?? [])));
            if (! $ids) {
                throw ValidationException::withMessages(['cabinet_ids' => 'ติ๊กตู้ที่ส่งถึงอย่างน้อย 1 ตู้']);
            }
            $left = array_values(array_diff(array_map('intval', $d->cabinet_ids ?? []), $ids));
            $again = $d->status === ProjectDelivery::DELIVERED;
            $at = Carbon::parse($data['delivered_date'].' '.(($data['delivered_time'] ?? null) ?: '00:00'));
            $text = fn (string $k) => trim((string) ($data[$k] ?? '')) ?: null;
            $d->fill(['status' => ProjectDelivery::DELIVERED, 'cabinet_ids' => $ids, 'packed' => array_intersect_key($d->packed ?? [], array_flip($ids)) ?: null,
                'departed_at' => $d->departed_at ?? $at, 'delivered_at' => $at,
                'receiver' => $text('receiver'), 'doc_no' => $text('doc_no'), 'issue' => $text('issue')])->save();
            $this->checklist->mark($ids, DeliveryChecklist::DEPART, true, $actor);
            $this->checklist->mark($ids, DeliveryChecklist::DELIVER, true, $actor);
            if ($left) {   // back at the factory: no longer on the way
                $this->checklist->mark($left, DeliveryChecklist::DELIVER, false, $actor);
                $this->checklist->mark($left, DeliveryChecklist::DEPART, false, $actor);
            }

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

    /**
     * ย้อนขั้น (pressed by mistake), one step: ส่งถึงแล้ว → ออกจากโรงงาน → แจ้งเปิดบิลแล้ว → ห่อครบ. The Checklist items the
     * step ticked are unticked with it. Packing is taken back per cabinet (pack()).
     */
    public function stepBack(ProjectDelivery $delivery, User $actor): ProjectDelivery
    {
        $this->assert(self::canDeliver($actor), 'ย้อนสถานะได้เฉพาะแผนกจัดส่ง');

        return DB::transaction(function () use ($delivery, $actor) {
            $d = $this->lock($delivery);
            $ids = array_map('intval', $d->cabinet_ids ?? []);
            $from = $d->step()['text'];
            if ($d->status === ProjectDelivery::DELIVERED) {
                $d->fill(['status' => ProjectDelivery::DEPARTED, 'delivered_at' => null, 'receiver' => null, 'issue' => null])->save();
                $this->checklist->mark($ids, DeliveryChecklist::DELIVER, false, $actor);
            } elseif ($d->status === ProjectDelivery::DEPARTED) {
                $d->fill(['status' => ProjectDelivery::SCHEDULED, 'departed_at' => null])->save();
                $this->checklist->mark($ids, DeliveryChecklist::DEPART, false, $actor);
            } elseif ($d->billed_at && $d->beforeDeparture()) {
                $d->fill(['billed_at' => null, 'billed_by' => null])->save();
                $this->checklist->mark($ids, DeliveryChecklist::BILL, false, $actor);
            } else {
                $this->assert(false, 'ไม่มีขั้นให้ย้อน');
            }
            $this->log($actor, $d, "การจัดส่งรอบที่ {$d->round}: ย้อนขั้น {$from} → ".$d->step()['text']);

            return $d;
        });
    }

    /** PM takes the round back; a round still on the delivery department's list leaves it, and its Checklist ticks go with it. */
    public function cancel(ProjectDelivery $delivery, User $actor): void
    {
        $this->assert(self::canBook($actor), 'ยกเลิกรอบส่งได้เฉพาะ Admin / PM');
        DB::transaction(function () use ($delivery, $actor) {
            $d = $this->lock($delivery);
            $this->log($actor, $d, "ยกเลิกการจัดส่งรอบที่ {$d->round} ({$d->when()})");
            if (in_array($d->status, [ProjectDelivery::WAITING, ProjectDelivery::SCHEDULED], true)) {
                $this->notices->deliveryForDepartment($d, $actor, 'PM ยกเลิกรอบส่ง', todo: false);
            }
            if ($d->status !== ProjectDelivery::DRAFT) {
                foreach (array_keys(DeliveryChecklist::ITEMS) as $step) {
                    $this->checklist->mark(array_map('intval', $d->cabinet_ids ?? []), $step, false, $actor);
                }
            }
            $d->vehicles()->delete();   // not left to the foreign key: prod's MyISAM tables have none
            $d->delete();
        });
    }

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
