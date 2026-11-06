<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\Department;
use App\Models\EventDateChange;
use App\Models\Project;
use App\Models\ProjectDateReason;
use App\Models\ProjectFat;
use App\Models\User;
use App\Services\PlanningBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** วัน FAT: Admin / PM set it on the project page; a failed round stays in the history; Planning shows the latest. */
class ProjectFatTest extends TestCase
{
    use RefreshDatabase;

    private User $pm;

    private User $member;

    private Project $project;

    private Cabinet $cab1;

    private Cabinet $cab2;

    protected function setUp(): void
    {
        parent::setUp();

        $dept = Department::create(['name' => 'ไฟฟ้า', 'code' => 'EL', 'is_active' => true]);
        $this->pm = User::forceCreate(['name' => 'PM', 'email' => 'fat-pm', 'password' => 'secret-pass', 'role' => 'project_manager', 'is_active' => true]);
        $this->member = User::forceCreate(['name' => 'วิภา', 'email' => 'fat-el', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $dept->id, 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'SO-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal', 'due_date' => '2026-11-15']);
        $this->cab1 = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'A', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->cab2 = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-2', 'cabinet_name' => 'B', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
    }

    private function fatData(array $over = []): array
    {
        return $over + ['fat_date' => '2026-11-04', 'fat_time' => '10:00', 'status' => 'scheduled', 'scope' => 'all', 'note' => 'ลูกค้า 3 คน'];
    }

    public function test_the_projects_list_shows_the_job_type_after_the_customer(): void
    {
        $type = \App\Models\JobType::create(['name' => 'ตู้ MDB งานโรงงาน', 'is_active' => true]);
        $this->project->forceFill(['job_type_id' => $type->id])->save();
        $this->actingAs($this->pm)->get(route('projects.index'))->assertOk()
            ->assertSeeInOrder(['<th class="whitespace-nowrap px-3 py-2.5">ลูกค้า</th>', '<th class="whitespace-nowrap px-3 py-2.5">ประเภทงาน</th>'], false)
            ->assertSeeInOrder(['SO-1', 'C', 'ตู้ MDB งานโรงงาน']);

        // ความสำคัญ: a chip like the due-date countdown
        $this->project->forceFill(['priority' => 'urgent'])->save();
        $this->actingAs($this->pm)->get(route('projects.index'))->assertOk()->assertSee('rounded-full px-2 py-0.5 text-[12px] font-semibold bg-red-100 text-red-700', false);
    }

    public function test_the_projects_list_shows_the_latest_fat(): void
    {
        $this->actingAs($this->pm)->get(route('projects.index'))->assertOk()->assertSee('<th class="whitespace-nowrap px-3 py-2.5">FAT</th>', false)->assertDontSee('นัดแล้ว');

        ProjectFat::forceCreate(['project_id' => $this->project->id, 'round' => 1, 'fat_date' => '2026-11-04', 'status' => 'failed']);
        ProjectFat::forceCreate(['project_id' => $this->project->id, 'round' => 2, 'fat_date' => '2026-11-20', 'fat_time' => '13:30', 'status' => 'scheduled']);
        $this->actingAs($this->pm)->get(route('projects.index'))->assertOk()
            ->assertSeeInOrder(['20/11/2569', '· 13:30 น.', 'นัดแล้ว', 'รอบ 2', 'หลังวันกำหนดส่ง'])->assertDontSee('04/11/2569');
    }

