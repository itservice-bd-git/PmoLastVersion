<?php

namespace App\Models;

use App\Models\Concerns\GuardsClosedProject;
use App\Support\EventStatuses;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * การจัดส่ง: one round of a project's delivery (App\Services\DeliveryFlow). PM may keep it as a draft (ร่าง, e.g. from
 * the new-project page, before there are cabinets: none = every cabinet) and sends it with the day it goes, the kinds of
 * vehicle and how many people (รอจัดรถ); the delivery department puts the vehicles and the people on it (จัดรถแล้ว) or
 * sends it back for another day (ไม่รับงาน); then each cabinet is packed (packed), แจ้งเปิดบิล (billed_at), it leaves the
 * factory and arrives. Those four steps tick the cabinets' Checklist items of the same names (App\Services\DeliveryChecklist).
 */
class ProjectDelivery extends Model
{
    use Concerns\PresentsDelivery;

    use GuardsClosedProject;

    public const DRAFT = 'draft';

    public const WAITING = 'waiting';

    public const REJECTED = 'rejected';

    public const SCHEDULED = 'scheduled';   // จัดรถแล้ว

    public const DEPARTED = 'departed';

    public const DELIVERED = 'delivered';

    /** how many files ส่งถึงแล้ว takes in one save (filesOf) */
    public const FILES_MAX = 5;

    public const STATUSES = [self::DRAFT, self::WAITING, self::REJECTED, self::SCHEDULED, self::DEPARTED, self::DELIVERED];

    public const COMPANY = 'company';

    public const EXTERNAL = 'external';

    public const PICKUP = 'pickup';

    public const METHOD_LABELS = [self::COMPANY => 'รถบริษัท', self::EXTERNAL => 'ขนส่งภายนอก', self::PICKUP => 'ลูกค้ามารับเอง'];

    /** "ไม่รับงาน": the reasons offered when Settings > รถขนส่ง has none of its own (AppSetting 'delivery_reject_reasons') */
    public const REJECT_REASONS = ['รถไม่ว่างวันนั้น', 'คนไม่พอวันนั้น', 'ตู้ยังไม่พร้อม', 'รถเข้าที่ส่งไม่ได้'];

    protected $fillable = ['project_id', 'round', 'delivery_date', 'delivery_end_date', 'delivery_time', 'date_pending_at', 'status',
        'cabinet_ids', 'packed', 'billed_at', 'billed_by', 'method', 'vehicle_types', 'crew_size', 'carrier', 'site', 'map_url', 'contact_name', 'contact_phone', 'note', 'departed_at', 'delivered_at', 'review_at',
        'receiver', 'doc_no', 'issue', 'sent_by', 'scheduled_by', 'scheduled_at', 'rejected_by', 'rejected_at', 'rejected_reason', 'rejected_note', 'created_by'];

