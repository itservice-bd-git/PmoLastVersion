<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectDateChange;
use App\Models\ProjectDateReason;
use App\Models\User;
use App\Services\ProjectDates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** เลื่อน / แก้วันโครงการ: Admin / PM move a project's dates with a reason; rounds are counted, corrections are not. */
class ProjectDatesTest extends TestCase
{
    use RefreshDatabase;

    private User $pm;

    private User $member;

    private User $follower;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'ไฟฟ้า', 'code' => 'EL', 'is_active' => true]);
        $this->pm = User::forceCreate(['name' => 'สมชาย', 'email' => 'dates-pm', 'password' => 'secret-pass', 'role' => 'project_manager', 'is_active' => true]);
        $this->member = User::forceCreate(['name' => 'วิภา', 'email' => 'dates-el', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $dept->id, 'is_active' => true]);
        $this->follower = User::forceCreate(['name' => 'ขาย', 'email' => 'dates-sale', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'SO-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal',
            'start_date' => '2026-09-15', 'due_date' => '2026-10-15']);
        $this->project->followers()->attach($this->follower->id);
    }

    private function reason(string $name = 'ลูกค้าขอเลื่อน'): ProjectDateReason
    {
        return ProjectDateReason::where('name', $name)->firstOrFail();
    }

    public function test_pm_postpones_twice_and_corrects_once_with_reasons_counted_rounds_log_and_notice(): void
    {
        $url = route('projects.dates.store', $this->project);
        $this->actingAs($this->pm)->post($url, ['kind' => 'postpone', 'due_date' => '2026-10-30', 'reason_id' => $this->reason('รอวัสดุ / อุปกรณ์')->id, 'note' => 'MCCB ของเข้าช้า', 'notify' => 1])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'บันทึกการเลื่อนวันครั้งที่ 1 แล้ว');
        $this->assertSame('2026-10-30', $this->project->fresh()->due_date->format('Y-m-d'));
        $this->assertSame('เลื่อนวันโครงการ ครั้งที่ 1: วันครบกำหนด 15/10/2569 → 30/10/2569 (+15 วัน) · สาเหตุ: รอวัสดุ / อุปกรณ์ — MCCB ของเข้าช้า',
            ActivityLog::where('action', 'rescheduled')->latest('id')->value('description'));
        $this->assertSame(1, $this->follower->notifications()->count(), 'the followers hear a round');
        $notice = $this->follower->notifications()->first()->data;
        $this->assertSame(['project_rescheduled', 'เลื่อนวัน 1 ครั้ง'], [$notice['kind'], $notice['body']]);
        $this->assertSame('เลื่อนวันโครงการ SO-1 ครั้งที่ 1 — ครบกำหนด 15/10/2569 → 30/10/2569 (+15 วัน) · รอวัสดุ / อุปกรณ์ — MCCB ของเข้าช้า', $notice['latest']);

        // a wrongly typed start date: kept, not a round
        $this->actingAs($this->pm)->post($url, ['kind' => 'correct', 'due_date' => '2026-10-30', 'change_start' => 1, 'start_date' => '2026-09-16', 'note' => 'ลงผิด'])
            ->assertSessionHasNoErrors();
        $this->actingAs($this->pm)->post($url, ['kind' => 'postpone', 'due_date' => '2026-11-13', 'reason_id' => $this->reason()->id, 'note' => 'หน้างานยังไม่พร้อม'])
            ->assertSessionHas('success', 'บันทึกการเลื่อนวันครั้งที่ 2 แล้ว');

        $s = app(ProjectDates::class)->summary($this->project->fresh());
        $this->assertSame([2, 29, '2026-10-15'], [$s['rounds'], $s['days'], $s['firstDue']->format('Y-m-d')]);
        $this->assertSame([2, null, 1], $s['changes']->pluck('round')->all(), 'newest first; the correction has no round');
        $this->assertSame('2026-09-16', $this->project->fresh()->start_date->format('Y-m-d'));

        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertOk()
            ->assertSee('เลื่อนแล้ว 2 ครั้ง · +29 วัน ›')->assertSee('วันครบกำหนดตอนแรก')->assertSee('ประวัติการเลื่อน')->assertSee('หน้างานยังไม่พร้อม')->assertSee('แก้ข้อมูลผิด · ไม่นับเป็นการเลื่อน');
    }

    public function test_a_round_needs_a_new_due_date_a_reason_and_details_and_only_admin_or_pm_moves_dates(): void
    {
        $url = route('projects.dates.store', $this->project);
        $this->actingAs($this->member)->post($url, ['kind' => 'postpone', 'due_date' => '2026-10-30', 'reason_id' => $this->reason()->id, 'note' => 'x'])->assertForbidden();

        $this->actingAs($this->pm)->post($url, ['kind' => 'postpone', 'due_date' => '2026-10-30'])->assertSessionHasErrors(['reason_id', 'note']);
        $this->actingAs($this->pm)->post($url, ['kind' => 'postpone', 'due_date' => '2026-10-15', 'reason_id' => $this->reason()->id, 'note' => 'x'])->assertSessionHasErrors('due_date');
        $this->actingAs($this->pm)->post($url, ['kind' => 'postpone', 'due_date' => '2026-09-01', 'reason_id' => $this->reason()->id, 'note' => 'x'])->assertSessionHasErrors('due_date');
        $this->reason()->update(['is_active' => false]);
        $this->actingAs($this->pm)->post($url, ['kind' => 'postpone', 'due_date' => '2026-10-30', 'reason_id' => $this->reason()->id, 'note' => 'x'])->assertSessionHasErrors('reason_id');
        $this->assertSame(0, ProjectDateChange::count());
        $this->assertSame('2026-10-15', $this->project->fresh()->due_date->format('Y-m-d'));
    }

    public function test_the_edit_form_keeps_a_set_date_and_planning_shift_counts_as_a_round(): void
    {
        $this->actingAs($this->pm)->put(route('projects.update', $this->project), [
            'project_no' => 'SO-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal',
            'start_date' => '2026-01-01', 'due_date' => '2026-12-31',
        ])->assertSessionHasNoErrors();
        $p = $this->project->fresh();
        $this->assertSame(['2026-09-15', '2026-10-15'], [$p->start_date->format('Y-m-d'), $p->due_date->format('Y-m-d')], 'set dates move only through "เลื่อน / แก้วัน"');

        app(ProjectDates::class)->shifted($p, $this->pm, $p->start_date, $p->due_date, 7);
        $s = app(ProjectDates::class)->summary($p);
        $this->assertSame(1, $s['rounds']);
        $this->assertSame('ไม่ระบุ (เลื่อนทั้งโครงการจาก Planning)', $s['changes']->first()->reason_name);
    }

    public function test_a_cabinet_is_postponed_on_its_own_count_and_its_edit_paths_keep_a_set_date(): void
    {
        $cab = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'A', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0,
            'start_date' => '2026-09-20', 'due_date' => '2026-10-10', 'expected_completion_date' => '2026-10-08']);
        $url = route('cabinets.dates.store', $cab);
        $this->actingAs($this->member)->post($url, ['kind' => 'postpone', 'due_date' => '2026-10-20', 'reason_id' => $this->reason()->id, 'note' => 'x'])->assertForbidden();

        $this->actingAs($this->pm)->post($url, ['kind' => 'postpone', 'due_date' => '2026-10-20', 'reason_id' => $this->reason('รอวัสดุ / อุปกรณ์')->id, 'note' => 'บัสบาร์มาช้า', 'notify' => 1])
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'บันทึกการเลื่อนวันครั้งที่ 1 แล้ว');
        $this->assertSame('2026-10-20', $cab->fresh()->due_date->format('Y-m-d'));
        $this->assertSame('เลื่อนวันตู้ MO-1 ครั้งที่ 1: กำหนดเสร็จ 10/10/2569 → 20/10/2569 (+10 วัน) · สาเหตุ: รอวัสดุ / อุปกรณ์ — บัสบาร์มาช้า',
            ActivityLog::where('action', 'rescheduled')->latest('id')->value('description'));
        $this->assertStringStartsWith('เลื่อนวันตู้ MO-1 (SO-1) ครั้งที่ 1', $this->follower->notifications()->first()->data['latest']);

        // its own count; the project's stays untouched
        $this->assertSame([1, 10], [app(ProjectDates::class)->summary($cab->fresh())['rounds'], app(ProjectDates::class)->summary($cab->fresh())['days']]);
        $this->assertSame(0, app(ProjectDates::class)->summary($this->project->fresh())['rounds']);
        $this->actingAs($this->pm)->get(route('cabinets.show', $cab))->assertOk()->assertSee('เลื่อน 1 ครั้ง')->assertSee('+10 วัน ›')->assertSee('กำหนดเสร็จตอนแรก')->assertSee('บัสบาร์มาช้า');

        // the expected finish may not end up after the new due date
        $this->actingAs($this->pm)->post($url, ['kind' => 'correct', 'due_date' => '2026-10-05'])->assertSessionHasErrors('due_date');

        // the edit form keeps the set dates; the expected finish is still checked against the due date
        $form = ['mo_no' => 'MO-1', 'cabinet_name' => 'A', 'start_date' => '2026-01-01', 'due_date' => '2026-12-31'];
        $this->actingAs($this->pm)->put(route('cabinets.update', $cab), $form + ['expected_completion_date' => '2026-10-18'])->assertSessionHasNoErrors();
        $this->assertSame(['2026-09-20', '2026-10-20', '2026-10-18'], [$cab->fresh()->start_date->format('Y-m-d'), $cab->fresh()->due_date->format('Y-m-d'), $cab->fresh()->expected_completion_date->format('Y-m-d')]);
        $this->actingAs($this->pm)->put(route('cabinets.update', $cab), $form + ['expected_completion_date' => '2026-11-30'])->assertSessionHasErrors('expected_completion_date');
    }

    public function test_planning_gets_the_rounds_of_a_project_and_of_its_cabinets(): void
    {
        $cab = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'A', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0, 'due_date' => '2026-10-10']);
        $board = fn () => collect(app(\App\Services\PlanningBoard::class)->tasks($this->pm))->firstWhere('id', 'p'.$this->project->id);
        $this->assertNull($board()['reschedule'], 'never postponed: no chip');

        $url = route('projects.dates.store', $this->project);
        $this->actingAs($this->pm)->post($url, ['kind' => 'postpone', 'due_date' => '2026-10-30', 'reason_id' => $this->reason('รอวัสดุ / อุปกรณ์')->id, 'note' => 'x']);
        $this->actingAs($this->pm)->post($url, ['kind' => 'correct', 'due_date' => '2026-10-31']);
        $this->actingAs($this->pm)->post($url, ['kind' => 'postpone', 'due_date' => '2026-11-13', 'reason_id' => $this->reason()->id, 'note' => 'y']);
        $this->actingAs($this->pm)->post(route('cabinets.dates.store', $cab), ['kind' => 'postpone', 'due_date' => '2026-10-17', 'reason_id' => $this->reason()->id, 'note' => 'z']);

        $t = $board();
        $this->assertSame([2, 29, '2026-10-15', route('projects.show', $this->project)], [$t['reschedule']['rounds'], $t['reschedule']['days'], $t['reschedule']['firstDue'], $t['reschedule']['url']]);
        $this->assertSame([[2, 'ลูกค้าขอเลื่อน', '2026-10-31', '2026-11-13', 13], [1, 'รอวัสดุ / อุปกรณ์', '2026-10-15', '2026-10-30', 15]],
            array_map(fn ($r) => [$r['n'], $r['reason'], $r['from'], $r['to'], $r['days']], $t['reschedule']['list']), 'newest first; the correction is not a round');
        $this->assertSame([1, 7], [$t['subtasks'][0]['reschedule']['rounds'], $t['subtasks'][0]['reschedule']['days']], 'the cabinet has its own count');
    }

    public function test_planning_opens_the_project_window_and_saves_through_it_as_json(): void
    {
        $this->actingAs($this->member)->get(route('projects.dates.form', $this->project))->assertForbidden();
        $this->actingAs($this->pm)->get(route('projects.dates.form', $this->project))->assertOk()
            ->assertSee('เลื่อน / แก้วันโครงการ')->assertSee('บันทึกการเลื่อน (ครั้งที่ 1)')->assertSee(route('projects.dates.store', $this->project), false);

        $url = route('projects.dates.store', $this->project);
        $this->actingAs($this->pm)->postJson($url, ['kind' => 'postpone', 'due_date' => '2026-10-30'])->assertStatus(422)->assertJsonValidationErrors(['reason_id', 'note']);
        $this->actingAs($this->pm)->postJson($url, ['kind' => 'postpone', 'due_date' => '2026-10-30', 'reason_id' => $this->reason()->id, 'note' => 'x'])
            ->assertOk()->assertExactJson(['message' => 'บันทึกการเลื่อนวันครั้งที่ 1 แล้ว']);
        $this->actingAs($this->pm)->get(route('projects.dates.form', $this->project))->assertSee('บันทึกการเลื่อน (ครั้งที่ 2)')->assertSee('ประวัติการเลื่อน');
    }

    public function test_admin_keeps_the_reason_list_and_a_used_reason_is_switched_off_not_deleted(): void
    {
        $this->actingAs($this->member)->get(route('settings.date-reasons.index'))->assertForbidden();
        $this->actingAs($this->follower)->get(route('settings.date-reasons.index'))->assertOk()->assertSee('ลูกค้าขอเลื่อน');

        $this->actingAs($this->follower)->post(route('settings.date-reasons.store'), ['name' => 'รอหน้างานลูกค้า', 'color' => 'sky'])->assertSessionHasNoErrors();
        $new = ProjectDateReason::where('name', 'รอหน้างานลูกค้า')->firstOrFail();
        $this->actingAs($this->follower)->delete(route('settings.date-reasons.destroy', $new));
        $this->assertNull(ProjectDateReason::find($new->id));

        $used = $this->reason();
        $this->actingAs($this->pm)->post(route('projects.dates.store', $this->project), ['kind' => 'postpone', 'due_date' => '2026-10-30', 'reason_id' => $used->id, 'note' => 'x']);
        $this->actingAs($this->follower)->delete(route('settings.date-reasons.destroy', $used));
        $this->assertFalse($used->fresh()->is_active);
        $this->assertSame('ลูกค้าขอเลื่อน', ProjectDateChange::first()->reason_name);
    }

    public function test_everyone_in_a_department_with_work_hears_a_round_of_the_project_or_of_its_cabinet(): void
    {
        $qc = Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true]);
        $paint = Department::create(['name' => 'พ่นสี', 'code' => 'PT', 'is_active' => true]);
        $qcMan = User::forceCreate(['name' => 'ตรวจ', 'email' => 'dates-qc', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $qc->id, 'is_active' => true]);
        $painter = User::forceCreate(['name' => 'สี', 'email' => 'dates-pt', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $paint->id, 'is_active' => true]);
        $helper = User::forceCreate(['name' => 'ช่วย', 'email' => 'dates-help', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $paint->id, 'is_active' => true]);
        $helper->extraDepartments()->attach($qc->id);   // looks after QC too
        $work = function (string $mo, Department $d, string $status) {
            $cab = Cabinet::firstOrCreate(['project_id' => $this->project->id, 'mo_no' => $mo], ['cabinet_name' => $mo, 'quantity' => 1, 'status' => 'not_started', 'progress' => 0, 'due_date' => '2026-10-10']);
            $t = CabinetTask::forceCreate(['cabinet_id' => $cab->id, 'name' => 'งาน', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
            CabinetSubtask::forceCreate(['cabinet_task_id' => $t->id, 'name' => $d->name, 'department_id' => $d->id, 'assignment_status' => $status, 'progress' => 0, 'status' => 'not_started', 'sequence' => 1]);

            return $cab;
        };
        $cab1 = $work('MO-1', Department::where('code', 'EL')->first(), CabinetSubtask::ASSIGNMENT_ACCEPTED);
        $work('MO-2', $qc, CabinetSubtask::ASSIGNMENT_ASSIGNED);
        $work('MO-2', $paint, CabinetSubtask::ASSIGNMENT_UNASSIGNED);   // not sent to พ่นสี yet: not theirs

        $this->actingAs($this->pm)->get(route('projects.dates.form', $this->project))->assertSee('และทุกคนในแผนกที่มีงาน (QC, ไฟฟ้า)');
        $this->actingAs($this->pm)->post(route('projects.dates.store', $this->project), ['kind' => 'postpone', 'due_date' => '2026-10-30', 'reason_id' => $this->reason()->id, 'note' => 'x', 'notify' => 1])
            ->assertSessionHasNoErrors();
        foreach ([$this->member, $qcMan, $helper] as $u) {
            $n = $u->notifications()->first();
            $this->assertNotNull($n, $u->name.' hears it');
            $this->assertStringEndsWith('วันส่งงานของแผนกยังเป็นวันเดิม', $n->data['latest']);
            $this->assertSame(route('planning.board', ['project' => $this->project->id]), $n->data['url']);
        }
        $this->assertSame(0, $painter->notifications()->count());
        $this->assertSame(1, $this->follower->notifications()->count());
        $this->assertStringEndsWith('— x', $this->follower->notifications()->first()->data['latest']);

        // a cabinet: only the departments with work in it; without "แจ้ง", nobody
        $this->actingAs($this->pm)->get(route('cabinets.show', $cab1))->assertSee('และทุกคนในแผนกที่มีงานในตู้นี้ (ไฟฟ้า)');
        $this->actingAs($this->pm)->post(route('cabinets.dates.store', $cab1), ['kind' => 'postpone', 'due_date' => '2026-10-20', 'reason_id' => $this->reason()->id, 'note' => 'y', 'notify' => 1]);
        $this->assertStringStartsWith('เลื่อนวันตู้ MO-1', $this->member->notifications()->first()->data['latest']);
        $this->assertStringStartsWith('เลื่อนวันโครงการ', $qcMan->notifications()->first()->data['latest']);
        $this->actingAs($this->pm)->post(route('projects.dates.store', $this->project), ['kind' => 'postpone', 'due_date' => '2026-11-06', 'reason_id' => $this->reason()->id, 'note' => 'z']);
        $this->assertStringStartsWith('เลื่อนวันโครงการ SO-1 ครั้งที่ 1', $qcMan->notifications()->first()->data['latest']);
    }
}
