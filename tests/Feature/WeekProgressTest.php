<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\User;
use App\Services\WeekProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ภาพรวมผู้บริหาร's "ความคืบหน้าสัปดาห์นี้" (App\Services\WeekProgress): the week's board projects and how far each moved. */
class WeekProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(9, 0));   // Wednesday: this week 16-22 Nov
        $this->pm = User::forceCreate(['name' => 'Somchai', 'email' => 'pm-wp', 'password' => 'secret-pass', 'role' => 'project_manager', 'is_active' => true]);
    }

    /** A project ending $due with one cabinet whose Checklist items were ticked at the times given (null = not ticked). */
    private function project(string $no, string $due, array $ticks, ?Department $dept = null): Project
    {
        $p = Project::forceCreate(['project_no' => $no, 'project_name' => 'P '.$no, 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal',
            'start_date' => '2026-10-01', 'due_date' => $due, 'project_manager_id' => $this->pm->id]);
        $done = count(array_filter($ticks));
        $progress = (int) round($done / count($ticks) * 100);
        $c = Cabinet::forceCreate(['project_id' => $p->id, 'mo_no' => 'MO-'.$no, 'cabinet_name' => $no, 'quantity' => 1, 'due_date' => $due,
            'status' => $progress >= 100 ? 'completed' : 'in_progress', 'progress' => $progress]);
        $t = CabinetTask::forceCreate(['cabinet_id' => $c->id, 'name' => '1. ประกอบ', 'status' => 'in_progress', 'progress' => $progress, 'sequence' => 0]);
        $s = CabinetSubtask::forceCreate(['cabinet_task_id' => $t->id, 'name' => '1.1', 'status' => 'in_progress', 'progress' => $progress, 'department_id' => $dept?->id]);
        foreach ($ticks as $i => $at) {
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => 'item '.$i, 'sequence' => $i, 'is_completed' => $at !== null, 'completed_at' => $at]);
        }
        // delivered on its due date: the board puts its card there
        ProjectDelivery::forceCreate(['project_id' => $p->id, 'round' => 1, 'delivery_date' => $due, 'status' => ProjectDelivery::WAITING, 'cabinet_ids' => [$c->id]]);

        return $p;
    }

    public function test_each_board_project_says_how_much_was_made_this_week(): void
    {
        $bb = Department::create(['name' => 'Busbar', 'code' => 'BB', 'is_active' => true]);
        // half made last week, the rest this week: 50% → 100%, its cabinet finished this week
        $this->project('A', '2026-11-19', ['2026-11-10 10:00', '2026-11-17 14:00'], $bb);
        // one of four ticked this week: 25% → 50%
        $this->project('B', '2026-11-20', ['2026-11-12 10:00', '2026-11-16 08:00', null, null]);
        // nothing this week
        $this->project('C', '2026-11-20', ['2026-11-11 10:00', null]);
        // not on this week's board (ends next month): left out
        $this->project('D', '2026-12-20', ['2026-11-17 10:00', null]);

        $w = app(WeekProgress::class)->build($this->pm);
        $rows = collect($w['rows'])->keyBy('no');

        $this->assertSame(['A', 'B', 'C'], array_column($w['rows'], 'no'), 'most added first; D is not on the board');
        $this->assertSame([50, 100, 50, 1, 1, ['Busbar' => 1]], [$rows['A']['start'], $rows['A']['now'], $rows['A']['added'], $rows['A']['ticks'], $rows['A']['cabinetsDone'], $rows['A']['departments']]);
        $this->assertSame([25, 50, 25, 1, 0], [$rows['B']['start'], $rows['B']['now'], $rows['B']['added'], $rows['B']['ticks'], $rows['B']['cabinetsDone']]);
        $this->assertSame([50, 50, 0, 0], [$rows['C']['start'], $rows['C']['now'], $rows['C']['added'], $rows['C']['ticks']]);
        $this->assertSame(['projects' => 3, 'moved' => 2, 'still' => 1, 'ticks' => 2, 'subtasks' => 0, 'cabinets' => 1, 'avgAdded' => 38], $w['totals']);
        $this->assertSame('16 พ.ย. – 22 พ.ย.', $w['label']);
        $this->assertSame('2 จาก 3 โครงการมีความคืบหน้า ผลิตเพิ่มเฉลี่ย +38% ตู้ผลิตเสร็จ 1 ตู้', $w['sentence']);
        $this->assertSame(['C'], $w['still']);
        // a row opens to each cabinet: what was done in it this week (by Task) and what is left
        $this->assertSame(['mo' => 'MO-B', 'name' => 'B', 'start' => 25, 'now' => 50, 'done' => ['1. ประกอบ' => 1], 'left' => ['1. ประกอบ' => 2]], $rows['B']['cabinetList'][0]);

        // last week: A's first tick (10 Nov) is not on last week's board (A delivers this week), so nothing to say for it there
        $this->assertSame(-1, app(WeekProgress::class)->build($this->pm, -1)['offset']);
        $this->assertSame(0, app(WeekProgress::class)->build($this->pm, 3)['offset'], 'no week to come');
    }

    public function test_the_executive_tab_shows_it(): void
    {
        $this->project('A', '2026-11-19', ['2026-11-10 10:00', '2026-11-17 14:00']);

        // ภาพรวมผู้บริหาร: the one view of those who see every department's work and are not PM
        $seesAll = Department::create(['name' => 'ผู้บริหาร', 'code' => 'EX', 'is_active' => true, 'sees_all' => true]);
        $this->pm = User::forceCreate(['name' => 'Boss', 'email' => 'boss-wp', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $seesAll->id, 'is_active' => true]);
        $this->actingAs($this->pm)->get(route('dashboard'))->assertOk()
            ->assertSeeText('ความคืบหน้าสัปดาห์นี้')->assertSeeText('+50%')->assertSeeText('P A');
        $this->actingAs($this->pm)->get(route('dashboard', ['week' => -1]))->assertOk()->assertSeeText('ความคืบหน้าสัปดาห์ที่แล้ว');
        // ‹ › fetch only the section: no page around it
        $this->actingAs($this->pm)->get(route('dashboard', ['view' => 'exec', 'week' => -1]), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSeeText('ความคืบหน้าสัปดาห์ที่แล้ว')->assertDontSee('<html', false)->assertDontSeeText('ภาพรวมการบริหารโครงการ');
    }
}
