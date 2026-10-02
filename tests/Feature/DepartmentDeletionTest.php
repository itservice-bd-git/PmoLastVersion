<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DepartmentDeletionTest extends TestCase
{
    use RefreshDatabase;

    private CabinetTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'P', 'customer_name' => 'C', 'status' => 'planning', 'priority' => 'normal']);
        $cabinet = Cabinet::forceCreate(['project_id' => $project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'C', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $cabinet->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0]);
    }

    private function admin(): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'Admin', 'email' => 'admin'.++$n.'@example.com', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
    }

    private function subtask(Department $dept, string $status): CabinetSubtask
    {
        return CabinetSubtask::forceCreate([
            'cabinet_task_id' => $this->task->id,
            'name' => 'S',
            'department_id' => $dept->id,
            'assignment_status' => $status,
            'status' => 'not_started',
            'progress' => 0,
        ]);
    }

    public function test_department_with_no_subtasks_can_be_deleted(): void
    {
        $dept = Department::create(['name' => 'Empty', 'code' => 'EMP']);

        $this->actingAs($this->admin())->delete(route('settings.departments.destroy', $dept))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('departments', ['id' => $dept->id]);
    }

    public function test_department_where_every_subtask_is_completed_can_be_deleted(): void
    {
        $dept = Department::create(['name' => 'Done', 'code' => 'DON']);
        $this->subtask($dept, 'COMPLETED');
        $this->subtask($dept, 'COMPLETED');

        $this->actingAs($this->admin())->delete(route('settings.departments.destroy', $dept))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('departments', ['id' => $dept->id]);
    }

    public function test_department_with_pending_work_cannot_be_deleted(): void
    {
        foreach (['UNASSIGNED_VIA_ASSIGNED' => 'ASSIGNED', 'accepted' => 'ACCEPTED', 'in_progress' => 'IN_PROGRESS'] as $label => $status) {
            $dept = Department::create(['name' => 'D-'.$label, 'code' => strtoupper(substr($label, 0, 3)).rand(100, 999)]);
            $this->subtask($dept, $status);

            $this->actingAs($this->admin())->delete(route('settings.departments.destroy', $dept))
                ->assertSessionHas('error', 'ไม่สามารถลบแผนกที่มีงานค้างอยู่ได้ ต้องรอให้งานเสร็จทั้งหมดหรือไม่มีงานผูกอยู่กับแผนกนี้ก่อน');

            $this->assertDatabaseHas('departments', ['id' => $dept->id]);
        }
    }

    public function test_department_with_a_mix_of_completed_and_pending_work_cannot_be_deleted(): void
    {
        $dept = Department::create(['name' => 'Mixed', 'code' => 'MIX']);
        $this->subtask($dept, 'COMPLETED');
        $this->subtask($dept, 'IN_PROGRESS');

        $this->actingAs($this->admin())->delete(route('settings.departments.destroy', $dept))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('departments', ['id' => $dept->id]);
    }
}
