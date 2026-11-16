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
use App\Services\SubtaskAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** One status per Sub Task, and the checklist decides it (SubtaskAssignmentService::followChecklist). */
class SubtaskChecklistStatusTest extends TestCase
{
    use RefreshDatabase;

    private Department $qc;

    private Cabinet $cabinet;

    private CabinetTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-16 09:00:00');
        $this->qc = Department::create(['name' => 'QC', 'code' => 'QC']);
        $project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'Test', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $this->cabinet = Cabinet::forceCreate(['project_id' => $project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $this->cabinet->id, 'name' => 'Task', 'status' => 'not_started', 'progress' => 0]);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'U'.++$n, 'email' => 'one'.$n.'@example.com', 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    private function subtask(string $status, ?Department $d = null, int $checks = 2, ?string $acceptedAt = null): CabinetSubtask
    {
        $s = CabinetSubtask::forceCreate([
            'cabinet_task_id' => $this->task->id, 'name' => 'S', 'department_id' => $d?->id,
            'assignment_status' => $status, 'status' => 'not_started', 'progress' => 0, 'accepted_at' => $acceptedAt,
        ]);
        for ($i = 1; $i <= $checks; $i++) {
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => "c$i", 'is_completed' => false, 'sequence' => $i]);
        }

        return $s;
    }

    private function tick(User $u, CabinetChecklist $c, bool $on = true)
    {
        return $this->actingAs($u)->patchJson(route('checklists.toggle', $c), ['is_completed' => $on]);
    }

    public function test_ticking_every_item_of_work_never_handed_out_makes_it_done(): void
    {
        $s = $this->subtask('UNASSIGNED');
        $pm = $this->user(null, 'project_manager');
        [$a, $b] = $s->checklists;

        $this->tick($pm, $a)->assertOk()->assertJsonPath('subtask.work_status_label', 'ยังไม่จ่ายงาน · 50%');
        $this->assertSame('UNASSIGNED', $s->fresh()->assignment_status);

        $this->tick($pm, $b)->assertOk()
            ->assertJsonPath('assignment.assignment_status', 'COMPLETED')
            ->assertJsonPath('subtask.work_status_label', 'เสร็จงานแล้ว');
        $this->assertSame(['COMPLETED', $pm->id], [$s->fresh()->assignment_status, $s->fresh()->completed_by]);
        $this->assertStringContainsString('Checklist ครบ (2/2)', ActivityLog::where('action', 'completed')->sole()->description);
    }

    public function test_unticking_done_work_goes_back_to_the_step_it_really_is_at(): void
    {
        $pm = $this->user(null, 'project_manager');
        $cases = [
            'UNASSIGNED' => $this->subtask('COMPLETED'),                                          // never handed out
            'ASSIGNED' => $this->subtask('COMPLETED', $this->qc),                                 // handed out, never accepted
            'IN_PROGRESS' => $this->subtask('COMPLETED', $this->qc, acceptedAt: '2026-11-10 08:00:00'),
        ];
        foreach ($cases as $expected => $s) {
            $s->checklists()->update(['is_completed' => true]);
            $this->tick($pm, $s->checklists()->first(), false)->assertOk();
            $this->assertSame($expected, $s->fresh()->assignment_status);
            $this->assertNull($s->fresh()->completed_at);
        }
        $this->assertSame(3, ActivityLog::where('action', 'reopened')->count());
    }

    public function test_an_item_added_to_done_work_reopens_it(): void
    {
        $s = $this->subtask('COMPLETED', $this->qc, checks: 1, acceptedAt: '2026-11-10 08:00:00');
        $s->checklists()->update(['is_completed' => true]);

        $this->actingAs($this->user(null, 'admin'))->post(route('checklists.store', $s), ['names' => 'เพิ่มทีหลัง']);

        $this->assertSame('IN_PROGRESS', $s->fresh()->assignment_status);
    }

    public function test_the_one_status_label(): void
    {
        $s = $this->subtask('ASSIGNED', $this->qc);
        $this->assertSame('รอรับงาน', $s->work_status_label);
        $s->progress = 40;
        $this->assertSame('รอรับงาน · 40%', $s->work_status_label);
        $s->status = CabinetSubtask::STATUS_ON_HOLD;
        $this->assertSame('พักงาน', $s->work_status_label);
        $s->assignment_status = 'COMPLETED';
        $s->progress = 100;
        $this->assertSame('เสร็จงานแล้ว', $s->work_status_label, 'done wins over on hold');
    }

    public function test_the_done_button_is_only_for_work_without_a_checklist(): void
    {
        $member = $this->user($this->qc);
        $svc = app(SubtaskAssignmentService::class);

        $this->assertFalse($svc->actionsFor($this->subtask('IN_PROGRESS', $this->qc), $member)['can_complete']);
        $this->assertTrue($svc->actionsFor($this->subtask('IN_PROGRESS', $this->qc, checks: 0), $member)['can_complete']);
    }

    public function test_the_cabinet_page_shows_one_status_per_sub_task(): void
    {
        $s = $this->subtask('ASSIGNED', $this->qc);

        $this->actingAs($this->user(null, 'admin'))->get(route('cabinets.show', $this->cabinet))->assertOk()
            ->assertSee('id="work-status-'.$s->id.'"', false)
            ->assertDontSee('id="status-badge-subtask-'.$s->id.'"', false);
    }

    /** database/sql/2026_11_16_*.sql: the one-off fix of older work, run in phpMyAdmin (here on sqlite, as written) */
    private function sql(string $file): string
    {
        return file_get_contents(database_path('sql/'.$file));
    }

    public function test_the_one_off_sql_lists_then_completes_older_work_whose_checklist_is_all_ticked(): void
    {
        $ticked = fn (CabinetSubtask $s) => tap($s, fn () => $s->checklists()->update(['is_completed' => true]));
        $neverOut = $ticked($this->subtask('UNASSIGNED'));
        $waiting = $ticked($this->subtask('ASSIGNED', $this->qc));
        $doneButOpen = $this->subtask('COMPLETED', $this->qc, acceptedAt: '2026-11-10 08:00:00');   // left as it is
        $partly = $this->subtask('ASSIGNED', $this->qc);
        $noChecklist = $this->subtask('ACCEPTED', $this->qc, checks: 0);
        $all = [$neverOut, $waiting, $doneButOpen, $partly, $noChecklist];
        $state = fn () => collect($all)->map(fn ($s) => $s->fresh()->assignment_status)->all();

        // the check lists what would change, and changes nothing
        $rows = collect(DB::select($this->sql('2026_11_16_check_subtask_status.sql')));
        $this->assertSame(['UNASSIGNED', 'ASSIGNED'], $rows->pluck('สถานะเดิม')->all());
        $this->assertSame(['UNASSIGNED', 'ASSIGNED', 'COMPLETED', 'ASSIGNED', 'ACCEPTED'], $state());

        DB::unprepared($this->sql('2026_11_16_subtask_status.sql'));

        $this->assertSame(['COMPLETED', 'COMPLETED', 'COMPLETED', 'ASSIGNED', 'ACCEPTED'], $state());
        $this->assertNotNull($neverOut->fresh()->completed_at);
        $this->assertSame([], DB::select($this->sql('2026_11_16_check_subtask_status.sql')), 'nothing left to complete');
    }
}
