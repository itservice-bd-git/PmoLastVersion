<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\CabinetSubtask;
use App\Models\Department;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index()
    {
        return view('settings.departments.index', [
            'departments' => Department::withCount('users')->orderBy('name')->get(),
        ]);
    }

    public function store(\App\Http\Requests\Settings\DepartmentRequest $request)
    {
        $data = $request->validated();
        $data['is_active'] = true;
        $data['sees_all'] = $request->boolean('sees_all');

        Department::create($data);

        return back()->with('success', 'เพิ่มแผนกเรียบร้อยแล้ว');
    }

    public function update(\App\Http\Requests\Settings\DepartmentRequest $request, Department $department)
    {
        $data = $request->validated();
        $data['handoff_date_label'] = trim((string) ($data['handoff_date_label'] ?? '')) ?: null;
        $data['is_active'] = $request->boolean('is_active');
        $data['sees_all'] = $request->boolean('sees_all');

        $department->update($data);

        return back()->with('success', 'บันทึกแผนกเรียบร้อยแล้ว');
    }

    public function destroy(Department $department)
    {
        if ($department->users()->exists()) {
            return back()->with('error', 'ไม่สามารถลบแผนกที่มีผู้ใช้งานอยู่ได้');
        }

        // A Sub Task carries this department (department_id set) only while it's
        // ASSIGNED/ACCEPTED/IN_PROGRESS/COMPLETED - deleting is fine once every
        // one of those has reached COMPLETED, or there are none at all.
        if ($department->cabinetSubtasks()->where('assignment_status', '!=', CabinetSubtask::ASSIGNMENT_COMPLETED)->exists()) {
            return back()->with('error', 'ไม่สามารถลบแผนกที่มีงานค้างอยู่ได้ ต้องรอให้งานเสร็จทั้งหมดหรือไม่มีงานผูกอยู่กับแผนกนี้ก่อน');
        }

        $department->delete();

        return back()->with('success', 'ลบแผนกเรียบร้อยแล้ว');
    }
}
