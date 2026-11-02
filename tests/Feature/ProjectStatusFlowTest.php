<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\CabinetChecklist;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\SubtaskAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A project starts by itself when the first department takes work; once every department is done the PM is asked to close it. */
class ProjectStatusFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $pm;

    private User $member;

    private Project $project;

    private CabinetSubtask $a;

    private CabinetSubtask $b;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'Busbar', 'code' => 'BUS', 'is_active' => true]);
        $this->pm = User::forceCreate(['name' => 'PM', 'email' => 'psf-pm', 'password' => 'secret-pass', 'role' => 'project_manager', 'is_active' => true]);
        $this->member = User::forceCreate(['name' => 'สมชาย', 'email' => 'psf-bus', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $dept->id, 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'P-9', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'draft', 'priority' => 'normal']);
        $cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::forceCreate(['cabinet_id' => $cabinet->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
        [$this->a, $this->b] = collect(['บัส 1', 'บัส 2'])->map(fn ($n) => CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => $n, 'department_id' => $dept->id, 'assignment_status' => 'ASSIGNED', 'assigned_at' => now(), 'status' => 'not_started', 'progress' => 0]))->all();
    }

    public function test_the_first_work_taken_starts_the_project_once(): void
    {
        $flow = app(SubtaskAssignmentService::class);
        $flow->accept($this->a, $this->member);
        $this->assertSame('in_progress', $this->project->fresh()->status);
        $this->assertStringContainsString('Draft → In Progress อัตโนมัติ (แผนก Busbar รับงานแรก)', ActivityLog::where('loggable_type', Project::class)->latest('id')->value('description'));

        // On Hold stays On Hold
        $this->project->update(['status' => 'on_hold']);
        $flow->accept($this->b, $this->member);
        $this->assertSame('on_hold', $this->project->fresh()->status);
    }

    public function test_every_department_done_asks_the_pm_to_close_and_closing_completes_it(): void
    {
        $flow = app(SubtaskAssignmentService::class);
        $flow->accept($this->a, $this->member);
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertDontSee('งานครบทุกแผนกแล้ว');

        foreach ([$this->a, $this->b->fresh()] as $s) {
            if ($s->assignment_status === 'ASSIGNED') {
                $flow->accept($s, $this->member);
            }
            $flow->start($s->fresh(), $this->member);
            $flow->complete($s->fresh(), $this->member);
        }
        $this->assertSame('in_progress', $this->project->fresh()->status, 'never closed by itself');
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertSee('งานครบทุกแผนกแล้ว (2 งาน)');
        $this->actingAs($this->member)->get(route('projects.show', $this->project))->assertDontSee('งานครบทุกแผนกแล้ว');

        $this->actingAs($this->member)->post(route('projects.complete', $this->project))->assertForbidden();
        $this->actingAs($this->pm)->post(route('projects.complete', $this->project))->assertSessionHas('success');
        $this->assertSame('completed', $this->project->fresh()->status);
    }

    public function test_a_pm_ticking_for_a_department_moves_the_work_when_allowed(): void
    {
        AppSetting::put('pmo_acts_for_departments', true);
        AppSetting::put('tick_accepts', true);
        \App\Models\AutomationRule::create(['name' => 'ครบ → เสร็จ', 'trigger' => 'checklist_all_done', 'action' => 'complete', 'is_active' => true]);
        $item = CabinetChecklist::forceCreate(['cabinet_subtask_id' => $this->a->id, 'name' => 'x', 'is_completed' => false, 'sequence' => 1]);

        $this->actingAs($this->pm)->patchJson(route('checklists.toggle', $item), ['is_completed' => true])->assertOk();
        $this->assertSame('COMPLETED', $this->a->fresh()->assignment_status, 'accepted, started and finished for the department');
        $this->assertStringContainsString('(ทำแทนโดย PM)', ActivityLog::where('action', 'accepted')->latest('id')->value('description'));
    }

    public function test_work_ticked_through_but_never_closed_is_listed_and_closed_in_one_go(): void
    {
        // ticked before the PM's tick counted (or with the switch off): the work stayed "รอรับ"
        foreach ([$this->a, $this->b] as $s) {
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => 'x', 'is_completed' => true, 'sequence' => 1]);
        }
        $page = $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertOk();
        $page->assertSee('Checklist ครบแล้ว 2 งาน แต่ยังไม่ได้ปิดงาน')->assertDontSee('งานครบทุกแผนกแล้ว');

        // not allowed to act for departments: refused, said why
        $this->actingAs($this->pm)->post(route('projects.finish-ticked', $this->project))->assertSessionHas('error');
        $this->assertSame('ASSIGNED', $this->a->fresh()->assignment_status);

        AppSetting::put('pmo_acts_for_departments', true);
        $this->actingAs($this->pm)->post(route('projects.finish-ticked', $this->project))->assertSessionHas('success', 'ปิดงาน 2 งานแล้ว (ทำแทนแผนก)');
        $this->assertSame(['COMPLETED', 'COMPLETED'], [$this->a->fresh()->assignment_status, $this->b->fresh()->assignment_status]);
        $this->assertSame('in_progress', $this->project->fresh()->status);
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertSee('งานครบทุกแผนกแล้ว (2 งาน)');
        $this->actingAs($this->member)->post(route('projects.finish-ticked', $this->project))->assertForbidden();
    }
}
