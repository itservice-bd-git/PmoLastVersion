<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ProjectModalTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private Department $wiring;

    private Project $project;

    private CabinetTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 09:00:00');
        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BUS']);
        $this->wiring = Department::create(['name' => 'Wiring', 'code' => 'WIR']);

        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'Test', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'high', 'start_date' => '2026-10-01', 'due_date' => '2026-10-31']);
        $cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $cabinet->id, 'name' => 'Task', 'status' => 'not_started', 'progress' => 0]);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'U'.++$n, 'email' => 'modal'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    private function subtask(Department $d, string $due = '2026-10-10', string $status = 'ACCEPTED', int $checks = 2, int $done = 1): CabinetSubtask
    {
        $s = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => $d->name.' work', 'department_id' => $d->id, 'assignment_status' => $status, 'due_date' => $due, 'status' => 'not_started', 'progress' => 0]);
        for ($i = 1; $i <= $checks; $i++) {
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => "c$i", 'is_completed' => $i <= $done, 'sequence' => $i]);
        }

        return $s;
    }

    public function test_pm_sees_the_whole_project_and_a_department_user_only_their_part(): void
    {
        $this->subtask($this->busbar);
        $this->subtask($this->wiring, '2026-10-20');
        $this->subtask($this->wiring, '2026-10-02');                 // overdue-ish? due before today (10-07), ACCEPTED -> overdue
        ActivityLog::record($this->project->id, null, $this->project, 'updated', 'system change');
        ActivityLog::record($this->project->id, null, $this->project, 'note', 'a comment');

        $pm = $this->user($this->busbar, 'project_manager');
        $json = $this->actingAs($pm)->getJson(route('my-department.project', $this->project))->assertOk()->json();

        $this->assertSame('P-1', $json['project']['project_no']);
        $this->assertTrue($json['project']['can_edit']);
        $this->assertEqualsCanonicalizing(['Busbar', 'Wiring'], array_column($json['departments'], 'name'));
        $this->assertCount(1, $json['cabinets']);
        $this->assertCount(3, $json['cabinets'][0]['items']);
        $this->assertSame(1, collect($json['departments'])->firstWhere('name', 'Wiring')['overdue']);
        $descriptions = array_column($json['activity'], 'description');
        $this->assertContains('system change', $descriptions, 'PMO sees the system log...');
        $this->assertContains('a comment', $descriptions, '...as well as comments');
        $this->assertSame(1, collect($json['activity'])->where('action', 'created')->count(), "only the Project's own creation - not one row per checklist/sub task");
        $this->assertNotEmpty($json['alert_departments']);

        $member = $this->user($this->busbar);
        $mine = $this->actingAs($member)->getJson(route('my-department.project', $this->project))->assertOk()->json();
        $this->assertSame(['Busbar'], array_column($mine['departments'], 'name'));
        $this->assertCount(1, $mine['cabinets'][0]['items']);
        $this->assertFalse($mine['project']['can_edit']);
        $this->assertSame(['note'], array_unique(array_column($mine['activity'], 'action')), 'department users only see the discussion');
        $this->assertSame(['a comment'], array_column($mine['activity'], 'description'));
        $this->assertSame([], $mine['alert_departments']);
    }

    public function test_a_user_with_no_work_in_the_project_is_refused(): void
    {
        $this->subtask($this->wiring);

        $this->actingAs($this->user($this->busbar))->getJson(route('my-department.project', $this->project))->assertForbidden();
        $this->actingAs($this->user(null))->getJson(route('my-department.project', $this->project))->assertForbidden();
        $this->actingAs($this->user($this->busbar))->postJson(route('my-department.project.note', $this->project), ['note' => 'x'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson(route('my-department.project', $this->project))->assertUnauthorized();
    }

    public function test_autosave_is_for_admin_and_pm_only_and_validates(): void
    {
        $this->subtask($this->busbar);
        $pm = $this->user($this->busbar, 'project_manager');
        $member = $this->user($this->busbar);

        $this->actingAs($member)->patchJson(route('my-department.project.update', $this->project), ['priority' => 'low'])->assertForbidden();
        $this->assertSame('high', $this->project->fresh()->priority);

        $this->actingAs($pm)->patchJson(route('my-department.project.update', $this->project), ['priority' => 'urgent', 'project_name' => 'Renamed'])
            ->assertOk()->assertJsonPath('project.priority', 'urgent');
        $p = $this->project->fresh();
        $this->assertSame('urgent', $p->priority);
        $this->assertSame('Renamed', $p->project_name);
        $this->assertSame('in_progress', $p->status, 'fields that were not sent stay as they were');

        $this->actingAs($pm)->patchJson(route('my-department.project.update', $this->project), ['status' => 'bogus'])->assertUnprocessable();
        $this->actingAs($pm)->patchJson(route('my-department.project.update', $this->project), ['project_name' => ''])->assertUnprocessable();

        // date order is checked against the saved value of the field not sent
        $this->actingAs($pm)->patchJson(route('my-department.project.update', $this->project), ['due_date' => '2026-09-01'])->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->actingAs($pm)->patchJson(route('my-department.project.update', $this->project), ['start_date' => '2026-11-15'])->assertUnprocessable();
        $this->actingAs($pm)->patchJson(route('my-department.project.update', $this->project), ['start_date' => '2026-10-05', 'due_date' => '2026-12-01'])->assertOk();
        $this->assertSame('2026-12-01', $this->project->fresh()->due_date->format('Y-m-d'));
        $this->actingAs($pm)->patchJson(route('my-department.project.update', $this->project), ['due_date' => null])->assertOk();
    }

    public function test_comments_are_logged_and_only_pm_can_alert_a_department(): void
    {
        $this->subtask($this->busbar);
        $this->subtask($this->wiring);
        $member = $this->user($this->busbar);
        $pm = $this->user($this->busbar, 'project_manager');
        $wiringUser = $this->user($this->wiring);
        $busbarMate = $this->user($this->busbar);

        $this->actingAs($member)->postJson(route('my-department.project.note', $this->project), ['note' => 'hello team'])
            ->assertOk()->assertJsonPath('activity.is_note', true)->assertJsonPath('activity.description', 'hello team')->assertJsonPath('notified', 0);
        $this->assertSame(1, ActivityLog::where('project_id', $this->project->id)->where('action', 'note')->count());

        $this->actingAs($member)->postJson(route('my-department.project.note', $this->project), ['note' => 'x', 'alert_department_id' => $this->wiring->id])->assertForbidden();
        $this->assertSame(0, $wiringUser->notifications()->count());

        $this->actingAs($pm)->postJson(route('my-department.project.note', $this->project), ['note' => 'urgent: check busbar sizes', 'alert_department_id' => $this->busbar->id])
            ->assertOk()->assertJsonPath('notified', 2);
        $this->assertSame(1, $busbarMate->notifications()->count());
        $this->assertSame(1, $member->notifications()->count());
        $this->assertSame(0, $pm->notifications()->count(), 'the sender is never notified of their own alert');
        $this->assertSame(0, $wiringUser->notifications()->count());

        // ...and it pops up as an urgent alert in the status report
        $alerts = $this->actingAs($busbarMate)->getJson(route('status-report.show'))->json('alerts');
        $this->assertSame('alert', $alerts[0]['kind']);
    }

    public function test_comment_validation_rejects_empty_text_and_unsafe_files(): void
    {
        $this->subtask($this->busbar);
        $member = $this->user($this->busbar);

        $this->actingAs($member)->postJson(route('my-department.project.note', $this->project), ['note' => ''])->assertUnprocessable();
        $this->actingAs($member)->postJson(route('my-department.project.note', $this->project), [
            'note' => 'with file', 'attachments' => [UploadedFile::fake()->create('shell.php', 5, 'application/x-php')],
        ])->assertUnprocessable();
        $this->assertSame(0, ActivityLog::where('project_id', $this->project->id)->where('action', 'note')->count());
    }
}
