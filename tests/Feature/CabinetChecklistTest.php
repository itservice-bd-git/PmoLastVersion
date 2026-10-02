<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Only the department a Sub Task is assigned to - and only once that
 * department has accepted the work (ACCEPTED/IN_PROGRESS) - may add, tick,
 * annotate, or remove its checklist items. See CabinetSubtask::isChecklistEditableBy().
 */
class CabinetChecklistTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Department $wiring;

    private CabinetSubtask $subtask;

    protected function setUp(): void
    {
        parent::setUp();

        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS']);
        $this->wiring = Department::create(['name' => 'Wiring', 'code' => 'WIR']);

        $project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'Test', 'customer_name' => 'C', 'status' => 'planning']);
        $cabinet = Cabinet::forceCreate(['project_id' => $project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::forceCreate(['cabinet_id' => $cabinet->id, 'name' => 'Task', 'status' => 'not_started', 'progress' => 0]);

        $this->subtask = CabinetSubtask::forceCreate([
            'cabinet_task_id' => $task->id,
            'name' => 'Prepare Busbar',
            'department_id' => $this->busbar->id,
            'assignment_status' => CabinetSubtask::ASSIGNMENT_ACCEPTED,
            'status' => 'not_started',
            'progress' => 0,
        ]);
    }

    private function user(?Department $department, string $role = User::ROLE_MEMBER): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'U'.++$n, 'email' => 'checklist'.$n.'@example.com', 'password' => 'secret-pass', 'role' => $role, 'department_id' => $department?->id, 'is_active' => true]);
    }

    private function checklist(): CabinetChecklist
    {
        return $this->subtask->checklists()->create(['name' => 'Check 1', 'is_completed' => false, 'sequence' => 1]);
    }

    // ---- Allowed: same department, work already accepted ----

    public function test_assigned_department_can_add_toggle_annotate_and_remove_checklist_once_accepted(): void
    {
        $user = $this->user($this->busbar);
        $this->actingAs($user);

        $this->post(route('checklists.store', $this->subtask), ['names' => 'Check 1'])
            ->assertSessionHas('success');
        $this->assertSame(1, $this->subtask->checklists()->count());

        $checklist = $this->subtask->checklists()->first();

        $this->patchJson(route('checklists.toggle', $checklist), ['is_completed' => true])->assertOk();
        $this->assertTrue($checklist->fresh()->is_completed);

        $this->patchJson(route('checklists.update-remark', $checklist), ['remark' => 'looks good'])->assertOk();
        $this->assertSame('looks good', $checklist->fresh()->remark);

        $this->delete(route('checklists.destroy', $checklist))->assertSessionHas('success');
        $this->assertSame(0, $this->subtask->checklists()->count());
    }

    public function test_assigned_department_can_copy_checklists_from_another_subtask_once_accepted(): void
    {
        $source = CabinetSubtask::forceCreate([
            'cabinet_task_id' => $this->subtask->cabinet_task_id,
            'name' => 'Other subtask',
            'department_id' => $this->busbar->id,
            'assignment_status' => CabinetSubtask::ASSIGNMENT_ASSIGNED,
            'status' => 'not_started',
            'progress' => 0,
        ]);
        $source->checklists()->create(['name' => 'From source', 'is_completed' => false, 'sequence' => 1]);

        $this->actingAs($this->user($this->busbar))
            ->post(route('checklists.copy', $this->subtask), ['source_subtask_id' => $source->id])
            ->assertSessionHas('success');

        $this->assertSame(1, $this->subtask->checklists()->count());
    }

    // ---- Forbidden: different department ----

    public function test_other_department_cannot_add_toggle_annotate_or_remove_checklist(): void
    {
        $checklist = $this->checklist();
        $this->actingAs($this->user($this->wiring));

        $this->post(route('checklists.store', $this->subtask), ['names' => 'Not allowed'])->assertForbidden();
        $this->patchJson(route('checklists.toggle', $checklist), ['is_completed' => true])->assertForbidden();
        $this->patchJson(route('checklists.update-remark', $checklist), ['remark' => 'nope'])->assertForbidden();
        $this->delete(route('checklists.destroy', $checklist))->assertForbidden();
        $this->post(route('checklists.copy', $this->subtask), ['source_subtask_id' => $this->subtask->id])->assertForbidden();

        $this->assertFalse($checklist->fresh()->is_completed);
        $this->assertNull($checklist->fresh()->remark);
        $this->assertSame(1, $this->subtask->checklists()->count());
    }

    // ---- Forbidden: same department, but the work has not been accepted yet ----

    public function test_own_department_cannot_touch_checklist_before_accepting_the_work(): void
    {
        $this->subtask->update(['assignment_status' => CabinetSubtask::ASSIGNMENT_ASSIGNED]);
        $checklist = $this->checklist();
        $this->actingAs($this->user($this->busbar));

        $this->post(route('checklists.store', $this->subtask), ['names' => 'Too early'])->assertForbidden();
        $this->patchJson(route('checklists.toggle', $checklist), ['is_completed' => true])->assertForbidden();

        $this->assertFalse($checklist->fresh()->is_completed);
    }

    // ---- Admin/PM bypass the department match entirely - same precedent as
    // SubtaskAssignmentService::changeDepartment() (administrative oversight),
    // unlike accept/start/complete which never bypass it for anyone. ----

    public function test_admin_from_another_department_can_still_manage_the_checklist(): void
    {
        $checklist = $this->checklist();
        $admin = $this->user($this->wiring, User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->patchJson(route('checklists.toggle', $checklist), ['is_completed' => true])
            ->assertOk();
        $this->assertTrue($checklist->fresh()->is_completed);
    }

    public function test_pm_from_another_department_can_also_manage_the_checklist(): void
    {
        $checklist = $this->checklist();
        $pm = $this->user($this->wiring, User::ROLE_PROJECT_MANAGER);

        $this->actingAs($pm)
            ->patchJson(route('checklists.toggle', $checklist), ['is_completed' => true])
            ->assertOk();
        $this->assertTrue($checklist->fresh()->is_completed);
    }

    public function test_admin_bypasses_the_not_yet_accepted_status_too(): void
    {
        $this->subtask->update(['assignment_status' => CabinetSubtask::ASSIGNMENT_ASSIGNED]);
        $checklist = $this->checklist();
        $admin = $this->user($this->wiring, User::ROLE_ADMIN);

        $this->actingAs($admin)
            ->patchJson(route('checklists.toggle', $checklist), ['is_completed' => true])
            ->assertOk();
    }

    // ---- IN_PROGRESS is editable too, not just ACCEPTED ----

    public function test_in_progress_work_is_still_editable(): void
    {
        $this->subtask->update(['assignment_status' => CabinetSubtask::ASSIGNMENT_IN_PROGRESS]);
        $checklist = $this->checklist();

        $this->actingAs($this->user($this->busbar))
            ->patchJson(route('checklists.toggle', $checklist), ['is_completed' => true])
            ->assertOk();
        $this->assertTrue($checklist->fresh()->is_completed);
    }
}
