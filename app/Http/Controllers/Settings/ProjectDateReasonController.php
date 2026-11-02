<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ProjectDateChange;
use App\Models\ProjectDateReason;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings > สาเหตุการเลื่อน (admin): the reasons offered when a project's dates are moved (App\Services\ProjectDates). */
class ProjectDateReasonController extends Controller
{
    public function index()
    {
        $used = ProjectDateChange::whereNotNull('reason_id')->selectRaw('reason_id, count(*) as n')->groupBy('reason_id')->pluck('n', 'reason_id');

        return view('settings.date-reasons', [
            'reasons' => ProjectDateReason::orderBy('sequence')->orderBy('id')->get(),
            'used' => $used,
        ]);
    }

    public function store(Request $request)
    {
        $reason = ProjectDateReason::create($this->validated($request) + ['sequence' => (int) ProjectDateReason::max('sequence') + 1, 'is_active' => true]);

        return back()->with('success', "เพิ่มสาเหตุ \"{$reason->name}\" แล้ว");
    }

    public function update(Request $request, ProjectDateReason $reason)
    {
        $reason->update($this->validated($request) + ['is_active' => $request->boolean('is_active')]);

        return back()->with('success', "บันทึก \"{$reason->name}\" แล้ว");
    }

    /** Used by a change: only switched off (its history keeps the name); unused: gone. */
    public function destroy(ProjectDateReason $reason)
    {
        if (ProjectDateChange::where('reason_id', $reason->id)->exists()) {
            $reason->update(['is_active' => false]);

            return back()->with('success', "\"{$reason->name}\" มีในประวัติการเลื่อนแล้ว จึงปิดไว้แทนการลบ (เลือกไม่ได้อีก ประวัติยังอยู่)");
        }
        $reason->delete();

        return back()->with('success', "ลบ \"{$reason->name}\" แล้ว");
    }

    /** ↑ / ↓ */
    public function move(Request $request, ProjectDateReason $reason)
    {
        $list = ProjectDateReason::orderBy('sequence')->orderBy('id')->get()->values();
        $i = $list->search(fn ($r) => $r->id === $reason->id);
        $j = $request->input('dir') === 'up' ? $i - 1 : $i + 1;
        if (isset($list[$j])) {
            [$list[$i], $list[$j]] = [$list[$j], $list[$i]];
            $list->each(fn ($r, $n) => $r->sequence === $n + 1 ?: $r->update(['sequence' => $n + 1]));
        }

        return back();
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['required', Rule::in(array_keys(ProjectDateReason::COLORS))],
        ]);
    }
}