    public function test_pm_sets_moves_and_reschedules_a_fat(): void
    {
        $this->actingAs($this->pm)->post(route('projects.fats.store', $this->project), $this->fatData())->assertSessionHasNoErrors();
        $fat = $this->project->latestFat()->first();
        $this->assertSame([1, '2026-11-04', '10:00', null], [$fat->round, $fat->fat_date->format('Y-m-d'), $fat->fat_time, $fat->cabinet_ids]);
        $this->assertSame('ลงวัน FAT: 04/11/2569 · 10:00 น.', ActivityLog::where('action', 'fat')->latest('id')->value('description'));

        // moved, then failed: the next appointment is round 2 and round 1 stays
        $this->actingAs($this->pm)->put(route('project-fats.update', $fat), $this->fatData(['fat_date' => '2026-11-05', 'status' => 'failed']))->assertSessionHasNoErrors();
        $this->assertStringContainsString('เลื่อนจาก 04/11/2569 · 10:00 น. เป็น 05/11/2569 · 10:00 น. · สถานะ นัดแล้ว → ไม่ผ่าน', ActivityLog::where('action', 'fat')->latest('id')->value('description'));
        $this->actingAs($this->pm)->post(route('projects.fats.store', $this->project), $this->fatData(['fat_date' => '2026-11-12', 'scope' => 'some', 'cabinet_ids' => [$this->cab2->id]]));
        $this->assertSame([1, 2], $this->project->fats()->pluck('round')->all());
        $latest = $this->project->latestFat()->first();
        $this->assertSame([2, [$this->cab2->id]], [$latest->round, $latest->cabinet_ids]);

        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertOk()
            ->assertSee('รอบที่ 2 · นัดแล้ว')->assertSee('ประวัติ FAT')->assertSee('MO-2');
    }

    public function test_a_moved_fat_day_is_kept_with_its_reason_and_shows_in_the_panel(): void
    {
        $this->actingAs($this->pm)->post(route('projects.fats.store', $this->project), $this->fatData())->assertSessionHasNoErrors();
        $fat = $this->project->latestFat()->first();
        $reason = ProjectDateReason::where('name', 'ลูกค้าขอเลื่อน')->first();

        // a change that leaves the day alone keeps nothing
        $this->actingAs($this->pm)->put(route('project-fats.update', $fat), $this->fatData(['note' => 'ลูกค้า 4 คน']))->assertSessionHasNoErrors();
        $this->assertSame(0, EventDateChange::count());

        $this->actingAs($this->pm)->put(route('project-fats.update', $fat), $this->fatData(['fat_date' => '2026-11-06', 'move_reason_id' => $reason->id, 'move_note' => 'หน้างานยังไม่พร้อม']))->assertSessionHasNoErrors();
        $this->assertStringContainsString('เลื่อนจาก 04/11/2569 · 10:00 น. เป็น 06/11/2569 · 10:00 น. · สาเหตุ: ลูกค้าขอเลื่อน — หน้างานยังไม่พร้อม', ActivityLog::where('action', 'fat')->latest('id')->value('description'));
        // typed wrong: kept, not counted
        $this->actingAs($this->pm)->put(route('project-fats.update', $fat), $this->fatData(['fat_date' => '2026-11-07', 'fat_time' => '13:00', 'move_kind' => 'correct', 'move_reason_id' => $reason->id]))->assertSessionHasNoErrors();

        $changes = EventDateChange::orderBy('id')->get();
        $this->assertSame([['fat', $fat->id, 1, 'postpone', '2026-11-04', '2026-11-06', 'ลูกค้าขอเลื่อน', $this->pm->id], ['fat', $fat->id, 1, 'correct', '2026-11-06', '2026-11-07', null, $this->pm->id]],
            $changes->map(fn ($c) => [$c->event, $c->event_id, $c->round, $c->kind, $c->old_date->format('Y-m-d'), $c->new_date->format('Y-m-d'), $c->reason_name, $c->changed_by])->all());
        $this->assertSame(['06/11/2569 · 10:00 น.', '07/11/2569 · 13:00 น.', 2], [$changes[1]->fromText(), $changes[1]->toText(), $changes[0]->days()]);

        $this->assertSame(1, ProjectFat::formData($this->project, $fat->fresh(), $fat)['moves']);
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertOk()
            ->assertSee('เลื่อน 1 ครั้ง')->assertSeeInOrder(['ประวัติการเลื่อนวัน FAT', 'แก้ไข', 'ลงวันผิด · ไม่นับเป็นการเลื่อน', 'ครั้งที่ 1', '04/11/2569 · 10:00 น.', '+2 วัน', 'ลูกค้าขอเลื่อน', 'หน้างานยังไม่พร้อม'])
            ->assertSee('สาเหตุที่เลื่อน');

        // the history stays when the round is deleted
        $this->actingAs($this->pm)->delete(route('project-fats.destroy', $fat));
        $this->assertSame(2, EventDateChange::count());
    }

