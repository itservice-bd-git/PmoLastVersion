<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ChecklistStatus;
use App\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings > สีสถานะ Checklist (admin): the coloured tags checklist items can carry (App\Services\ChecklistStatuses). */
class ChecklistStatusController extends Controller
{
    public function index()
    {
        $statuses = ChecklistStatus::withCount('checklists')->orderBy('sort')->orderBy('id')->get();

        return view('settings.checklist-statuses', [
            'statuses' => $statuses,
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'groups' => $statuses->pluck('group_name')->filter()->unique()->values(),
        ]);
    }

    public function store(Request $request)
    {
        $status = ChecklistStatus::create($this->validated($request) + ['sort' => (int) ChecklistStatus::max('sort') + 1, 'is_active' => true]);

        return back()->with('success', "เพิ่ม \"{$status->name}\" แล้ว · ทุกหน้าที่มี Checklist ใช้ได้ทันที")->with('status_new', $status->id);
    }

    /** "เริ่มจากชุดตัวอย่าง": a first set to rename / recolour, only while there is none. */
    public function starter()
    {
        if (ChecklistStatus::exists()) {
            return back();
        }
        foreach ([
            ['ของสั่งนอก', 'อุปกรณ์ที่ต้องสั่งซื้อจากผู้ขายภายนอก', 'red', null],
            ['รอของ', 'สั่งแล้ว ของยังไม่เข้า', 'orange', 'การรับของ'],
            ['ของเข้าแล้ว', 'รับของครบ พร้อมใช้', 'teal', 'การรับของ'],
            ['ลูกค้าแก้แบบ', 'รอยืนยันแบบใหม่จากลูกค้า', 'violet', null],
        ] as $i => [$name, $desc, $color, $group]) {
            ChecklistStatus::create(['name' => $name, 'description' => $desc, 'color' => $color, 'group_name' => $group, 'sort' => $i + 1, 'is_active' => true]);
        }

        return back()->with('success', 'เพิ่มชุดตัวอย่าง 4 สถานะแล้ว แก้ชื่อและสีได้');
    }

    public function update(Request $request, ChecklistStatus $status)
    {
        $status->update($this->validated($request, $status));

        return back()->with('success', "บันทึก \"{$status->name}\" แล้ว");
    }

    /** Used by an item: only switched off (the items keep it, nobody can pick it again); unused: gone. */
    public function destroy(ChecklistStatus $status)
    {
        if ($status->checklists()->exists()) {
            return back()->with('error', "\"{$status->name}\" มีรายการใช้อยู่ ลบไม่ได้ ให้ปิดใช้แทน");
        }
        $status->delete();

        return back()->with('success', "ลบ \"{$status->name}\" แล้ว");
    }

    public function toggle(ChecklistStatus $status)
    {
        $status->update(['is_active' => ! $status->is_active]);

        return back()->with('success', ($status->is_active ? 'เปิดใช้' : 'ปิดใช้')." \"{$status->name}\" แล้ว");
    }

    /** ↑ / ↓: its place in every status menu. */
    public function move(Request $request, ChecklistStatus $status)
    {
        $list = ChecklistStatus::orderBy('sort')->orderBy('id')->get()->values();
        $i = $list->search(fn ($s) => $s->id === $status->id);
        $j = $request->input('dir') === 'up' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < $list->count()) {
            $ids = $list->pluck('id')->all();
            [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
            foreach ($ids as $n => $id) {
                ChecklistStatus::whereKey($id)->update(['sort' => $n + 1]);
            }
        }

        return back();
    }

    private function validated(Request $request, ?ChecklistStatus $status = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:30', Rule::unique('checklist_statuses', 'name')->ignore($status?->id)],
            'description' => ['nullable', 'string', 'max:120'],
            'color' => ['required', Rule::in(array_keys(ChecklistStatus::PALETTE))],
            'group_name' => ['nullable', 'string', 'max:40'],
            'scope' => ['required', Rule::in(['all', 'some'])],
            'department_ids' => ['array', 'required_if:scope,some'],
            'department_ids.*' => ['integer', 'exists:departments,id'],
        ], [
            'name.unique' => 'มีสถานะชื่อนี้แล้ว',
            'department_ids.required_if' => 'เลือกแผนกอย่างน้อย 1 แผนก หรือเลือก "ทุกแผนก"',
        ]);

        return [
            'name' => trim($data['name']),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'color' => $data['color'],
            'group_name' => trim((string) ($data['group_name'] ?? '')) ?: null,
            'department_ids' => $data['scope'] === 'some' ? array_values(array_map('intval', $data['department_ids'] ?? [])) : null,
        ];
    }
}
