<?php

namespace App\Http\Controllers;

use App\Models\Cabinet;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\Vehicle;
use App\Services\DeliveryFlow;
use App\Services\DeliveryWindow;
use App\Services\EventDates;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * การจัดส่ง on the project page (and Planning's project window): Admin / PM keep a round as a draft or send it to the
 * delivery department (which cabinets, the day it goes, the kinds of vehicle and how many people, where), move its day,
 * pick another day when it is sent back, or take it back. The steps after
 * that are the delivery department's (DeliveryController). A cabinet belongs to one round at a time; a round that arrives
 * keeps only the cabinets that did, the rest are free to book again.
 */
class ProjectDeliveryController extends Controller
{
    public function __construct(private DeliveryFlow $flow) {}

    /** The window on its own, for Planning's project window (it saves through store() / update() as JSON). */
    public function form(Request $request, Project $project)
    {
        $this->authorizeEdit($request);
        $d = $request->filled('delivery') ? $project->deliveries()->findOrFail($request->integer('delivery')) : null;   // ?delivery=id: that round (Planning's จัดส่ง cards)

        return view('projects.partials._delivery-window', DeliveryWindow::data($project) + ['f' => DeliveryWindow::form($project, $d)]);
    }

    public function store(Request $request, Project $project)
    {
        $this->authorizeEdit($request);
        $send = $this->sending($request);
        $data = $this->validated($request, $project, null, $send);
        $send ? $this->flow->send($project, $data, $request->user()) : $this->flow->draft($project, $data, $request->user());

        return $this->done($request, $send ? 'ส่งให้แผนกจัดส่งแล้ว' : 'บันทึกร่างการจัดส่งแล้ว');
    }

    public function update(Request $request, ProjectDelivery $delivery)
    {
        $this->authorizeEdit($request);
        $draft = $delivery->status === ProjectDelivery::DRAFT;
        $send = ! $draft || $this->sending($request);   // a round already sent stays sent
        $move = $request->validate(EventDates::rules());   // why the day moved (ประวัติการเลื่อน)
        $this->flow->revise($delivery, $this->validated($request, $delivery->project, $delivery, $send), $request->user(), $draft && $send, $move);

        return $this->done($request, match (true) {
            $draft => $send ? 'ส่งให้แผนกจัดส่งแล้ว' : 'บันทึกร่างการจัดส่งแล้ว',
            $delivery->getOriginal('status') === ProjectDelivery::REJECTED => 'ส่งให้แผนกจัดส่งอีกครั้งแล้ว',
            default => 'บันทึกการจัดส่งแล้ว',
        });
    }

    public function destroy(Request $request, ProjectDelivery $delivery)
    {
        $this->authorizeEdit($request);
        $this->flow->cancel($delivery, $request->user());

        return $this->done($request, 'ยกเลิกรอบส่งแล้ว');
    }

    private function authorizeEdit(Request $request): void
    {
        abort_unless(DeliveryFlow::canBook($request->user()), 403, 'จัดการการจัดส่งได้เฉพาะ Admin / PM');
    }

    /** The button pressed: "ส่งให้แผนกจัดส่ง" (send=1) or "บันทึกร่าง" (send=0); none = send, as before drafts. */
    private function sending(Request $request): bool
    {
        return ! $request->has('send') || $request->boolean('send');
    }

    /**
     * The fields PM sets. A draft may have no cabinets (= every cabinet) and no kind of vehicle yet; sending needs both
     * (the kinds only once Settings > รถขนส่ง has any).
     */
    public static function rules(Project $project, ?ProjectDelivery $current, bool $send, ?string $method): array
    {
        $types = Vehicle::types($current?->vehicle_types ?? []);

        return [
            'delivery_date' => ['required', 'date'],
            'delivery_end_date' => ['nullable', 'date', 'after_or_equal:delivery_date'],
            'delivery_time' => ['nullable', 'date_format:H:i'],
            'cabinet_ids' => [$send ? 'required' : 'nullable', 'array', $send ? 'min:1' : 'min:0'],
            'cabinet_ids.*' => ['integer', Rule::exists('cabinets', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'method' => ['required', Rule::in(array_keys(ProjectDelivery::METHOD_LABELS))],
            'vehicle_types' => [Rule::requiredIf($send && $method === ProjectDelivery::COMPANY && $types), 'nullable', 'array', 'max:'.ProjectDelivery::MAX_VEHICLES],
            'vehicle_types.*' => ['string', Rule::in($types)],
            'crew_size' => ['nullable', 'integer', 'min:1', 'max:20'],
        ];
    }

    public const MESSAGES = [
        'delivery_date.required' => 'เลือกวันส่ง',
        'delivery_end_date.after_or_equal' => 'วันสุดท้ายที่ส่งต้องไม่ก่อนวันแรก',
        'delivery_time.date_format' => 'ใส่เวลาแบบ 09:00',
        'cabinet_ids.required' => 'เลือกตู้ที่ส่งรอบนี้อย่างน้อย 1 ตู้',
        'vehicle_types.required' => 'เลือกประเภทรถอย่างน้อย 1 แบบ',
        'vehicle_types.max' => 'รถรวมไม่เกิน '.ProjectDelivery::MAX_VEHICLES.' คัน',
        'vehicle_types.*.in' => 'ไม่มีรถประเภทนี้ · เพิ่มได้ที่ ตั้งค่า › รถขนส่ง',
        'crew_size.min' => 'จำนวนคนอย่างน้อย 1 คน',
        'crew_size.max' => 'จำนวนคนไม่เกิน 20 คน',
    ];

    private function validated(Request $request, Project $project, ?ProjectDelivery $current, bool $send): array
    {
        $data = $request->validate(self::rules($project, $current, $send, $request->input('method')) + [
            'carrier' => ['nullable', 'string', 'max:150'],
            'site' => ['nullable', 'string', 'max:255'],
            'map_url' => ProjectDelivery::mapRule(),
            'contact_name' => ['nullable', 'string', 'max:100'],
            'contact_phone' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:500'],
        ], self::MESSAGES);

        $ids = array_values(array_unique(array_map('intval', $data['cabinet_ids'] ?? [])));
        // a cabinet goes in one round at a time
        $taken = $project->deliveries()->when($current, fn ($q) => $q->whereKeyNot($current->id))->get()
            ->flatMap(fn ($d) => collect($d->cabinet_ids ?? [])->intersect($ids)->map(fn ($id) => [$id, $d]));
        if ($taken->isNotEmpty()) {
            [$id, $d] = $taken->first();
            $name = Cabinet::whereKey($id)->value('mo_no') ?: 'ตู้';
            throw ValidationException::withMessages(['cabinet_ids' => "{$name} อยู่ในรอบที่ {$d->round} แล้ว".($d->status === ProjectDelivery::DELIVERED ? ' (ส่งถึงแล้ว)' : ' · แก้รอบนั้นก่อน')]);
        }

        $method = $data['method'];
        $text = fn (string $k) => trim((string) ($data[$k] ?? '')) ?: null;
        $company = $method === ProjectDelivery::COMPANY;

        // the vehicles and the people are the delivery department's (DeliveryFlow::assign)
        return [
            'delivery_date' => $data['delivery_date'],
            'delivery_end_date' => ! empty($data['delivery_end_date']) && $data['delivery_end_date'] > $data['delivery_date'] ? $data['delivery_end_date'] : null,
            'delivery_time' => $text('delivery_time'),
            'cabinet_ids' => $ids,
            'method' => $method,
            'vehicle_types' => $company ? ProjectDelivery::normaliseTypes($data['vehicle_types'] ?? []) : null,   // a kind once per vehicle
            'crew_size' => $method !== ProjectDelivery::PICKUP && ! empty($data['crew_size']) ? (int) $data['crew_size'] : null,
        ] + [
            'carrier' => $method === ProjectDelivery::EXTERNAL ? $text('carrier') : null,
            'site' => $text('site'), 'map_url' => ProjectDelivery::mapLink($data['map_url'] ?? '') ?: null, 'contact_name' => $text('contact_name'), 'contact_phone' => $text('contact_phone'), 'note' => $text('note'),
        ];
    }

    private function done(Request $request, string $message)
    {
        return $request->wantsJson() ? response()->json(['message' => $message]) : back()->with('success', $message);   // JSON: Planning
    }
}
