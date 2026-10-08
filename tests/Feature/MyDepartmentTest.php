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

    private function window(User $user, array $extra = [])
    {
        return $this->actingAs($user)->getJson(route('my-department.tasks', ['from' => '2026-08-30', 'to' => '2026-10-10'] + $extra));
    }

    public function test_user_only_gets_their_own_department_work(): void
    {
        $this->subtask($this->busbar, 'Mine', '2026-09-20', '2026-09-30');
        $this->subtask($this->wiring, 'Theirs', '2026-09-20', '2026-09-30');
        $this->subtask(null, 'Unassigned', '2026-09-20', '2026-09-30');

        $names = collect($this->window($this->user($this->busbar))->assertOk()->json('items'))->pluck('name')->all();

        $this->assertSame(['Mine'], $names);
    }

    public function test_regular_user_cannot_request_another_department(): void
    {
        $theirs = $this->subtask($this->wiring, 'Theirs', '2026-09-20', '2026-09-30');
        $user = $this->user($this->busbar);

        $this->window($user, ['department_id' => $this->wiring->id])->assertForbidden();
        $this->actingAs($user)->getJson(route('my-department.show', $theirs))->assertForbidden();
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
            $this->assertCount(1, $this->window($user, ['department_id' => $this->wiring->id])->assertOk()->json('items'));
            $this->assertCount(0, $this->window($user)->json('items')); // defaults to own department
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

        $items = collect($this->window($this->user($this->busbar))->assertOk()->json('items'))->keyBy('name');

        $this->assertEqualsCanonicalizing(
            ['In window', 'Spans window', 'Due only', 'Old overdue', 'No dates'],
            $items->keys()->all()
        );
        $this->assertTrue($items['Old overdue']['is_overdue']);
        $this->assertFalse($items['In window']['is_overdue']);
    }

    public function test_overdue_near_due_and_priority_are_derived(): void
    {
        $this->subtask($this->busbar, 'Late', '2026-09-01', '2026-09-20', 'IN_PROGRESS');
        $this->subtask($this->busbar, 'Late but done', '2026-09-01', '2026-09-20', 'COMPLETED');
        $this->subtask($this->busbar, 'Soon', '2026-09-20', '2026-09-26', 'ASSIGNED');
        $this->subtask($this->busbar, 'Later', '2026-09-20', '2026-10-05', 'ASSIGNED');

        $items = collect($this->window($this->user($this->busbar))->json('items'))->keyBy('name');

        $this->assertTrue($items['Late']['is_overdue']);
        $this->assertFalse($items['Late but done']['is_overdue']);
        $this->assertTrue($items['Soon']['is_near_due']);
        $this->assertFalse($items['Later']['is_near_due']);
        $this->assertSame('high', $items['Later']['priority']); // inherited from the project
        $this->assertSame('IN_PROGRESS', $items['Late']['assignment_status']); // overdue is derived, status untouched
    }

    public function test_action_flags_follow_department_and_status(): void
    {
        $this->subtask($this->busbar, 'A', '2026-09-20', '2026-09-30', 'ASSIGNED');

        $own = collect($this->window($this->user($this->busbar))->json('items'))->first();
        $this->assertTrue($own['can_accept']);
        $this->assertFalse($own['can_start']);

        $viewer = collect($this->window($this->user($this->wiring, 'admin'), ['department_id' => $this->busbar->id])->json('items'))->first();
        $this->assertFalse($viewer['can_accept']); // admin of another department cannot accept
    }

    public function test_detail_returns_checklists_and_lock_state(): void
    {
        $subtask = $this->subtask($this->busbar, 'A', '2026-09-20', '2026-09-30', 'ACCEPTED');
        $subtask->checklists()->create(['name' => 'Check 1', 'is_completed' => false, 'sequence' => 1]);
        $user = $this->user($this->busbar);

        $item = $this->actingAs($user)->getJson(route('my-department.show', $subtask))->assertOk()->json('item');

        $this->assertTrue($item['is_department_locked']);
        $this->assertTrue($item['checklist_editable']);
        $this->assertSame(['Check 1'], collect($item['checklists'])->pluck('name')->all());
        $this->assertSame(0, $item['checklists_completed']);
        $this->assertSame(1, $item['checklists_total']);
    }

    public function test_owner_name_is_exposed_when_set(): void
    {
        $owner = $this->user($this->busbar);
        $subtask = $this->subtask($this->busbar, 'A', '2026-09-20', '2026-09-30', 'ASSIGNED');
        $subtask->update(['owner_id' => $owner->id]);

        $viewer = $this->user($this->busbar);
        $items = $this->window($viewer)->assertOk()->json('items');
        $this->assertSame($owner->name, collect($items)->firstWhere('id', $subtask->id)['owner_name']);

        $detailItem = $this->actingAs($viewer)->getJson(route('my-department.show', $subtask))->assertOk()->json('item');
        $this->assertSame($owner->name, $detailItem['owner_name']);

        // No owner set - the key is still present but null (the Blade side hides it with x-show).
        $noOwner = $this->subtask($this->busbar, 'B', '2026-09-21', '2026-09-29', 'ASSIGNED');
        $items2 = $this->window($viewer)->json('items');
        $this->assertNull(collect($items2)->firstWhere('id', $noOwner->id)['owner_name']);
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
        $this->actingAs($this->user($this->busbar))
            ->getJson(route('my-department.tasks', ['from' => 'nope', 'to' => '2026-10-01']))
            ->assertStatus(422);
    }
}
