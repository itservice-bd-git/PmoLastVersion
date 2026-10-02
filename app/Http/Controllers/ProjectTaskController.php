<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectTask;
use Illuminate\Http\Request;

class ProjectTaskController extends Controller
{
    public function store(Request $request, Project $project)
    {
        $data = $this->validateData($request);
        $data['sequence'] = $project->projectTasks()->max('sequence') + 1;

        $project->projectTasks()->create($data);

        return back()->with('success', 'เพิ่มงานโครงการเรียบร้อยแล้ว');
    }

    public function update(Request $request, ProjectTask $projectTask)
    {
        $data = $this->validateData($request);

        if ($data['status'] === ProjectTask::STATUS_COMPLETED) {
            $data['progress'] = 100;
        }

        $projectTask->update($data);

        return back()->with('success', 'บันทึกงานโครงการเรียบร้อยแล้ว');
    }

    public function destroy(ProjectTask $projectTask)
    {
        $projectTask->delete();

        return back()->with('success', 'ลบงานโครงการเรียบร้อยแล้ว');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'owner_id' => ['nullable', 'exists:users,id'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'status' => ['required', 'in:'.implode(',', array_keys(ProjectTask::$statuses))],
            'priority' => ['required', 'in:'.implode(',', array_keys(ProjectTask::$priorities))],
            'progress' => ['nullable', 'integer', 'min:0', 'max:100'],
            'remark' => ['nullable', 'string'],
        ]);
    }
}
