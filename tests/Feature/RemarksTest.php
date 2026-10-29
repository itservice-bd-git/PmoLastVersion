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
use App\Services\Remarks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** "หมายเหตุ": every remark of a cabinet's work in one list (cabinet page tab, Planning project window), only what the user may see. */
class RemarksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $wiring;   // a member of ไฟฟ้า

    private Cabinet $cabinet;

    private CabinetChecklist $breaker;

    protected function setUp(): void
    {
        parent::setUp();

        $electric = Department::create(['name' => 'ไฟฟ้า', 'code' => 'EL', 'is_active' => true]);
        $metal = Department::create(['name' => 'โลหะ', 'code' => 'ME', 'is_active' => true]);
        $this->admin = User::forceCreate(['name' => 'Admin', 'email' => 'rm-admin', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        $this->wiring = User::forceCreate(['name' => 'วิภา', 'email' => 'rm-el', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $electric->id, 'is_active' => true]);

        $project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $this->cabinet = Cabinet::forceCreate(['project_id' => $project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0, 'remark' => 'สีตู้ RAL 7035']);
        $task = CabinetTask::forceCreate(['cabinet_id' => $this->cabinet->id, 'name' => 'งานเดินสาย', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
        $el = CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => 'ติดตั้ง Breaker', 'department_id' => $electric->id, 'assignment_status' => 'ACCEPTED', 'status' => 'in_progress', 'progress' => 0, 'remark' => 'สายบางส่วนยังไม่มา']);
        $me = CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => 'พับชิ้นงาน', 'department_id' => $metal->id, 'assignment_status' => 'ACCEPTED', 'status' => 'in_progress', 'progress' => 0, 'remark' => 'รอเหล็กเข้า']);
        $this->breaker = CabinetChecklist::forceCreate(['cabinet_subtask_id' => $el->id, 'name' => 'Main 400A', 'is_completed' => false, 'sequence' => 1]);
        CabinetChecklist::forceCreate(['cabinet_subtask_id' => $me->id, 'name' => 'พ่นสี', 'is_completed' => false, 'sequence' => 1, 'remark' => 'ของโลหะ']);

        // a remark written through the app is logged, so the list can say who wrote it
        $this->actingAs($this->wiring);
        $this->breaker->update(['remark' => 'ใช้ยี่ห้อตาม Rev.B']);
    }

    public function test_the_cabinet_list_holds_every_level_and_who_wrote_it(): void
    {
        $rows = Remarks::forCabinet($this->cabinet->load('tasks.subtasks.checklists', 'tasks.subtasks.department'));

        $this->assertSame(['ตู้', 'Sub Task', 'Checklist', 'Sub Task', 'Checklist'], $rows->pluck('level')->all());
        $item = $rows->firstWhere('itemId', $this->breaker->id);
        $this->assertSame('งานเดินสาย › ติดตั้ง Breaker › Main 400A', $item['path']);
        $this->assertStringStartsWith('วิภา · ', $item['who']);
        $this->assertSame('', $rows->firstWhere('level', 'ตู้')['who'], 'a cabinet remark is not logged');
    }

    public function test_a_department_member_sees_only_their_own_departments_remarks(): void
    {
        $this->actingAs($this->wiring)->get(route('cabinets.show', $this->cabinet))->assertOk()
            ->assertSee('หมายเหตุ (3)')->assertSee('ใช้ยี่ห้อตาม Rev.B')->assertDontSee('รอเหล็กเข้า')->assertDontSee('ของโลหะ');
        $this->actingAs($this->admin)->get(route('cabinets.show', $this->cabinet))->assertOk()
            ->assertSee('หมายเหตุ (5)')->assertSee('รอเหล็กเข้า');
    }

    public function test_planning_sends_the_remarks_with_their_writer(): void
    {
        $cab = app(PlanningBoard::class)->tasks($this->wiring)[0]['subtasks'][0];

        $this->assertSame('สีตู้ RAL 7035', $cab['remark']);
        $this->assertSame(['ติดตั้ง Breaker'], collect($cab['works'])->pluck('name')->all(), 'other departments stay out');
        $item = collect($cab['checklist'])->firstWhere('id', 'k'.$this->breaker->id);
        $this->assertStringStartsWith('วิภา · ', $item['remarkBy']);
        $this->assertSame('', $cab['works'][0]['remarkBy'], 'typed in with the work: no logged edit, no writer');
    }
}
