<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\PlanningBoard;
use App\Services\SubtaskAssignmentService;
use App\Services\WorkScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "ดูแลแผนกเพิ่มเติม" (Settings > ผู้ใช้งาน): a head of จัดซื้อ who runs คลัง too works for both as one of their members. */
class MultiDepartmentTest extends TestCase
{
    use RefreshDatabase;

    private Department $purchasing;

    private Department $store;

    private User $head;

    private User $buyer;   // จัดซื้อ only

    private CabinetSubtask $stock;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = Department::create(['name' => 'จัดซื้อ', 'code' => 'PUR', 'is_active' => true]);
        $this->store = Department::create(['name' => 'คลัง', 'code' => 'WH', 'is_active' => true]);
        $admin = User::forceCreate(['name' => 'Admin', 'email' => 'md-admin', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($admin)->post(route('settings.users.store'), ['name' => 'หัวหน้า', 'email' => 'md-head', 'password' => 'secret-pass', 'role' => 'member',
            'department_id' => $this->purchasing->id, 'extra_department_ids' => [$this->store->id, $this->purchasing->id], 'is_active' => 1])->assertRedirect();
        $this->head = User::firstWhere('email', 'md-head');
        $this->buyer = User::forceCreate(['name' => 'ผู้ซื้อ', 'email' => 'md-buyer', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $this->purchasing->id, 'is_active' => true]);

        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $cab = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::forceCreate(['cabinet_id' => $cab->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
        $this->stock = CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => 'รับของเข้าคลัง', 'department_id' => null, 'assignment_status' => 'UNASSIGNED', 'status' => 'not_started', 'progress' => 0]);
        CabinetChecklist::forceCreate(['cabinet_subtask_id' => $this->stock->id, 'name' => 'ตรวจนับ', 'is_completed' => false, 'sequence' => 1]);
    }

    public function test_the_settings_keep_the_extra_departments_apart_from_their_own(): void
    {
        $this->assertSame([$this->store->id], $this->head->extraDepartments()->pluck('departments.id')->all(), 'their own is not one "extra"');
        $this->assertSame([$this->purchasing->id, $this->store->id], $this->head->departmentIds());
        $this->assertTrue($this->head->inDepartment($this->store->id));
        $this->assertFalse($this->buyer->inDepartment($this->store->id));
    }

    public function test_they_hear_see_and_do_the_work_of_the_department_they_look_after(): void
    {
        // work handed to คลัง: the head hears it as one of คลัง, the buyer does not
        app(SubtaskAssignmentService::class)->changeDepartment($this->stock, $this->store->id, User::firstWhere('email', 'md-admin'));
        $this->assertSame(1, $this->head->notifications()->count());
        $this->assertSame(0, $this->buyer->notifications()->count());

        // they see the project and คลัง's work there, on Planning too, and may act for it
        $stock = $this->stock->fresh();
        $this->assertTrue(WorkScope::canSeeProject($this->project, $this->head));
        $this->assertFalse(WorkScope::canSeeProject($this->project, $this->buyer));
        $this->assertSame([$stock->id], WorkScope::projectSubtasks($this->project, $this->head)->pluck('id')->all());
        $this->assertCount(1, app(PlanningBoard::class)->tasks($this->head));
        $this->actingAs($this->head)->get(route('planning.board'))->assertOk()->assertSee('"myDepts":["d_'.$this->purchasing->id.'","d_'.$this->store->id.'"]', false);

        $this->assertTrue(app(SubtaskAssignmentService::class)->actionsFor($stock, $this->head)['can_accept']);
        app(SubtaskAssignmentService::class)->accept($stock, $this->head);
        $this->assertSame('ACCEPTED', $stock->fresh()->assignment_status);
        $this->assertTrue($stock->fresh()->isChecklistEditableBy($this->head));
        $this->actingAs($this->head)->patchJson(route('checklists.toggle', CabinetChecklist::firstWhere('name', 'ตรวจนับ')), ['is_completed' => true])->assertOk();
        $this->actingAs($this->buyer)->patchJson(route('checklists.toggle', CabinetChecklist::firstWhere('name', 'ตรวจนับ')), ['is_completed' => false])->assertForbidden();
    }
}
