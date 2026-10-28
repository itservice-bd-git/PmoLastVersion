<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\CabinetTemplate;
use App\Models\ChecklistNoticeRule;
use App\Models\Department;
use App\Models\User;
use App\Services\ChecklistNoticeRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Settings > แจ้งเตือน Checklist (route: admin): the rules App\Services\ChecklistNoticeRules sends by. */
class ChecklistNoticeRuleController extends Controller
{
    public function index()
    {
        return view('settings.notice-rules', [
            'rules' => ChecklistNoticeRule::with('department:id,name', 'cabinetTemplate:id,name')->orderBy('department_id')->orderBy('id')->get(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'templates' => CabinetTemplate::orderBy('name')->get(['id', 'name']),
            'users' => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'department_id']),
        ]);
    }

    public function store(Request $request)
    {
        ChecklistNoticeRule::create($this->validated($request));

        return back()->with('success', 'เพิ่มกฎแจ้งเตือนแล้ว');
    }

    public function update(Request $request, ChecklistNoticeRule $rule)
    {
        // the on / off switch in the list sends only that
        $rule->update($request->has('toggle') ? ['is_active' => ! $rule->is_active] : $this->validated($request));

        return back()->with('success', $request->has('toggle') ? ($rule->is_active ? 'เปิดใช้กฎแล้ว' : 'ปิดกฎแล้ว') : 'บันทึกกฎแล้ว');
    }

    public function destroy(ChecklistNoticeRule $rule)
    {
        $rule->delete();

        return back()->with('success', 'ลบกฎแล้ว');
    }

    /** "ลองส่งหาฉัน": a sample of the rule's notice to the admin trying it. */
    public function test(Request $request, ChecklistNoticeRule $rule, ChecklistNoticeRules $rules)
    {
        $rules->test($rule, $request->user());

        return back()->with('success', 'ส่งตัวอย่างเข้ากระดิ่งของคุณแล้ว');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'event' => ['required', Rule::in(array_keys(ChecklistNoticeRule::EVENTS))],
            'department_id' => ['required', 'exists:departments,id'],
            'cabinet_template_id' => ['nullable', 'exists:cabinet_templates,id'],
            'days' => ['nullable', 'integer', 'min:1', 'max:60', Rule::requiredIf(in_array($request->input('event'), ['before', 'late'], true))],
            'repeat' => ['nullable', 'boolean'],
            'message' => ['required', 'string', 'max:255'],
            'recipients' => ['required', 'array', 'min:1'],
            'recipients.*' => ['string', 'regex:/^(linked|owner|pm|dept:\d+|user:\d+)$/'],
            'is_active' => ['nullable', 'boolean'],
        ], ['recipients.required' => 'เลือกผู้รับอย่างน้อย 1 อย่าง', 'days.required' => 'ใส่จำนวนวัน']);

        $daily = in_array($data['event'], ['before', 'late'], true);

        return [
            'event' => $data['event'],
            'department_id' => (int) $data['department_id'],
            'cabinet_template_id' => $data['cabinet_template_id'] ?? null,
            'days' => $daily ? (int) $data['days'] : null,
            'repeat' => $data['event'] === 'late' && $request->boolean('repeat'),
            'message' => trim($data['message']),
            'recipients' => array_values(array_unique($data['recipients'])),
            'is_active' => $request->boolean('is_active', true),
        ];
    }
}
