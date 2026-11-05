<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\ProjectDelivery;
use App\Models\Vehicle;
use Illuminate\Http\Request;

/**
 * Settings > รถขนส่ง (Access 'edit.delivery': the delivery department, Admin / PM): the company's vehicles - their kinds
 * are what PM asks for when a round is booked (ProjectDeliveryController), the vehicles what the delivery department
 * picks with the day (DeliveryController) - and the reasons "ไม่รับงาน" offers.
 */
class VehicleController extends Controller
{
    public function index()
    {
        return view('settings.vehicles', [
            'vehicles' => Vehicle::orderByDesc('is_active')->orderBy('sequence')->orderBy('id')->get(),
            'used' => ProjectDelivery::whereNotNull('vehicle_id')->selectRaw('vehicle_id, count(*) as n')->groupBy('vehicle_id')->pluck('n', 'vehicle_id'),
            'reasons' => ProjectDelivery::rejectReasons(),
            'types' => Vehicle::whereNotNull('type')->distinct()->orderBy('type')->pluck('type'),
        ]);
    }

    /** One reason per line; none = the built-in ones (ProjectDelivery::REJECT_REASONS). */
    public function reasons(Request $request)
    {
        $request->validate(['reasons' => ['nullable', 'string', 'max:2000']]);
        $list = collect(preg_split('/\R/u', (string) $request->input('reasons')))->map(fn ($r) => mb_substr(trim($r), 0, 100))
            ->filter(fn ($r) => $r !== '' && $r !== 'อื่น ๆ')->unique()->values()->all();
        AppSetting::put('delivery_reject_reasons', $list);

        return back()->with('success', 'บันทึกเหตุผลไม่รับงานแล้ว');
    }

    public function store(Request $request)
    {
        $vehicle = Vehicle::create($this->validated($request) + ['sequence' => (int) Vehicle::max('sequence') + 1, 'is_active' => true]);

        return back()->with('success', "เพิ่มรถ \"{$vehicle->label()}\" แล้ว");
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $vehicle->update($this->validated($request) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('success', "บันทึก \"{$vehicle->label()}\" แล้ว");
    }

    /** Named by a delivery: only switched off (the history still says which vehicle went); unused: gone. */
    public function destroy(Vehicle $vehicle)
    {
        if ($vehicle->deliveries()->exists()) {
            $vehicle->update(['is_active' => false]);

            return back()->with('success', "\"{$vehicle->label()}\" มีในประวัติการส่งแล้ว จึงปิดไว้แทนการลบ (เลือกไม่ได้อีก ประวัติยังอยู่)");
        }
        $vehicle->delete();

        return back()->with('success', "ลบ \"{$vehicle->label()}\" แล้ว");
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['nullable', 'string', 'max:50'],
            'plate' => ['nullable', 'string', 'max:30'],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'equipment' => ['nullable', 'string', 'max:100'],
            'driver_name' => ['nullable', 'string', 'max:100'],
            'driver_phone' => ['nullable', 'string', 'max:30'],
        ], ['name.required' => 'ใส่ชื่อเรียกรถ']);

        return array_map(fn ($v) => is_string($v) ? (trim($v) ?: null) : $v, $data);
    }
}
