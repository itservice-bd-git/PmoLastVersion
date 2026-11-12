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

class MyDepartmentTest extends TestCase
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

    private function user(?Department $department, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'U'.++$n, 'email' => 'mydept'.$n.'@example.com', 'password' => 'secret-pass', 'role' => $role, 'department_id' => $department?->id, 'is_active' => true]);
    }

    private function subtask(?Department $department, string $name, ?string $start, ?string $due, string $status = 'ASSIGNED'): CabinetSubtask
    {
        return CabinetSubtask::forceCreate([
            'cabinet_task_id' => $this->task->id,
            'name' => $name,
            'department_id' => $department?->id,
            'assignment_status' => $department ? $status : 'UNASSIGNED',
            'start_date' => $start,
            'due_date' => $due,
            'status' => 'not_started',
            'progress' => 0,
        ]);
    }

    // the department's work over a window, through the Planning page's CSV export (DepartmentExportController), the
    // endpoint that still runs this scope (DepartmentSchedule); the old page's JSON endpoints were removed (2026-11)
    private function window(User $user, array $extra = [])
    {
        // Export CSV is off for a department by default (Settings > สิทธิ์การใช้งาน): on for both here
        \App\Models\AppSetting::put('department_permissions', [(string) $this->busbar->id => ['page.export' => true], (string) $this->wiring->id => ['page.export' => true]]);
        return $this->actingAs($user)->get(route('my-department.export', ['from' => '2026-08-30', 'to' => '2026-10-10'] + $extra));
    }

    /** @return \Illuminate\Support\Collection<string, array{overdue: bool}> rows keyed by Sub Task name */
    private function rows($response)
    {
        $response->assertOk();
        $lines = array_slice(array_map('str_getcsv', preg_split('/\r?\n/', trim(substr($response->streamedContent(), 3)))), 1);

        return collect($lines)->mapWithKeys(fn ($r) => [$r[5] => ['overdue' => $r[10] !== '']]);
    }

    public function test_user_only_gets_their_own_department_work(): void
    {
        $this->subtask($this->busbar, 'Mine', '2026-09-20', '2026-09-30');
        $this->subtask($this->wiring, 'Theirs', '2026-09-20', '2026-09-30');
        $this->subtask(null, 'Unassigned', '2026-09-20', '2026-09-30');

        $names = $this->rows($this->window($this->user($this->busbar)))->keys()->all();

        $this->assertSame(['Mine'], $names);
    }

    public function test_regular_user_cannot_request_another_department(): void
    {
        $theirs = $this->subtask($this->wiring, 'Theirs', '2026-09-20', '2026-09-30');
        $user = $this->user($this->busbar);

        $this->window($user, ['department_id' => $this->wiring->id])->assertForbidden();
        // Own department id is fine.
        $this->window($user, ['department_id' => $this->busbar->id])->assertOk();
    }

    public function test_user_without_department_is_refused_and_page_explains(): void
    {
        $user = $this->user(null);

        $this->window($user)->assertForbidden();
        $this->actingAs($user)->get(route('planning.board'))->assertOk()->assertSee('ยังไม่ได้ผูกกับแผนก');
    }

    public function test_admin_and_pm_can_view_other_departments(): void
    {
        $this->subtask($this->wiring, 'Theirs', '2026-09-20', '2026-09-30');

        foreach (['admin', 'project_manager'] as $role) {
            $user = $this->user($this->busbar, $role);
            $this->assertCount(1, $this->rows($this->window($user, ['department_id' => $this->wiring->id])));
            $this->assertCount(0, $this->rows($this->window($user))); // defaults to own department
        }
    }

    public function test_window_overlap_overdue_and_undated_rules(): void
    {
        $this->subtask($this->busbar, 'In window', '2026-09-20', '2026-09-30');
        $this->subtask($this->busbar, 'Spans window', '2026-08-01', '2026-12-31');
        $this->subtask($this->busbar, 'Due only', null, '2026-09-25');
        $this->subtask($this->busbar, 'Far future', '2027-01-01', '2027-01-10');
        $this->subtask($this->busbar, 'Old completed', '2026-07-01', '2026-07-10', 'COMPLETED');
        $this->subtask($this->busbar, 'Old overdue', '2026-07-01', '2026-07-10', 'ACCEPTED');
        $this->subtask($this->busbar, 'No dates', null, null);

        $items = $this->rows($this->window($this->user($this->busbar)));

        $this->assertEqualsCanonicalizing(
            ['In window', 'Spans window', 'Due only', 'Old overdue', 'No dates'],
            $items->keys()->all()
        );
        $this->assertTrue($items['Old overdue']['overdue']);
        $this->assertFalse($items['In window']['overdue']);
    }

    public function test_the_old_pages_land_on_the_one_planning_page(): void
    {
        $member = $this->user($this->busbar);

        $this->actingAs($member)->get(route('my-department.index'))->assertRedirect(route('planning.board'));
        $this->actingAs($member)->get(route('my-department.index', ['project' => 7]))->assertRedirect(route('planning.board', ['project' => 7]));
        $this->actingAs($member)->get(route('planning.index'))->assertRedirect(route('planning.board'));
        $this->app['auth']->guard()->logout();
        $this->get(route('my-department.index'))->assertRedirect(route('login'));

        // the one page carries what My Department had: the CSV export of the month on screen (the same endpoint)
        $page = $this->actingAs($member)->get(route('planning.board'))->assertOk()->assertSee('Export CSV');
        $this->assertSame(route('my-department.export'), $page->viewData('config')['urls']['export']);
    }

    public function test_window_dates_are_validated(): void
    {
        $this->window($this->user($this->busbar));   // (the permission)
        $this->actingAs($this->user($this->busbar))
            ->getJson(route('my-department.export', ['from' => 'nope', 'to' => '2026-10-01']))
            ->assertStatus(422);
    }
}
