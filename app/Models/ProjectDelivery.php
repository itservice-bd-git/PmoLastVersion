<?php

namespace App\Models;

use App\Models\Concerns\GuardsClosedProject;
use App\Support\EventStatuses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * การจัดส่ง: one round of a project's delivery (App\Services\DeliveryFlow). PM may keep it as a draft (ร่าง, e.g. from
 * the new-project page, before there are cabinets: none = every cabinet) and sends it with a window of days, the kind of
 * vehicle and how many people (รอกำหนดวัน); the delivery department picks the day in it, the vehicle and the people
 * (กำหนดวันแล้ว) or sends it back (ไม่รับงาน); then it leaves the factory and arrives.
 */
class ProjectDelivery extends Model
{
    use GuardsClosedProject;

    public const DRAFT = 'draft';

    public const WAITING = 'waiting';

    public const REJECTED = 'rejected';

    public const SCHEDULED = 'scheduled';

    public const DEPARTED = 'departed';

    public const DELIVERED = 'delivered';

    public const STATUSES = [self::DRAFT, self::WAITING, self::REJECTED, self::SCHEDULED, self::DEPARTED, self::DELIVERED];

    public const COMPANY = 'company';

    public const EXTERNAL = 'external';

    public const PICKUP = 'pickup';

    public const METHOD_LABELS = [self::COMPANY => 'รถบริษัท', self::EXTERNAL => 'ขนส่งภายนอก', self::PICKUP => 'ลูกค้ามารับเอง'];

    /** "ไม่รับงาน": the reasons offered when Settings > รถขนส่ง has none of its own (AppSetting 'delivery_reject_reasons') */
    public const REJECT_REASONS = ['รถไม่ว่างทั้งช่วงวัน', 'ช่วงวันสั้นเกินไป', 'ตู้ยังไม่พร้อม', 'รถเข้าที่ส่งไม่ได้'];

    protected $fillable = ['project_id', 'round', 'window_from', 'window_to', 'delivery_date', 'delivery_time', 'status',
        'cabinet_ids', 'method', 'vehicle_id', 'vehicle_type', 'crew_size', 'driver_name', 'crew', 'carrier', 'site', 'contact_name', 'contact_phone', 'note', 'departed_at', 'delivered_at',
        'receiver', 'doc_no', 'issue', 'sent_by', 'scheduled_by', 'scheduled_at', 'rejected_by', 'rejected_at', 'rejected_reason', 'rejected_note', 'created_by'];

    protected function casts(): array
    {
        return ['window_from' => 'date', 'window_to' => 'date', 'delivery_date' => 'date',
            'cabinet_ids' => 'array', 'crew' => 'array', 'crew_size' => 'integer', 'departed_at' => 'datetime', 'delivered_at' => 'datetime', 'scheduled_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    protected function activityProjectId(): ?int
    {
        return $this->project_id;
    }

    /** The reasons "ไม่รับงาน" offers (Settings > รถขนส่ง); "อื่น ๆ" is always there besides. */
    public static function rejectReasons(): array
    {
        return array_values(array_filter((array) AppSetting::get('delivery_reject_reasons'))) ?: self::REJECT_REASONS;
    }

    /** What the round shows (Settings > สถานะงาน, EventStatuses 'delivery'): draft / waiting / rejected / booked / external / pickup / departed / delivered. */
    public function stateKey(): string
    {
        return $this->status === self::SCHEDULED ? (['company' => 'booked', 'external' => 'external', 'pickup' => 'pickup'][$this->method] ?? 'booked') : $this->status;
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
        return $status === self::SCHEDULED ? 'กำหนดวันส่ง' : EventStatuses::name('delivery', $status);
    }

    /** A way of sending as the form names it: the outside carrier and the pickup carry their state's name. */
    public static function methodName(string $method): string
    {
        return $method === self::COMPANY ? self::METHOD_LABELS[$method] : EventStatuses::name('delivery', $method);
    }

    /** No day picked: PM's window is all there is (ร่าง / รอกำหนดวัน / ไม่รับงาน). */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::DRAFT, self::WAITING, self::REJECTED], true);
    }

    /** "6 ล้อเครน · 3กข-1234", "ขนส่งภายนอก · ไทยขนส่ง", "ลูกค้ามารับเอง"; no vehicle picked yet: the kind PM asked for; null = nothing */
    public function transport(): ?string
    {
        return match ($this->method) {
            self::EXTERNAL => self::methodName(self::EXTERNAL).($this->carrier ? ' · '.$this->carrier : ''),
            self::PICKUP => self::methodName(self::PICKUP),
            default => $this->vehicle?->label() ?? $this->vehicle_type,
        };
    }

    /** What PM asked for: "6 ล้อเครน · 3 คน" ('' = nothing) */
    public function request(): string
    {
        return $this->method === self::COMPANY ? collect([$this->vehicle_type, $this->crew_size ? $this->crew_size.' คน' : null])->filter()->implode(' · ') : '';
    }

    /** The picked vehicle is not of the kind PM asked for. */
    public function otherType(): bool
    {
        return $this->method === self::COMPANY && $this->vehicle_type && $this->vehicle && $this->vehicle->type && $this->vehicle->type !== $this->vehicle_type;
    }

    /** The people who go: the driver first, then the others. */
    public function people(): array
    {
        return $this->method === self::PICKUP ? [] : array_values(array_filter([$this->driver_name, ...($this->crew ?? [])]));
    }

    /** "สมชาย, วิชัย, อนันต์ (3/3 คน)" - the driver first; '' = nobody yet */
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

    /** Past its day and not there yet (someone has to record what happened), or its window ran out with no day picked. */
    public function isOverdue(?Carbon $today = null): bool
    {
        return $this->status !== self::DELIVERED && $this->lastDay()->lt(($today ?? today())->copy()->startOfDay());
    }

    /** Its first day: the picked day, else the window's first. */
    public function firstDay(): Carbon
    {
        return $this->delivery_date ?? $this->window_from;
    }

    /** Its last day: the picked day, or the window's last while none is picked. */
    public function lastDay(): Carbon
    {
        return $this->delivery_date ?? $this->window_to;
    }

    /** "20/11/2569", "20–22/11/2569", "30/11/2569 – 02/12/2569" */
    public static function range(Carbon $a, Carbon $b): string
    {
        return $a->eq($b) ? $a->thaiDate() : ($a->format('Y-m') === $b->format('Y-m') ? $a->format('d').'–'.$b->thaiDate() : $a->thaiDate().' – '.$b->thaiDate());
    }

    /** PM's window: "ส่งได้ 18–22/11/2569" */
    public function windowText(): string
    {
        return 'ส่งได้ '.self::range($this->window_from, $this->window_to);
    }

    /** "20/11/2569 · 09:00 น."; no day picked yet: the window. */
    public function when(): string
    {
        return $this->delivery_date ? $this->delivery_date->thaiDate().($this->delivery_time ? ' · '.$this->delivery_time.' น.' : '') : $this->windowText();
    }
}
