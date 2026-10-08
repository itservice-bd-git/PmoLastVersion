<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\DateLimitRule;
use App\Models\Department;
use Illuminate\Http\Request;

/**
 * Settings > Automation: workflow rules + date limits. Admin only - these rules change what happens to
 * everyone's work, so (unlike the older Settings pages) every action here checks the role itself.
 */
class AutomationController extends Controller
{
    private function adminOnly(Request $request): void
    {
        abort_unless($request->user()->isAdmin(), 403, 'เฉพาะผู้ดูแลระบบ (Admin) เท่านั้น');
    }

    public function index(Request $request)
    {
        $this->adminOnly($request);

        return view('settings.automation.index', [
            'rules' => AutomationRule::with('department')->orderBy('id')->get(),
            'limits' => DateLimitRule::with('anchorDepartment', 'blockedDepartment')->orderBy('id')->get(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'triggers' => AutomationRule::$triggers,
            'actions' => AutomationRule::$actions,
        ]);
    }

    public function storeRule(Request $request)
    {
        $this->adminOnly($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'department_id' => ['nullable', 'exists:departments,id'],
            'trigger' => ['required', 'in:'.implode(',', array_keys(AutomationRule::$triggers))],
            'action' => ['required', 'in:'.implode(',', array_keys(AutomationRule::$actions))],
        ]);
        $data['department_id'] = $data['department_id'] ?? null;
        $data['is_active'] = true;

        AutomationRule::create($data);

        return back()->with('success', 'เพิ่มกฎอัตโนมัติเรียบร้อยแล้ว');
    }

    public function toggleRule(Request $request, AutomationRule $rule)
    {
        $this->adminOnly($request);
        $rule->update(['is_active' => ! $rule->is_active]);

        return back()->with('success', $rule->is_active ? 'เปิดใช้กฎแล้ว' : 'ปิดกฎแล้ว');
    }

    public function destroyRule(Request $request, AutomationRule $rule)
    {
        $this->adminOnly($request);
        $rule->delete();

        return back()->with('success', 'ลบกฎเรียบร้อยแล้ว');
    }

    public function storeLimit(Request $request)
    {
        $this->adminOnly($request);

        $data = $request->validate([
            'anchor_department_id' => ['required', 'exists:departments,id'],
            'blocked_department_id' => ['required', 'exists:departments,id', 'different:anchor_department_id'],
        ]);

        if (DateLimitRule::where($data)->exists()) {
            return back()->with('error', 'มีกฎจำกัดวันนี้อยู่แล้ว');
        }

        DateLimitRule::create($data + ['is_active' => true]);

        return back()->with('success', 'เพิ่มกฎจำกัดวันเรียบร้อยแล้ว');
    }

    public function toggleLimit(Request $request, DateLimitRule $limit)
    {
        $this->adminOnly($request);
        $limit->update(['is_active' => ! $limit->is_active]);

        return back()->with('success', $limit->is_active ? 'เปิดใช้กฎแล้ว' : 'ปิดกฎแล้ว');
    }

    public function destroyLimit(Request $request, DateLimitRule $limit)
    {
        $this->adminOnly($request);
        $limit->delete();

        return back()->with('success', 'ลบกฎเรียบร้อยแล้ว');
    }
}
