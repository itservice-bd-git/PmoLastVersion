<?php

namespace Tests\Feature;

use App\Exceptions\ProjectLockedException;
use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\ProgressService;
use App\Services\SubtaskAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deleting is recoverable (trash), and a Completed/Cancelled project is read-only.
 */
class TrashAndLockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Project $project;

    private Cabinet $cabinet;

    private CabinetTask $task;

    private CabinetSubtask $subtask;

    private CabinetChecklist $checklist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::forceCreate(['name' => 'Admin', 'email' => 'trash@example.com', 'password' => 'secret-pass', 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->actingAs($this->admin);

        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'Test', 'customer_name' => 'C', 'status' => 'planning']);
        $this->cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $this->cabinet->id, 'name' => 'Task', 'status' => 'not_started', 'progress' => 0]);
        $this->subtask = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->task->id, 'name' => 'Sub', 'status' => 'not_started', 'progress' => 0]);
        $this->checklist = $this->subtask->checklists()->create(['name' => 'Chk', 'is_completed' => false, 'sequence' => 1]);
    }

    private function close(string $status = 'completed'): void
    {
        $this->project->update(['status' => $status]);
    }

    // ---- trash ----

    public function test_deleting_a_project_trashes_everything_under_it_and_restore_brings_it_back(): void
    {
        $this->delete(route('projects.destroy', $this->project))->assertRedirect(route('projects.index'));

        $this->assertSoftDeleted('projects', ['id' => $this->project->id]);
        foreach (['cabinets' => $this->cabinet, 'cabinet_tasks' => $this->task, 'cabinet_subtasks' => $this->subtask, 'cabinet_checklists' => $this->checklist] as $table => $model) {
            $this->assertSoftDeleted($table, ['id' => $model->id]);
        }
        $this->assertSame(0, Cabinet::count());

        $this->post(route('trash.projects.restore', $this->project->id))->assertRedirect(route('projects.show', $this->project));

        $this->assertSame(1, Cabinet::count());
        $this->assertSame(1, CabinetChecklist::count());
        $this->assertSame(1, ActivityLog::where('action', 'restored')->count());
    }

    public function test_restore_only_brings_back_what_went_away_with_the_parent(): void
    {
        $other = CabinetTask::forceCreate(['cabinet_id' => $this->cabinet->id, 'name' => 'Deleted earlier', 'status' => 'not_started', 'progress' => 0]);
        $other->delete();
        $this->travel(5)->seconds();

        $this->cabinet->delete();
        $this->cabinet->restore();

        $this->assertNull(CabinetTask::find($other->id));
        $this->assertNotNull(CabinetTask::find($this->task->id));
        $this->assertNotNull(CabinetChecklist::find($this->checklist->id));
    }

    public function test_cascade_does_not_write_a_log_row_per_child(): void
    {
        $before = ActivityLog::count();

        $this->project->delete();

        $this->assertSame($before + 1, ActivityLog::count());
    }

    public function test_deleted_numbers_can_be_reused_but_not_restored_over_a_live_one(): void
    {
        $this->cabinet->delete();
        Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'New', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);

        $this->post(route('trash.cabinets.restore', $this->cabinet->id))->assertSessionHas('error');

        $this->assertSoftDeleted('cabinets', ['id' => $this->cabinet->id]);
    }

    public function test_only_pmo_roles_can_see_or_use_the_trash(): void
    {
        $member = User::forceCreate(['name' => 'M', 'email' => 'm@example.com', 'password' => 'secret-pass', 'role' => User::ROLE_MEMBER, 'is_active' => true]);
        $this->cabinet->delete();

        $this->actingAs($member)->get(route('trash.index'))->assertForbidden();
        $this->actingAs($member)->post(route('trash.cabinets.restore', $this->cabinet->id))->assertForbidden();
        $this->actingAs($this->admin)->get(route('trash.index'))->assertOk()->assertSee('MO-1');
    }

    // ---- closed project lock ----

    public function test_closed_project_blocks_work_edits(): void
    {
        $this->close();

        foreach ([
            fn () => $this->subtask->update(['name' => 'x']),
            fn () => $this->task->update(['name' => 'x']),
            fn () => $this->cabinet->update(['cabinet_name' => 'x']),
            fn () => app(ProgressService::class)->toggleChecklist($this->checklist, true, $this->admin),
            fn () => $this->subtask->checklists()->create(['name' => 'new', 'is_completed' => false, 'sequence' => 2]),
            fn () => Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-2', 'cabinet_name' => 'n', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]),
            fn () => $this->project->update(['project_name' => 'renamed']),
        ] as $i => $attempt) {
            try {
                $attempt();
                $this->fail("edit #{$i} was allowed on a closed project");
            } catch (ProjectLockedException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_closed_project_blocks_assignment_transitions_even_though_they_save_quietly(): void
    {
        $dept = Department::create(['name' => 'Wiring', 'code' => 'WIR']);
        $this->subtask->forceFill(['department_id' => $dept->id, 'assignment_status' => CabinetSubtask::ASSIGNMENT_ASSIGNED])->saveQuietly();
        $member = User::forceCreate(['name' => 'W', 'email' => 'w@example.com', 'password' => 'secret-pass', 'role' => User::ROLE_MEMBER, 'department_id' => $dept->id, 'is_active' => true]);
        $this->close('cancelled');

        $this->expectException(ProjectLockedException::class);
        app(SubtaskAssignmentService::class)->accept($this->subtask->fresh(), $member);
    }

    public function test_locked_edit_over_http_returns_423_json_and_flash_error_for_forms(): void
    {
        $this->close();

        $this->putJson(route('cabinet-subtasks.update', $this->subtask), ['status' => 'in_progress'])->assertStatus(423);
        $this->from(route('projects.show', $this->project))
            ->put(route('cabinet-subtasks.update', $this->subtask), ['status' => 'in_progress'])
            ->assertRedirect(route('projects.show', $this->project))->assertSessionHas('error');
    }

    public function test_reopening_and_comments_are_still_allowed_on_a_closed_project(): void
    {
        $this->close();

        $this->project->update(['status' => 'in_progress']);
        $this->subtask->update(['name' => 'editable again']);
        $this->assertSame('editable again', $this->subtask->fresh()->name);

        $this->close();
        $this->post(route('projects.notes.store', $this->project), ['note' => 'still can comment'])->assertRedirect();
        $this->assertSame(1, ActivityLog::where('action', 'note')->count());
    }
}
