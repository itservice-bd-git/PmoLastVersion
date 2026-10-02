<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\JobType;
use Illuminate\Http\Request;

class JobTypeController extends Controller
{
    public function index()
    {
        return view('settings.job-types.index', [
            'jobTypes' => JobType::withCount('projects')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:job_types,name'],
            'description' => ['nullable', 'string'],
        ]);
        $data['is_active'] = true;

        JobType::create($data);

        return back()->with('success', 'เพิ่มประเภทงานเรียบร้อยแล้ว');
    }

    public function update(Request $request, JobType $jobType)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:job_types,name,'.$jobType->id],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active');

        $jobType->update($data);

        return back()->with('success', 'บันทึกประเภทงานเรียบร้อยแล้ว');
    }

    public function destroy(JobType $jobType)
    {
        if ($jobType->projects()->exists()) {
            return back()->with('error', 'ไม่สามารถลบประเภทงานที่มีโครงการใช้งานอยู่ได้');
        }

        $jobType->delete();

        return back()->with('success', 'ลบประเภทงานเรียบร้อยแล้ว');
    }
}