    protected function casts(): array
    {
        return ['delivery_date' => 'date', 'delivery_end_date' => 'date', 'review_at' => 'datetime', 'cabinet_ids' => 'array', 'packed' => 'array', 'vehicle_types' => 'array', 'crew_size' => 'integer',
            'billed_at' => 'datetime', 'date_pending_at' => 'datetime', 'departed_at' => 'datetime', 'delivered_at' => 'datetime', 'scheduled_at' => 'datetime', 'rejected_at' => 'datetime'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    /** The vehicles the delivery department put on it, each with its driver and people. */
    public function vehicles()
    {
        return $this->hasMany(ProjectDeliveryVehicle::class)->orderBy('sequence')->orderBy('id');
    }

    public function biller()
    {
        return $this->belongsTo(User::class, 'billed_by');
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function rejecter()
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public static function filesOf(iterable $ids): \Illuminate\Support\Collection
    {
        $ids = collect($ids)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        return CommentAttachment::whereHas('activityLog', fn ($q) => $q->where('action', 'note')->whereIn('changes->delivery_id', $ids->all()))
            ->with('activityLog:id,changes')->orderBy('id')->get()
            ->groupBy(fn ($a) => (int) ($a->activityLog->getAttribute('changes')['delivery_id'] ?? 0));   // getAttribute: inside a model ->changes is Eloquent's own
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

    /** No vehicle put on it yet (ร่าง / รอจัดรถ / ไม่รับงาน). */
    public function isOpen(): bool
    {
        return in_array($this->status, [self::DRAFT, self::WAITING, self::REJECTED], true);
    }

    /** Still to go: sent, not left yet - the steps before ออกจากโรงงาน (packing, แจ้งเปิดบิล) may happen. */
    public function beforeDeparture(): bool
    {
        return in_array($this->status, [self::WAITING, self::REJECTED, self::SCHEDULED], true);
    }

    /** Most vehicles PM may ask for on one round. */
    public const MAX_VEHICLES = 20;

    /**
     * PM's kinds of vehicle as saved: a kind once per vehicle, each kind's together in the order first picked
     * (["6 ล้อเครน", "6 ล้อเครน", "กระบะ"] = two 6 ล้อเครน and a กระบะ); null = none.
     *
     * @param  array<mixed>|null  $types
     */
    public static function normaliseTypes(?array $types): ?array
    {
        $list = collect($types ?? [])->map(fn ($t) => trim((string) $t))->filter()->take(self::MAX_VEHICLES);

        return $list->countBy()->flatMap(fn ($n, $t) => array_fill(0, $n, (string) $t))->values()->all() ?: null;
    }

    /** ["6 ล้อเครน" => 2, "กระบะ" => 1] */
    public function typeCounts(): array
    {
        return collect($this->vehicle_types ?? [])->countBy()->all();
    }

    /** Fewer vehicles on it than PM asked for (PM asked two 6 ล้อเครน, one put on it). */
    public function shortOfVehicles(): bool
    {
        return $this->method === self::COMPANY && ! $this->isOpen() && $this->vehicles->count() < count($this->vehicle_types ?? []);
    }

    /** What PM asked for: "10 ล้อ + กระบะ · 5 คน" ('' = nothing) */
    public function request(): string
    {
        return $this->method === self::PICKUP ? '' : collect([$this->method === self::COMPANY ? $this->typesText() : null, $this->crew_size ? $this->crew_size.' คน' : null])->filter()->implode(' · ');
    }

    /** The kinds of the vehicles put on it that PM did not ask for ("4 ล้อ"); none asked = none other. */
    public function otherTypes(): array
    {
        $asked = $this->vehicle_types ?? [];

        return $this->method !== self::COMPANY || ! $asked ? [] : $this->vehicles->map(fn ($v) => $v->vehicle?->type)->filter()->reject(fn ($t) => in_array($t, $asked, true))->unique()->values()->all();
    }

    public function otherType(): bool
    {
        return (bool) $this->otherTypes();
    }

    /** The people who go: each vehicle's driver, then its others. */
    public function people(): array
    {
        return $this->method === self::PICKUP ? [] : $this->vehicles->flatMap->people()->unique()->values()->all();
    }

    /** Fewer people on it than PM asked for. */
    public function shortOfPeople(): bool
    {
        return $this->method !== self::PICKUP && $this->crew_size && ! $this->isOpen() && count($this->people()) < $this->crew_size;
    }

    // ---- the steps after the vehicles: ห่อตู้ (each cabinet) → แจ้งเปิดบิล → ออกจากโรงงาน → ส่งถึงแล้ว ----

    public function isPacked(int $cabinetId): bool
    {
        return isset(($this->packed ?? [])[$cabinetId]) && in_array($cabinetId, $this->cabinet_ids ?? [], true);
    }

    public function packedCount(): int
    {
        return count(array_filter($this->cabinet_ids ?? [], fn ($id) => $this->isPacked((int) $id)));
    }

    public function allPacked(): bool
    {
        return $this->cabinet_ids && $this->packedCount() === count($this->cabinet_ids);
    }

    /**
     * Where it is in the four steps: 0 packing, 1 packed (to แจ้งเปิดบิล), 2 billed (to leave), 3 left, 4 arrived; with
     * what it reads: "ห่อ 4/6", "ห่อครบ · รอแจ้งเปิดบิล", "แจ้งเปิดบิลแล้ว", ...
     *
     * @return array{n: int, text: string}
     */
    public function step(): array
    {
        return match (true) {
            $this->status === self::DELIVERED => ['n' => 4, 'text' => self::statusName(self::DELIVERED)],
            $this->status === self::DEPARTED => ['n' => 3, 'text' => self::statusName(self::DEPARTED)],
            (bool) $this->billed_at => ['n' => 2, 'text' => 'แจ้งเปิดบิลแล้ว'],
            $this->allPacked() => ['n' => 1, 'text' => 'ห่อครบ · รอแจ้งเปิดบิล'],
            default => ['n' => 0, 'text' => 'ห่อ '.$this->packedCount().'/'.count($this->cabinet_ids ?? [])],
        };
    }

    /** Past its last day and not there yet (someone has to record what happened). */
    public function isOverdue(?Carbon $today = null): bool
    {
        return $this->status !== self::DELIVERED && $this->delivery_date && $this->lastDay()->lt(($today ?? today())->copy()->startOfDay());
    }

    /** Google Maps' own addresses: a ลิงก์ GPS must be one of them (not any link pasted by mistake). */
    public const MAP_HOSTS = ['maps.app.goo.gl', 'goo.gl', 'www.google.com', 'google.com', 'maps.google.com', 'www.google.co.th', 'google.co.th'];

    /**
     * What was typed in ลิงก์ GPS, as the link to keep: a Google Maps link as it is, coordinates ("19.91, 99.84") as a link
     * to that point; '' = none; null = not a map (refused).
     */
    public static function mapLink(?string $input): ?string
    {
        $v = trim((string) $input);
        if ($v === '') {
            return '';
        }
        if (preg_match('/^(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)$/', $v, $m) && abs((float) $m[1]) <= 90 && abs((float) $m[2]) <= 180) {
            return 'https://www.google.com/maps/search/?api=1&query='.$m[1].','.$m[2];
        }
        // a link inside the words LINE shares it with ("📍 … https://maps.app.goo.gl/…")
        if (preg_match('~https?://\S+~u', $v, $m)) {
            $v = rtrim($m[0], '.,)>');
        }
        $parts = parse_url($v);
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        $ok = in_array($parts['scheme'] ?? '', ['http', 'https'], true) && in_array($host, self::MAP_HOSTS, true)
            && (in_array($host, ['maps.app.goo.gl', 'maps.google.com'], true) || str_starts_with($path, '/maps'));

        return $ok && strlen($v) <= 500 ? $v : null;
    }

    /** The validation rule of ลิงก์ GPS (PM's form, จัดรถ / คน). */
    public static function mapRule(): array
    {
        return ['nullable', 'string', 'max:600', function ($attribute, $value, $fail) {
            if (self::mapLink($value) === null) {
                $fail('ลิงก์ GPS ต้องเป็นลิงก์ Google Maps (เช่น https://maps.app.goo.gl/…) หรือพิกัด เช่น 19.91, 99.84');
            }
        }];
    }

    /** Its first day (PM's). */
    public function firstDay(): Carbon
    {
        return $this->delivery_date;
    }

    /** Its last day: a round over several days ends on delivery_end_date. */
    public function lastDay(): Carbon
    {
        return $this->delivery_end_date && $this->delivery_end_date->gt($this->delivery_date) ? $this->delivery_end_date : $this->delivery_date;
    }

    /** @return list<string> its days, 'Y-m-d' */
    public function days(): array
    {
        $out = [];
        for ($d = $this->firstDay()->copy(), $n = 0; $d->lte($this->lastDay()) && $n < 62; $d->addDay(), $n++) {
            $out[] = $d->format('Y-m-d');
        }

        return $out;
    }
}
