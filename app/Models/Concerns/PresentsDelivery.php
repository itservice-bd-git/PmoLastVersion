<?php

namespace App\Models\Concerns;

use App\Models\Concerns\GuardsClosedProject;
use App\Models\CommentAttachment;
use App\Models\ProjectDelivery;
use App\Support\EventStatuses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * How a delivery round is said and shown: its state's name and colour, the method, the vehicles / people / cabinets as
 * text, its days, the navigation link, its files' viewer. Part of ProjectDelivery (a trait of it); the rules about a
 * round (its steps, what it may do, what it is short of) stay on the model.
 */
trait PresentsDelivery
{
    /**
     * The files sent with ส่งถึงแล้ว (DeliveryFlow::deliver), per round id: they are a project comment (action 'note',
     * so they show in the project's Comment tab too) whose changes carry the round's id. Oldest first.
     *
     * @param  iterable<int>  $ids
     * @return \Illuminate\Support\Collection<int, \Illuminate\Support\Collection<int, CommentAttachment>>
     */
    /** One of those files as <x-file-chip> / the panels show it: a photo or a PDF opens over the page (kind), the rest is a link. */
    public static function fileView(CommentAttachment $f): array
    {
        $ext = strtolower(pathinfo((string) $f->path, PATHINFO_EXTENSION));

        return ['url' => $f->url, 'name' => (string) $f->original_name, 'size' => $f->size_for_humans,
            'kind' => in_array($ext, ['jpg', 'jpeg', 'png'], true) ? 'image' : ($ext === 'pdf' ? 'pdf' : null),
            'ext' => strtoupper(substr($ext, 0, 4))];   // what a document's tile says (x-file-tile)
    }

    public function stateLabel(): string
    {
        return EventStatuses::name('delivery', $this->stateKey());
    }

    public function stateColor(): string
    {
        return EventStatuses::color('delivery', $this->stateKey());
    }

    /** A status as the log names it. */
    public static function statusName(string $status): string
    {
        return $status === self::SCHEDULED ? 'จัดรถแล้ว' : EventStatuses::name('delivery', $status);
    }

    /** A way of sending as the form names it: the outside carrier and the pickup carry their state's name. */
    public static function methodName(string $method): string
    {
        return $method === self::COMPANY ? self::METHOD_LABELS[$method] : EventStatuses::name('delivery', $method);
    }

    /** The kinds of vehicle PM asked for: "10 ล้อ 2 คัน + กระบะ" ('' = none) */
    public function typesText(): string
    {
        return collect($this->typeCounts())->map(fn ($n, $t) => $n > 1 ? "{$t} {$n} คัน" : $t)->implode(' + ');
    }

    /** "รถ 1/2 คัน" when short, else '' */
    public function vehiclesShortText(): string
    {
        return $this->shortOfVehicles() ? 'รถ '.$this->vehicles->count().'/'.count($this->vehicle_types).' คัน' : '';
    }

    /**
     * "10 ล้อ · 70-1234, กระบะ · 2กข-555", "ขนส่งภายนอก · ไทยขนส่ง", "ลูกค้ามารับเอง"; no vehicle put on it yet: the kinds PM
     * asked for; null = nothing
     */
    public function transport(): ?string
    {
        $cars = $this->vehicles->map->name()->implode(', ');

        return match ($this->method) {
            self::EXTERNAL => self::methodName(self::EXTERNAL).($this->carrier ? ' · '.$this->carrier : '').($cars ? ' · '.$cars : ''),
            self::PICKUP => self::methodName(self::PICKUP),
            default => $cars ?: ($this->typesText() ?: null),
        };
    }

    /** "สมชาย, วิชัย, อนันต์ (3/5 คน)"; '' = nobody yet */
    public function peopleText(): string
    {
        $p = $this->people();

        return $p ? implode(', ', $p).' ('.count($p).($this->crew_size ? '/'.$this->crew_size : '').' คน)' : '';
    }

    /** "2 ตู้", or "ทุกตู้" for a draft with none picked */
    public function cabinetsText(): string
    {
        return $this->cabinet_ids ? count($this->cabinet_ids).' ตู้' : 'ทุกตู้';
    }

    /** Where ไปที่หมุด / นำทาง goes: its ลิงก์ GPS, else a search for the site's words; null = nothing to go by. */
    public function navUrl(): ?string
    {
        return $this->map_url ?: ($this->site ? 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($this->site) : null);
    }

    /** "20/11/2569", "20–22/11/2569", "30/11/2569 – 02/12/2569" */
    public static function range(Carbon $a, Carbon $b): string
    {
        return $a->eq($b) ? $a->thaiDate() : ($a->format('Y-m') === $b->format('Y-m') ? $a->format('d').'–'.$b->thaiDate() : $a->thaiDate().' – '.$b->thaiDate());
    }

    /** "20/11/2569 · 09:00 น.", "20–22/11/2569 · 09:00 น." */
    public function when(): string
    {
        return $this->delivery_date ? self::range($this->firstDay(), $this->lastDay()).($this->delivery_time ? ' · '.$this->delivery_time.' น.' : '') : '-';
    }
}
