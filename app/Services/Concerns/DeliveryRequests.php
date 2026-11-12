<?php

namespace App\Services\Concerns;

use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\User;
use App\Services\DeliveryChecklist;
use App\Services\EventDates;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** DeliveryFlow, PM's side: a round drafted, sent to the delivery department, revised, cancelled. */
trait DeliveryRequests
{
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
}
