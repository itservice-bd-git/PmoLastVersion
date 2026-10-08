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

class SubtaskStarTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Department $wiring;

    private CabinetTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-24 09:00:00');
        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS']);
        $this->wiring = Department::create(['name' => 'Wiring', 'code' => 'WIR']);

        $project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'Test', 'customer_name' => 'C', 'status' => 'planning', 'priority' => 'high']);
        $cabinet = Cabinet::forceCreate(['project_id' => $project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $cabinet->id, 'name' => 'Task', 'status' => 'not_started', 'progress' => 0]);
    }

    private function user(Department $department, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'U'.++$n, 'email' => 'star'.$n.'@example.com', 'password' => 'secret-pass', 'role' => $role, 'department_id' => $department->id, 'is_active' => true]);
    }

    private function subtask(Department $department, ?User $owner = null): CabinetSubtask
    {
        return CabinetSubtask::forceCreate([
            'cabinet_task_id' => $this->task->id, 'name' => 'S', 'department_id' => $department->id,
            'assignment_status' => 'ACCEPTED', 'start_date' => '2026-09-20', 'due_date' => '2026-09-30',
            'status' => 'not_started', 'progress' => 0, 'owner_id' => $owner?->id,
        ]);
    }

    private function items(User $user)
    {
        return collect($this->actingAs($user)->getJson(route('my-department.tasks', ['from' => '2026-08-30', 'to' => '2026-10-10']))->assertOk()->json('items'));
    }

    public function test_toggle_star_on_and_off(): void
    {
        $user = $this->user($this->busbar);
        $s = $this->subtask($this->busbar);

        $this->actingAs($user)->postJson(route('my-department.star', $s))->assertOk()->assertJson(['starred' => true]);
        $this->assertTrue($this->items($user)->firstWhere('id', $s->id)['is_starred']);

        $this->actingAs($user)->postJson(route('my-department.star', $s))->assertOk()->assertJson(['starred' => false]);
        $this->assertFalse($this->items($user)->firstWhere('id', $s->id)['is_starred']);
    }

    public function test_stars_are_personal(): void
    {
        $a = $this->user($this->busbar);
        $b = $this->user($this->busbar);
        $s = $this->subtask($this->busbar);

        $this->actingAs($a)->postJson(route('my-department.star', $s))->assertOk();

        $this->assertTrue($this->items($a)->firstWhere('id', $s->id)['is_starred']);
        $this->assertFalse($this->items($b)->firstWhere('id', $s->id)['is_starred']);
    }

    public function test_cannot_star_another_departments_work_but_admin_can(): void
    {
        $s = $this->subtask($this->wiring);

        $this->actingAs($this->user($this->busbar))->postJson(route('my-department.star', $s))->assertForbidden();
        $this->actingAs($this->user($this->busbar, 'admin'))->postJson(route('my-department.star', $s))->assertOk()->assertJson(['starred' => true]);
    }

    public function test_guest_is_redirected_and_owner_id_is_exposed(): void
    {
        $owner = $this->user($this->busbar);
        $s = $this->subtask($this->busbar, $owner);

        $this->postJson(route('my-department.star', $s))->assertUnauthorized();
        $this->assertSame($owner->id, $this->items($owner)->firstWhere('id', $s->id)['owner_id']);
    }
}