    public function test_the_cabinet_table_shows_each_cabinets_latest_fat_round(): void
    {
        $this->travelTo('2026-11-01');
        ProjectFat::forceCreate(['project_id' => $this->project->id, 'round' => 1, 'fat_date' => '2026-11-04', 'status' => 'failed']);
        ProjectFat::forceCreate(['project_id' => $this->project->id, 'round' => 2, 'fat_date' => '2026-11-12', 'fat_end_date' => '2026-11-13', 'status' => 'scheduled', 'cabinet_ids' => [$this->cab2->id]]);

        $rows = collect(app(\App\Services\ProjectOverview::class)->build($this->project->fresh(), $this->pm)['rows'])->keyBy('mo');
        $this->assertSame(['04/11/2569', 1, null, 'failed', 'ไม่ผ่าน'], array_values(\Illuminate\Support\Arr::only($rows['MO-1']['fat'], ['date', 'round', 'days', 'result', 'resultText'])));
        $this->assertSame(['12–13/11/2569', 2, 11, null, null], array_values(\Illuminate\Support\Arr::only($rows['MO-2']['fat'], ['date', 'round', 'days', 'result', 'resultText'])));

        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertOk()->assertSee('<th class="hidden px-2 py-2 lg:table-cell">FAT</th>', false);

        // the cabinet page: the same day, once the cabinet is in a round
        $this->actingAs($this->pm)->get(route('cabinets.show', $this->cab2))->assertOk()->assertSeeInOrder(['วัน FAT', '12–13/11/2569', '· 11 วัน', 'รอบที่ 2 · 1 ตู้ · ', 'ดู FAT ›']);
        $this->actingAs($this->pm)->get(route('cabinets.show', $this->cab1))->assertOk()->assertSeeInOrder(['วัน FAT', '04/11/2569', '· ไม่ผ่าน', 'ทุกตู้ · ', 'ดู FAT ›']);
        $other = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-3', 'cabinet_name' => 'C', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        ProjectFat::where('round', 1)->update(['cabinet_ids' => json_encode([$this->cab1->id])]);
        $this->actingAs($this->pm)->get(route('cabinets.show', $other))->assertOk()->assertDontSee('วัน FAT');
    }

    public function test_only_admin_and_pm_may_set_it_and_a_closed_project_refuses(): void
    {
        $this->actingAs($this->member)->post(route('projects.fats.store', $this->project), $this->fatData())->assertForbidden();
        $this->actingAs($this->pm)->post(route('projects.fats.store', $this->project), $this->fatData(['scope' => 'some', 'cabinet_ids' => [999]]))->assertSessionHasErrors('cabinet_ids.0');
        $this->assertSame(0, ProjectFat::count());

        $this->project->update(['status' => 'completed']);
        $this->actingAs($this->pm)->post(route('projects.fats.store', $this->project), $this->fatData());
        $this->assertSame(0, ProjectFat::count());
    }

    public function test_after_the_due_date_warns_and_planning_carries_the_latest_round(): void
    {
        $this->actingAs($this->pm)->post(route('projects.fats.store', $this->project), $this->fatData(['fat_date' => '2026-11-20']));
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertSee('FAT ช้ากว่าวันครบกำหนดของโครงการ');

        $task = collect(app(PlanningBoard::class)->tasks($this->pm))->firstWhere('id', 'p'.$this->project->id);
        $this->assertSame(['round' => 1, 'date' => '2026-11-20', 'end' => '2026-11-20', 'time' => '10:00', 'status' => 'scheduled', 'note' => 'ลูกค้า 3 คน', 'cabinets' => null], $task['fat']);
    }

