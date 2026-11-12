<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The new-project page's FAT and delivery (ProjectController::store): checked with the project's own fields, saved once
 * the project exists - FAT round 1 and a draft delivery round 1, both for every cabinet.
 */
class NewProjectAppointments
{
    /**
     * The new-project page's FAT and delivery (Admin / PM only; anyone else's are ignored): the fields of the project page's
     * FAT window and delivery window, but for every cabinet (there are none yet).
     */
    public function validate(Request $request): array
    {
        if (! $request->user()->canDispatchWork()) {
            return [];
        }
        $delivery = ['delivery_end_date', 'delivery_time', 'delivery_vehicle_types', 'delivery_crew_size', 'delivery_carrier', 'delivery_site', 'delivery_contact_name', 'delivery_contact_phone', 'delivery_note'];

        return $request->validate([
            'fat_date' => ['nullable', 'date', 'required_with:fat_end_date,fat_time,fat_note'],
            'fat_end_date' => ['nullable', 'date', 'after_or_equal:fat_date'],
            'fat_time' => ['nullable', 'date_format:H:i'],
            'fat_status' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Models\ProjectFat::STATUS_LABELS))],
            'fat_note' => ['nullable', 'string', 'max:500'],
            'delivery_date' => ['nullable', 'date', 'required_with:'.implode(',', $delivery)],
            'delivery_end_date' => ['nullable', 'date', 'after_or_equal:delivery_date'],
            'delivery_time' => ['nullable', 'date_format:H:i'],
            'delivery_method' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(\App\Models\ProjectDelivery::METHOD_LABELS))],
            'delivery_vehicle_types' => ['nullable', 'array', 'max:'.\App\Models\ProjectDelivery::MAX_VEHICLES],
            'delivery_vehicle_types.*' => ['string', \Illuminate\Validation\Rule::in(\App\Models\Vehicle::types())],
            'delivery_crew_size' => ['nullable', 'integer', 'min:1', 'max:20'],
            'delivery_carrier' => ['nullable', 'string', 'max:150'],
            'delivery_site' => ['nullable', 'string', 'max:255'],
            'delivery_contact_name' => ['nullable', 'string', 'max:100'],
            'delivery_contact_phone' => ['nullable', 'string', 'max:30'],
            'delivery_note' => ['nullable', 'string', 'max:500'],
        ], [
            'fat_date.required_with' => 'ใส่วัน FAT ก่อน',
            'fat_end_date.after_or_equal' => 'วันสุดท้ายของ FAT ต้องไม่ก่อนวันแรก',
            'fat_time.date_format' => 'ใส่เวลา FAT แบบ 13:30',
            'delivery_date.required_with' => 'ใส่วันส่งก่อน',
            'delivery_end_date.after_or_equal' => 'วันสุดท้ายที่ส่งต้องไม่ก่อนวันแรก',
            'delivery_time.date_format' => 'ใส่เวลาส่งแบบ 09:00',
            'delivery_vehicle_types.max' => 'รถรวมไม่เกิน '.\App\Models\ProjectDelivery::MAX_VEHICLES.' คัน',
            'delivery_vehicle_types.*.in' => 'ไม่มีรถประเภทนี้ · เพิ่มได้ที่ ตั้งค่า › รถขนส่ง',
            'delivery_crew_size.min' => 'จำนวนคนอย่างน้อย 1 คน',
            'delivery_crew_size.max' => 'จำนวนคนไม่เกิน 20 คน',
        ]);
    }

    /** FAT round 1 for every cabinet; delivery round 1 as a draft for every cabinet (sent later from the project page). */
    public function store(Project $project, array $a, User $actor): void
    {
        $text = fn (string $k) => trim((string) ($a[$k] ?? '')) ?: null;
        if (! empty($a['fat_date'])) {
            $fat = $project->fats()->create(['round' => 1, 'fat_date' => $a['fat_date'], 'fat_end_date' => $a['fat_end_date'] ?? null, 'fat_time' => $text('fat_time'),
                'status' => $a['fat_status'] ?? \App\Models\ProjectFat::SCHEDULED, 'cabinet_ids' => null, 'note' => $text('fat_note'), 'created_by' => $actor->id]);
            ActivityLog::record($project->id, $actor->id, $project, 'fat', 'ลงวัน FAT: '.$fat->when());
            PlanningBoard::touch();
        }
        if (! empty($a['delivery_date'])) {
            $method = $a['delivery_method'] ?? \App\Models\ProjectDelivery::COMPANY;
            $company = $method === \App\Models\ProjectDelivery::COMPANY;
            app(DeliveryFlow::class)->draft($project, [
                'delivery_date' => $a['delivery_date'], 'delivery_time' => $text('delivery_time'),
                'delivery_end_date' => ! empty($a['delivery_end_date']) && $a['delivery_end_date'] > $a['delivery_date'] ? $a['delivery_end_date'] : null, 'cabinet_ids' => [], 'method' => $method,
                'vehicle_types' => $company ? \App\Models\ProjectDelivery::normaliseTypes($a['delivery_vehicle_types'] ?? []) : null,
                'crew_size' => $method !== \App\Models\ProjectDelivery::PICKUP && ! empty($a['delivery_crew_size']) ? (int) $a['delivery_crew_size'] : null,
                'carrier' => $method === \App\Models\ProjectDelivery::EXTERNAL ? $text('delivery_carrier') : null,
                'site' => $text('delivery_site'), 'contact_name' => $text('delivery_contact_name'), 'contact_phone' => $text('delivery_contact_phone'), 'note' => $text('delivery_note'),
            ], $actor);
        }
    }
}
