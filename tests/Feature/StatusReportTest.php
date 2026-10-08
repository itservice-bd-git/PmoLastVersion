<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PmoNotice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatusReportTest extends TestCase
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

        return User::forceCreate(['name' => 'U'.++$n, 'email' => 'rep'.$n.'@example.com', 'password' => 'secret-pass', 'role' => $role, 'department_id' => $department?->id, 'is_active' => true]);
    }

    private function subtask(Department $department, string $name, ?string $due, string $status = 'ACCEPTED'): CabinetSubtask
    {
        return CabinetSubtask::forceCreate([
            'cabinet_task_id' => $this->task->id, 'name' => $name, 'department_id' => $department->id,
            'assignment_status' => $status, 'due_date' => $due, 'status' => 'not_started', 'progress' => 0,
        ]);
    }

    private function notice(User $user, string $kind, string $title = 'T'): void
    {
        $user->notify(new PmoNotice($kind, $title, 'body', '/x'));
    }

    public function test_report_lists_overdue_then_due_soon_and_skips_far_or_done_work(): void
    {
        $this->subtask($this->busbar, 'late', '2026-09-20');
        $this->subtask($this->busbar, 'today', '2026-09-24');
        $this->subtask($this->busbar, 'soon', '2026-09-27');       // +3 days: included
        $this->subtask($this->busbar, 'far', '2026-09-28');        // +4 days: not "soon"
        $this->subtask($this->busbar, 'done', '2026-09-20', 'COMPLETED');

        $json = $this->actingAs($this->user($this->busbar))->getJson(route('status-report.show'))->assertOk()->json();

        $this->assertSame(['late', 'today', 'soon'], array_column($json['items'], 'name'));
        $this->assertTrue($json['items'][0]['overdue']);
        $this->assertSame(-4, $json['items'][0]['days_remaining']);
        $this->assertSame(4, $json['totals']['pending']);
        $this->assertSame(1, $json['totals']['completed']);
        $this->assertSame(1, $json['totals']['overdue']);   // only 'late': due today is not overdue yet, and 'done' is finished
    }

    public function test_department_user_never_sees_another_departments_work(): void
    {
        $this->subtask($this->busbar, 'mine', '2026-09-24');
        $this->subtask($this->wiring, 'theirs', '2026-09-24');

        $json = $this->actingAs($this->user($this->busbar))->getJson(route('status-report.show', ['department' => $this->wiring->id]))->assertOk()->json();

        $this->assertSame(['mine'], array_column($json['items'], 'name'));
        $this->assertSame(1, $json['totals']['pending']);
    }

    public function test_pm_sees_every_department_and_user_without_department_sees_nothing(): void
    {
        $this->subtask($this->busbar, 'a', '2026-09-24');
        $this->subtask($this->wiring, 'b', '2026-09-24');

        $pm = $this->actingAs($this->user($this->busbar, 'project_manager'))->getJson(route('status-report.show'))->json();
        $this->assertEqualsCanonicalizing(['a', 'b'], array_column($pm['items'], 'name'));

        $none = $this->actingAs($this->user(null))->getJson(route('status-report.show'))->json();
        $this->assertSame([], $none['items']);
        $this->assertSame(0, $none['totals']['pending']);
    }

    public function test_only_unread_urgent_notices_are_alerts(): void
    {
        $user = $this->user($this->busbar);
        $this->notice($user, 'assigned', 'new work');
        $this->notice($user, 'overdue', 'late');
        $this->notice($user, 'due_soon', 'soon (not urgent)');
        $this->notice($user, 'completed', 'fyi (not urgent)');
        $user->notifications()->where('data', 'like', '%"late"%')->first()->markAsRead();

        $alerts = $this->actingAs($user)->getJson(route('status-report.show'))->json('alerts');

        $this->assertSame(['new work'], array_column($alerts, 'title'));
    }

    public function test_acknowledge_marks_only_my_notices_read(): void
    {
        $me = $this->user($this->busbar);
        $other = $this->user($this->busbar);
        $this->notice($me, 'assigned');
        $this->notice($other, 'assigned');

        $mine = $me->notifications()->first()->id;
        $theirs = $other->notifications()->first()->id;

        $this->actingAs($me)->postJson(route('status-report.acknowledge'), ['ids' => [$mine, $theirs]])
            ->assertOk()->assertJson(['acknowledged' => 1]);

        $this->assertNotNull($me->notifications()->first()->read_at);
        $this->assertNull($other->notifications()->first()->read_at, "someone else's notice must stay unread");
        $this->actingAs($me)->postJson(route('status-report.acknowledge'), [])->assertUnprocessable();
    }

    public function test_guests_are_refused_and_pages_carry_the_popup(): void
    {
        $this->getJson(route('status-report.show'))->assertUnauthorized();
        $this->postJson(route('status-report.acknowledge'), ['ids' => ['x']])->assertUnauthorized();

        $this->actingAs($this->user($this->busbar))->get(route('dashboard'))->assertOk()
            ->assertSee('open-status-report', false)->assertSee('statusReport(', false)->assertSee('status-report', false);   // @js() escapes the full URL, so match the stable parts
    }
}
