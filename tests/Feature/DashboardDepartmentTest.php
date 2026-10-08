<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\DepartmentDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardDepartmentTest extends TestCase
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

        $project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'Test', 'customer_name' => 'C', 'status' => 'planning', 'priority' => 'normal']);
        $cabinet = Cabinet::forceCreate(['project_id' => $project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $cabinet->id, 'name' => 'Task', 'status' => 'not_started', 'progress' => 0]);
    }

    private function user(?Department $department, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'U'.++$n, 'email' => 'dash'.$n.'@example.com', 'password' => 'secret-pass', 'role' => $role, 'department_id' => $department?->id, 'is_active' => true]);
    }

    private function subtask(Department $department, ?string $due, string $status = 'ACCEPTED'): CabinetSubtask
    {
        return CabinetSubtask::forceCreate([
            'cabinet_task_id' => $this->task->id,
            'name' => 'S',
            'department_id' => $department->id,
            'assignment_status' => $status,
            'due_date' => $due,
            'status' => 'not_started',
            'progress' => 0,
        ]);
    }

    private function checklist(CabinetSubtask $s, bool $done): void
    {
        CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => 'c', 'is_completed' => $done, 'sequence' => 1]);
    }

    public function test_buckets_totals_and_checklist_percentage(): void
    {
        // today = 2026-09-24
        $this->subtask($this->busbar, '2026-09-20');                    // overdue
        $this->subtask($this->busbar, '2026-09-24');                    // 0 days  -> d7
        $this->subtask($this->busbar, '2026-10-01');                    // 7 days  -> d7 (boundary)
        $this->subtask($this->busbar, '2026-10-02');                    // 8 days  -> d14
        $this->subtask($this->busbar, '2026-10-15');                    // 21 days -> d21
        $this->subtask($this->busbar, '2026-10-16');                    // 22 days -> d22
        $this->subtask($this->busbar, '2026-09-01', 'COMPLETED');       // done: never overdue / bucketed
        $this->subtask($this->busbar, null);                            // undated: counted, not bucketed
        $withChecks = $this->subtask($this->busbar, '2026-10-01');
        $this->checklist($withChecks, true);
        $this->checklist($withChecks, false);

        $r = app(DepartmentDashboard::class)->build(null, today());

        $this->assertSame(9, $r['totals']['total']);
        $this->assertSame(1, $r['totals']['completed']);
        $this->assertSame(1, $r['totals']['overdue']);
        $this->assertSame(50, $r['totals']['checklist_pct']);
        $this->assertSame([1, 3, 1, 1, 1], array_column($r['buckets'], 'count'));
    }

    public function test_unassigned_work_is_not_counted(): void
    {
        CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => 'U', 'department_id' => null, 'assignment_status' => 'UNASSIGNED', 'status' => 'not_started', 'progress' => 0]);

        $this->assertSame(0, app(DepartmentDashboard::class)->build(null, today())['totals']['total']);
    }

    public function test_regular_user_is_pinned_to_own_department_even_with_a_forged_filter(): void
    {
        $this->subtask($this->busbar, '2026-09-30');
        $this->subtask($this->wiring, '2026-09-30');
        $this->subtask($this->wiring, '2026-09-30');

        $user = $this->user($this->busbar);

        foreach ([[], ['department' => $this->wiring->id], ['department' => 999]] as $query) {
            $dept = $this->actingAs($user)->get(route('dashboard', $query))->assertOk()->viewData('dept');
            $this->assertSame(1, $dept['totals']['total']);
            $this->assertSame(['Busbar'], array_column($dept['departments'], 'name'));
        }
    }

    public function test_user_without_department_sees_nothing(): void
    {
        $this->subtask($this->busbar, '2026-09-30');

        $dept = $this->actingAs($this->user(null))->get(route('dashboard'))->assertOk()->viewData('dept');

        $this->assertSame(0, $dept['totals']['total']);
        $this->assertSame([], $dept['departments']);
    }

    public function test_admin_sees_all_and_can_filter(): void
    {
        $this->subtask($this->busbar, '2026-09-30');
        $this->subtask($this->wiring, '2026-09-30');
        $admin = $this->user($this->busbar, 'admin');

        $all = $this->actingAs($admin)->get(route('dashboard'))->assertOk()->viewData('dept');
        $this->assertSame(2, $all['totals']['total']);

        $one = $this->actingAs($admin)->get(route('dashboard', ['department' => $this->wiring->id]))->assertOk()->viewData('dept');
        $this->assertSame(1, $one['totals']['total']);
        $this->assertSame(['Wiring'], array_column($one['departments'], 'name'));
    }

    public function test_my_department_dashboard_endpoint_is_scoped_and_lists_projects(): void
    {
        $this->subtask($this->busbar, '2026-09-20');                      // overdue -> project status overdue
        $this->subtask($this->busbar, '2026-10-01', 'COMPLETED');
        $this->subtask($this->wiring, '2026-09-30');

        $member = $this->user($this->busbar);
        $json = $this->actingAs($member)->getJson(route('my-department.dashboard'))->assertOk()->json();

        $this->assertSame(2, $json['totals']['total']);
        $this->assertSame(1, $json['totals']['overdue']);
        $this->assertCount(1, $json['projects']);
        $this->assertSame('overdue', $json['projects'][0]['status']);
        $this->assertSame(['Busbar'], $json['projects'][0]['departments']);
        $this->assertSame('Busbar', $json['department']['name']);

        // a department user can neither ask for another department nor for "all"
        $this->actingAs($member)->getJson(route('my-department.dashboard', ['department_id' => $this->wiring->id]))->assertForbidden();
        $this->actingAs($member)->getJson(route('my-department.dashboard', ['department_id' => 'all']))->assertForbidden();

        $pm = $this->user($this->busbar, 'project_manager');
        $all = $this->actingAs($pm)->getJson(route('my-department.dashboard', ['department_id' => 'all']))->assertOk()->json();
        $this->assertSame(3, $all['totals']['total']);
        $this->assertSame('ทุกแผนก', $all['department']['name']);
        $this->assertEqualsCanonicalizing(['Busbar', 'Wiring'], $all['projects'][0]['departments']);

        $this->app['auth']->forgetGuards();   // actingAs() sticks for the rest of the test - drop it to act as a guest
        $this->getJson(route('my-department.dashboard'))->assertUnauthorized();
    }
}
