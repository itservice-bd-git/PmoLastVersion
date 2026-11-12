<?php

namespace App\Services\Concerns;

use App\Models\ActivityLog;
use App\Models\ProjectDelivery;
use App\Models\User;
use App\Services\DeliveryChecklist;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** DeliveryFlow, the delivery department's side: each step of a round (จัดรถ / คน, ห่อ, แจ้งเปิดบิล, ออกจากโรงงาน, ส่งถึงแล้ว), ไม่รับงาน, ตรวจแล้ว and a step back. */
trait DeliverySteps
{
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
                // ลิงก์ GPS: the delivery department may put it on (or change it) with the vehicles; left out = as it was
                'map_url' => array_key_exists('map_url', $data) ? (ProjectDelivery::mapLink($data['map_url']) ?: null) : $d->map_url,
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
     * ส่งถึงแล้ว: when, a problem on site, and the cabinets that arrived (the rest leave the round, free to book again).
     * Saved again = a correction. The receiver's name and the delivery note number are no longer asked (2026-11: the signed
     * note goes up as a file); a round saved before keeps the ones it has.
     *
     * $files (ใบส่งของที่เซ็นรับ, รูปหน้างาน …) go up as a project comment carrying the
     * round's id (ProjectDelivery::filesOf): seen in the Comment tab and on the round; each save adds, none is replaced.
     *
     * @param  array{delivered_date: string, delivered_time?: ?string, issue?: ?string, cabinet_ids: list<int>}  $data
     * @param  array<int, \Illuminate\Http\UploadedFile>  $files
     * @param  string|null  $via  how it came in, said in the history (the driver's link: ผ่านลิงก์คนขับ - $actor is the one who sent it)
     * @param  bool  $review  รอตรวจ (the driver's link): delivered, but PM is told only once the delivery department has looked
     *                        at it (review(), or its own ส่งถึงแล้ว / แก้ข้อมูลส่งถึง saved over it)
     */
    public function deliver(ProjectDelivery $delivery, array $data, User $actor, array $files = [], ?string $via = null, bool $review = false): ProjectDelivery
    {
        $this->assert(self::canDeliver($actor), 'บันทึกได้เฉพาะแผนกจัดส่ง');
        if ($files && ! $this->uploader->ready()) {   // before anything is written: the log is MyISAM, a failed upload would leave half a save
            throw ValidationException::withMessages(['files' => 'เซิร์ฟเวอร์เก็บไฟล์ไม่ได้ (โฟลเดอร์ uploads/comment-attachments เขียนไม่ได้) · ยังไม่ได้บันทึก · แจ้งผู้ดูแลระบบ หรือบันทึกโดยไม่แนบไฟล์']);
        }

        return DB::transaction(function () use ($delivery, $data, $actor, $files, $via, $review) {
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
            $checked = ! $review && (bool) $d->review_at;   // the department's own save over the driver's report = looked at
            $at = Carbon::parse($data['delivered_date'].' '.(($data['delivered_time'] ?? null) ?: '00:00'));
            $text = fn (string $k) => trim((string) ($data[$k] ?? '')) ?: null;
            $d->fill(['status' => ProjectDelivery::DELIVERED, 'cabinet_ids' => $ids, 'packed' => array_intersect_key($d->packed ?? [], array_flip($ids)) ?: null,
                'departed_at' => $d->departed_at ?? $at, 'delivered_at' => $at, 'review_at' => $review ? now() : null,
                'issue' => $text('issue')])->save();
            $this->checklist->mark($ids, DeliveryChecklist::DEPART, true, $actor);
            $this->checklist->mark($ids, DeliveryChecklist::DELIVER, true, $actor);
            if ($left) {   // back at the factory: no longer on the way
                $this->checklist->mark($left, DeliveryChecklist::DELIVER, false, $actor);
                $this->checklist->mark($left, DeliveryChecklist::DEPART, false, $actor);
            }

            $line = ($again ? 'แก้การส่งถึง' : ProjectDelivery::statusName(ProjectDelivery::DELIVERED)).' '.$at->thaiDate().($at->format('H:i') !== '00:00' ? ' '.$at->format('H:i').' น.' : '')
                .' · '.$this->describe($d).($d->receiver ? ' · ผู้รับ '.$d->receiver : '').($d->doc_no ? ' · ใบส่งของ '.$d->doc_no : '').($d->issue ? ' · หมายเหตุ: '.$d->issue : '')
                .($left ? ' · ไม่ได้ส่ง '.$this->names($left)->implode(', ') : '').($files ? ' · แนบไฟล์ '.count($files).' ไฟล์' : '').($via ? ' · '.$via : '').($review ? ' · รอแผนกจัดส่งตรวจ' : '').($checked ? ' · ตรวจแล้ว' : '');
            $this->log($actor, $d, "การจัดส่งรอบที่ {$d->round}: {$line}");
            if ($files) {
                $note = ActivityLog::record($d->project_id, $actor->id, $d->project, 'note',
                    "ไฟล์การจัดส่งรอบที่ {$d->round} · ".ProjectDelivery::statusName(ProjectDelivery::DELIVERED).' '.$at->thaiDate(), ['delivery_id' => $d->id]);
                foreach ($files as $file) {
                    $this->uploader->store($note, $file, $actor->id);
                }
            }
            // PM hears it arrived (again: only a problem, or files added) - not while it waits to be checked; checked by this
            // save, PM hears it as it arrives (with all the files the round has)
            if (! $review) {
                $this->arrived($d, $actor, $again && ! $checked, $checked ? ProjectDelivery::filesOf([$d->id])->get($d->id, collect())->count() : count($files), $left);
            }

            return $d;
        });
    }

    /**
     * ตรวจแล้ว · แจ้ง PM: the delivery department looked at what the driver sent (photos, the note) and found it in order;
     * PM hears it arrived now. Wrong instead: แก้ข้อมูลส่งถึง (deliver() again) or ย้อนขั้น.
     */
    public function review(ProjectDelivery $delivery, User $actor): ProjectDelivery
    {
        $this->assert(self::canDeliver($actor), 'ตรวจได้เฉพาะแผนกจัดส่ง');

        return DB::transaction(function () use ($delivery, $actor) {
            $d = $this->lock($delivery);
            $this->assert($d->status === ProjectDelivery::DELIVERED && $d->review_at, 'รอบนี้ไม่มีอะไรรอตรวจ');
            $d->fill(['review_at' => null])->save();
            $this->log($actor, $d, "การจัดส่งรอบที่ {$d->round}: แผนกจัดส่งตรวจข้อมูลจากคนขับแล้ว");
            $this->arrived($d, $actor, false, ProjectDelivery::filesOf([$d->id])->get($d->id, collect())->count(), []);

            return $d;
        });
    }

    /**
     * PM hears it arrived: ส่งถึงแล้ว with how many files to look at and the หมายเหตุ said at arrival (the notice opens the
     * panel). Only news - since 2026-11 the arrival has one หมายเหตุ, no "ปัญหาที่หน้างาน" asking PM to act.
     */
    private function arrived(ProjectDelivery $d, User $actor, bool $again, int $files, array $left): void
    {
        if ($again && ! $files) {   // corrected: PM hears it again only for files added
            return;
        }
        $attached = $files ? ($again ? 'แนบไฟล์เพิ่ม ' : 'แนบไฟล์ ').$files.' ไฟล์' : null;
        $title = ProjectDelivery::statusName(ProjectDelivery::DELIVERED).($attached ? ' · '.$attached : '');
        $extra = collect([$d->issue ? 'หมายเหตุ: '.$d->issue : null, $left ? 'ไม่ได้ส่ง '.$this->names($left)->implode(', ') : null])->filter()->implode(' · ');
        $this->notices->deliveryForManager($d, $actor, $title, $extra ?: null);
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
                $d->fill(['status' => ProjectDelivery::DEPARTED, 'delivered_at' => null, 'review_at' => null, 'receiver' => null, 'issue' => null])->save();
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
}
