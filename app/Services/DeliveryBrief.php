<?php

namespace App\Services;

use App\Models\ProjectDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * "ข้อความส่งงาน": the message the delivery department's admin used to type into LINE for the crew, written from what
 * the round already holds - in the same order and words as that message (2026-11). The admin can still change it before
 * copying (deliveries/index); only what is filled in is said. Ends with the driver's link (DriverLinkController).
 *
 * Not kept in the system yet, so the admin adds them by hand: the planned time to leave, a time to meet given as words
 * ("ช่วงบ่าย").
 */
class DeliveryBrief
{
    /** @param  Collection<int, \App\Models\Cabinet>  $cabinets  keyed by id (mo_no, cabinet_name, cabinet_type, size, quantity) */
    public static function text(ProjectDelivery $d, Collection $cabinets, ?string $link): string
    {
        $p = $d->project;
        $first = $d->firstDay();
        $last = $d->lastDay();
        $lines = [];

        // the days: one day, or the trip (the first day on the road, the last at the site)
        $lines[] = $last->gt($first) ? 'เดินทาง '.self::day($first).' หน้างาน '.self::day($last) : 'ส่ง '.self::day($first);
        if ($people = $d->people()) {
            $lines[] = 'คนไป : '.implode(', ', $people);
        }
        $lines[] = '';
        $lines[] = trim($p->project_no.' '.$p->project_name).($d->round > 1 ? ' (รอบ '.$d->round.')' : '');
        if ($p->customer_name) {
            $lines[] = 'ลูกค้า : '.$p->customer_name;
        }
        $lines[] = '';

        $lines[] = 'ทำการส่งตู้';
        foreach ($d->cabinet_ids ?? [] as $id) {
            $c = $cabinets->get($id);
            if (! $c) {
                continue;
            }
            $name = (string) ($c->cabinet_name ?: $c->mo_no);
            $type = $c->cabinet_type && ! str_contains($name, (string) $c->cabinet_type) ? ' ('.$c->cabinet_type.')' : '';
            $lines[] = trim($name.$type.($c->size ? ' '.$c->size : '')).' = '.max(1, (int) $c->quantity).' SET';
        }
        $lines[] = '';

        if ($p->payment_terms) {
            $lines[] = '💰 เงื่อนไขการชำระ : '.$p->payment_terms;
        }
        if ($d->site) {
            $lines[] = '📍 สถานที่ : '.$d->site;
        }
        if ($d->map_url) {
            $lines[] = '🌏 โลเคชั่น : '.$d->map_url;
        }
        if ($contact = trim($d->contact_name.' '.$d->contact_phone)) {
            $lines[] = '☎️ ติดต่อ : '.$contact;
        }
        if ($d->delivery_time) {
            $lines[] = '⏱️ เวลาที่นัดลูกค้า : '.self::day($last).' '.$d->delivery_time.' น.';
        }
        $lines[] = '🚚 รถ : '.($d->transport() ?: 'ยังไม่ได้จัดรถ');
        if ($d->note) {
            $lines[] = '📝 หมายเหตุ : '.$d->note;
        }
        $lines[] = '';
        $lines[] = '👉 โทรหาลูกค้าก่อนออกจากบริษัททุกครั้ง 👈';
        if ($link) {
            $lines[] = '';
            $lines[] = 'ถึงหน้างานแล้ว ถ่ายรูปใบส่งของที่เซ็นรับ / ตู้ แล้วกด ส่งถึงแล้ว ที่ลิงก์นี้';
            $lines[] = $link;
        }

        return implode("\n", $lines);
    }

    /** 11/11/69, as the message has always said it */
    private static function day(Carbon $d): string
    {
        return $d->format('d/m/').str_pad((string) (($d->year + 543) % 100), 2, '0', STR_PAD_LEFT);
    }
}