    public function test_a_fat_over_several_days_keeps_its_last_day(): void
    {
        $url = route('projects.fats.store', $this->project);
        $this->actingAs($this->pm)->post($url, $this->fatData(['fat_end_date' => '2026-11-03']))->assertSessionHasErrors('fat_end_date');

        $this->actingAs($this->pm)->post($url, $this->fatData(['fat_end_date' => '2026-11-06']))->assertSessionHasNoErrors();
        $fat = $this->project->latestFat()->first();
        $this->assertSame(['04–06/11/2569 (3 วัน) · 10:00 น.', 3], [$fat->when(), $fat->days()]);
        $this->assertSame('ลงวัน FAT: 04–06/11/2569 (3 วัน) · 10:00 น.', ActivityLog::where('action', 'fat')->latest('id')->value('description'));
        $task = collect(app(PlanningBoard::class)->tasks($this->pm))->firstWhere('id', 'p'.$this->project->id);
        $this->assertSame(['2026-11-04', '2026-11-06'], [$task['fat']['date'], $task['fat']['end']]);

        // the last day counts for "after the due date"; the same day as the first one means one day
        $this->actingAs($this->pm)->put(route('project-fats.update', $fat), $this->fatData(['fat_date' => '2026-11-14', 'fat_end_date' => '2026-11-16']));
        $this->assertTrue($fat->fresh()->isAfterDue());
        $this->actingAs($this->pm)->put(route('project-fats.update', $fat), $this->fatData(['fat_end_date' => '2026-11-04']));
        $this->assertSame([null, '04/11/2569 · 10:00 น.'], [$fat->fresh()->fat_end_date, $fat->fresh()->when()]);
        $this->actingAs($this->pm)->get(route('projects.index'))->assertSee('04/11/2569');
    }

    public function test_planning_opens_the_fat_window_and_saves_through_it_as_json(): void
    {
        $this->actingAs($this->member)->get(route('projects.fats.form', $this->project))->assertForbidden();
        $this->actingAs($this->pm)->get(route('projects.fats.form', $this->project))->assertOk()
            ->assertSee('id="project-fat"', false)->assertSee('MO-2')
            ->assertViewHas('f', fn ($f) => $f['title'] === 'ลงวัน FAT' && $f['url'] === route('projects.fats.store', $this->project));

        $this->actingAs($this->pm)->postJson(route('projects.fats.store', $this->project), $this->fatData(['fat_date' => '']))->assertStatus(422)->assertJsonValidationErrors('fat_date');
        $this->actingAs($this->pm)->postJson(route('projects.fats.store', $this->project), $this->fatData())->assertOk()->assertExactJson(['message' => 'บันทึกวัน FAT แล้ว']);
        $fat = $this->project->latestFat()->first();

        // a FAT on the books: the window edits it; a failed one: the window books the next round
        $this->actingAs($this->pm)->get(route('projects.fats.form', $this->project))->assertViewHas('f', fn ($f) => $f['title'] === 'แก้วัน FAT' && $f['url'] === route('project-fats.update', $fat));
        $this->actingAs($this->pm)->putJson(route('project-fats.update', $fat), $this->fatData(['status' => 'failed']))->assertOk();
        $this->actingAs($this->pm)->get(route('projects.fats.form', $this->project))->assertViewHas('f', fn ($f) => $f['title'] === 'นัด FAT รอบที่ 2' && $f['method'] === 'POST');

        $this->actingAs($this->pm)->deleteJson(route('project-fats.destroy', $fat))->assertOk()->assertExactJson(['message' => 'ลบวัน FAT แล้ว']);
        $this->assertNull($fat->fresh());
    }
}
