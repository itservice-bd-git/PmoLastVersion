<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Department;
use App\Models\EventDateChange;
use App\Models\Project;
use App\Models\ProjectDateChange;
use App\Models\ProjectDateReason;
use App\Models\ProjectDelivery;
use App\Models\ProjectFat;
use App\Models\User;
use App\Notifications\PmoNotice;
use App\Services\DeliveryFlow;
use App\Services\NotificationService;
use App\Services\PlanningBoard;
use App\Services\ProjectDates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * "รอฝ่ายขายแจ้งกำหนดส่งอีกครั้ง": a project's dates put off with no new ones yet - no date shown, nothing counted or
 * chased, never late; its FAT rounds wait with it and its delivery rounds are taken back as drafts (vehicles, people and
 * packing cleared; one already billed stays with the delivery department). When sales has the dates, the project and
 * every waiting round get theirs in one postponement, counted once, and PM sends the drafts again the usual way.
 */
class DatePendingTest extends TestCase
{
    use RefreshDatabase;

    private User $pm;

    private User $follower;

    private User $clerk;

    private Project $project;

    private ProjectFat $fat;

    private ProjectDelivery $round;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 09:00:00');

        $ship = Department::create(['name' => 'จัดส่งสินค้า', 'code' => 'SH', 'is_active' => true]);
        AppSetting::put('department_permissions', [(string) $ship->id => ['edit.delivery' => true]]);
        $this->pm = User::forceCreate(['name' => 'วรรณา', 'email' => 'wait-pm', 'password' => 'secret-pass', 'role' => 'project_manager', 'is_active' => true]);
        $this->follower = User::forceCreate(['name' => 'วีระ', 'email' => 'wait-sales', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        $this->clerk = User::forceCreate(['name' => 'นิด', 'email' => 'wait-ship', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $ship->id, 'is_active' => true]);
        // overdue already: due 5 Nov, today 10 Nov
        $this->project = Project::forceCreate(['project_no' => 'SO-W', 'project_name' => 'ตู้ไฟอาคาร', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal',
            'start_date' => '2026-10-01', 'due_date' => '2026-11-05', 'project_manager_id' => $this->pm->id]);
        $this->project->followers()->attach($this->follower->id);
        $this->fat = ProjectFat::forceCreate(['project_id' => $this->project->id, 'round' => 1, 'fat_date' => '2026-11-17', 'fat_end_date' => '2026-11-18', 'status' => 'scheduled']);
        // sent to the delivery department, vehicles on it, a cabinet packed
        $this->round = ProjectDelivery::forceCreate(['project_id' => $this->project->id, 'round' => 1, 'delivery_date' => '2026-11-24', 'delivery_end_date' => '2026-11-25',
            'status' => 'scheduled', 'cabinet_ids' => [], 'packed' => ['7' => '2026-11-09 10:00'], 'method' => 'company', 'created_by' => $this->pm->id]);
        $this->round->vehicles()->create(['label' => 'รถ 6 ล้อ', 'driver_name' => 'สมชาย', 'sequence' => 1]);
    }

    private function reason(): ProjectDateReason
    {
        return ProjectDateReason::where('name', 'ลูกค้าขอเลื่อน')->firstOrFail();
    }

    private function wait(array $over = [])
    {
        return $this->actingAs($this->pm)->post(route('projects.dates.store', $this->project), $over + [
            'kind' => 'pending', 'reason_id' => $this->reason()->id, 'note' => 'ลูกค้ายังไม่ยืนยันวันเข้าพื้นที่', 'notify' => 1,
        ]);
    }

    public function test_waiting_puts_the_dates_off_with_nothing_to_fill_in_but_why_and_is_never_late(): void
    {
        $this->assertSame(1, Project::runningLate()->count(), 'late before');
        $this->wait()->assertSessionHasNoErrors()->assertSessionHas('success', 'บันทึกแล้ว · รอฝ่ายขายแจ้งกำหนดส่งอีกครั้ง');

        $p = $this->project->fresh();
        $this->assertTrue($p->isDuePending());
        $this->assertSame('2026-11-05', $p->due_date->format('Y-m-d'), 'the old date kept underneath');
        $this->assertSame(0, Project::runningLate()->count(), 'not late while waiting');
        $this->assertSame('pending', $p->risk_level);
        $this->assertSame(0, app(ProjectDates::class)->summary($p)['rounds'], 'not a round');
        $this->assertSame(ProjectDateChange::PENDING, ProjectDateChange::latest('id')->value('kind'));
        $this->assertSame('รอฝ่ายขายแจ้งกำหนดส่งอีกครั้ง · วันครบกำหนดเดิม 05/11/2569 · สาเหตุ: ลูกค้าขอเลื่อน — ลูกค้ายังไม่ยืนยันวันเข้าพื้นที่ · FAT รอบ 1 รอด้วย · ส่งมอบ รอบ 1 รอด้วย (ดึงกลับเป็นร่าง)',
            ActivityLog::where('action', 'rescheduled')->latest('id')->value('description'));

        // FAT waits; the delivery round is taken back as a draft - no vehicles, nothing packed - and the delivery department told
        $this->assertNotNull($this->fat->fresh()->date_pending_at);
        $round = $this->round->fresh();
        $this->assertSame(['draft', null, 0], [$round->status, $round->packed, $round->vehicles()->count()]);
        $this->assertNotNull($round->date_pending_at);
        $this->assertSame('ดึงรอบนี้กลับ · รอฝ่ายขายแจ้งกำหนดส่ง', $this->clerk->notifications()->first()->data['title']);
        $this->assertSame([], collect($this->actingAs($this->clerk)->get(route('deliveries.index', ['tab' => 'scheduled']))->viewData('rows'))->all(), 'no longer theirs');
        try {
            app(DeliveryFlow::class)->assign($round, ['vehicles' => []], $this->clerk);
            $this->fail('a waiting round takes no vehicles');
        } catch (ValidationException $e) {
            $this->assertSame(DeliveryFlow::PENDING_TEXT, collect($e->errors())->flatten()->first());
        }

        // told as a postponement is - not a ต้องทำ - and never chased afterwards
        $this->assertSame(1, $this->follower->notifications()->count());
        $this->assertFalse(PmoNotice::todoOf($this->follower->notifications()->first()->data));
        $sent = DatabaseNotification::count();
        foreach (['2026-11-13', '2026-11-20'] as $day) {
            $this->travelTo($day.' 08:00:00');
            app(NotificationService::class)->sendReminders();
        }
        $this->assertSame($sent, DatabaseNotification::count(), 'no reminder, ever');

        // no date shown while waiting: the pages say รอฝ่ายขายแจ้งกำหนดส่ง / รอกำหนดส่ง
        $this->actingAs($this->pm)->get(route('projects.index'))->assertOk()->assertSee('รอฝ่ายขายแจ้งกำหนดส่ง')->assertDontSee('05/11/2569')->assertDontSee('data-countdown', false);
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertOk()
            ->assertSee('ได้วันแล้ว · ลงวันใหม่')->assertSee('ยกเลิกการรอ · ใช้วันเดิม')->assertSee('รอกำหนดส่ง')
            ->assertDontSee('อีก 7 วัน')->assertDontSee('วันเดิม 17–18/11/2569');
        $task = collect(app(PlanningBoard::class)->tasks($this->pm))->firstWhere('id', 'p'.$this->project->id);
        $this->assertSame(['label' => 'รอฝ่ายขายแจ้งกำหนดส่ง'], $task['pending']);
        $this->assertTrue($task['deliveries'][0]['pending']);
        $this->assertTrue($task['fat']['pending']);
    }

    public function test_a_round_already_billed_stays_with_the_delivery_department(): void
    {
        $this->round->forceFill(['billed_at' => now(), 'billed_by' => $this->clerk->id])->save();
        $this->wait()->assertSessionHasNoErrors();
        $round = $this->round->fresh();
        $this->assertSame(['scheduled', null, 1], [$round->status, $round->date_pending_at, $round->vehicles()->count()]);
        $this->assertNotNull($this->fat->fresh()->date_pending_at, 'FAT still waits');
    }

    public function test_the_dates_come_in_together_and_the_rounds_go_out_again_the_usual_way(): void
    {
        $this->wait();
        $this->travelTo('2026-11-14 10:00:00');
        $url = route('projects.dates.store', $this->project);
        $base = ['kind' => 'postpone', 'due_date' => '2026-12-19', 'reason_id' => $this->reason()->id, 'note' => 'ลูกค้ายืนยันแล้ว', 'notify' => 1];

        // every waiting round needs its day
        $this->actingAs($this->pm)->post($url, $base + ['events' => [['type' => 'fat', 'id' => $this->fat->id, 'action' => 'date', 'date' => '2026-12-01']]])
            ->assertSessionHasErrors(['events' => 'ใส่วันใหม่ของ ส่งมอบ รอบ 1 ด้วย']);
        $this->assertTrue($this->project->fresh()->isDuePending(), 'nothing saved');

        $this->actingAs($this->pm)->post($url, $base + ['events' => [
            ['type' => 'fat', 'id' => $this->fat->id, 'action' => 'date', 'date' => '2026-12-01'],
            ['type' => 'delivery', 'id' => $this->round->id, 'action' => 'date', 'date' => '2026-12-15'],
        ]])->assertSessionHasNoErrors()->assertSessionHas('success', 'บันทึกการเลื่อนวันครั้งที่ 1 แล้ว');

        $p = $this->project->fresh();
        $this->assertFalse($p->isDuePending());
        $this->assertSame('2026-12-19', $p->due_date->format('Y-m-d'));
        $this->assertSame(1, app(ProjectDates::class)->summary($p)['rounds'], 'the wait and its dates: one round');
        $this->assertSame('ลูกค้ายืนยันแล้ว · ได้วันจากฝ่ายขายแล้ว', ProjectDateChange::where('kind', 'postpone')->value('note'));

        $fat = $this->fat->fresh();
        $this->assertSame(['2026-12-01', '2026-12-02', null], [$fat->fat_date->format('Y-m-d'), $fat->fat_end_date->format('Y-m-d'), $fat->date_pending_at]);
        // the delivery round has its day and stays a draft: PM sends it again, the usual way
        $round = $this->round->fresh();
        $this->assertSame(['2026-12-15', '2026-12-16', null, 'draft'], [$round->delivery_date->format('Y-m-d'), $round->delivery_end_date->format('Y-m-d'), $round->date_pending_at, $round->status]);
        $this->assertSame(2, EventDateChange::where('project_id', $p->id)->where('kind', 'postpone')->count());

        app(DeliveryFlow::class)->revise($round, ['delivery_date' => '2026-12-15'], $this->pm, send: true);
        $this->assertSame('waiting', $round->fresh()->status);
        $this->assertSame([$round->id], collect($this->actingAs($this->clerk)->get(route('deliveries.index', ['tab' => 'waiting']))->viewData('rows'))->pluck('id')->all());
    }

    public function test_a_fat_or_delivery_round_whose_day_has_passed_waits_too_and_gets_a_new_day(): void
    {
        // FAT day passed with no result, the delivery day passed and not on the road
        $this->fat->forceFill(['fat_date' => '2026-11-03', 'fat_end_date' => null])->save();
        $this->round->forceFill(['delivery_date' => '2026-11-07', 'delivery_end_date' => null])->save();
        $this->assertSame(['FAT รอบ 1' => 'นัดแล้ว · เลยกำหนด', 'ส่งมอบ รอบ 1' => ProjectDelivery::statusName('scheduled').' · เลยกำหนด'],
            ProjectDates::linkedEvents($this->project)->pluck('state', 'label')->all());

        $this->wait()->assertSessionHasNoErrors();
        $this->assertNotNull($this->fat->fresh()->date_pending_at);
        $this->assertNotNull($this->round->fresh()->date_pending_at);

        $url = route('projects.dates.store', $this->project);
        $base = ['kind' => 'postpone', 'due_date' => '2026-12-19', 'reason_id' => $this->reason()->id, 'note' => 'ลูกค้ายืนยันแล้ว', 'notify' => 1];
        $this->actingAs($this->pm)->post($url, $base + ['events' => [['type' => 'delivery', 'id' => $this->round->id, 'action' => 'date', 'date' => '2026-12-15']]])
            ->assertSessionHasErrors(['events' => 'ใส่วันใหม่ของ FAT รอบ 1 ด้วย']);
        $this->actingAs($this->pm)->post($url, $base + ['events' => [
            ['type' => 'fat', 'id' => $this->fat->id, 'action' => 'date', 'date' => '2026-12-01'],
            ['type' => 'delivery', 'id' => $this->round->id, 'action' => 'date', 'date' => '2026-12-15'],
        ]])->assertSessionHasNoErrors();
        $this->assertSame(['2026-12-01', null], [$this->fat->fresh()->fat_date->format('Y-m-d'), $this->fat->fresh()->date_pending_at]);
        $this->assertSame(['2026-12-15', null], [$this->round->fresh()->delivery_date->format('Y-m-d'), $this->round->fresh()->date_pending_at]);
    }

    public function test_a_fat_round_with_its_result_stays_out_of_the_wait(): void
    {
        $this->fat->forceFill(['fat_date' => '2026-11-03', 'fat_end_date' => null, 'status' => 'passed'])->save();
        $this->wait()->assertSessionHasNoErrors();
        $this->assertNull($this->fat->fresh()->date_pending_at);
        $this->assertSame('2026-11-03', $this->fat->fresh()->fat_date->format('Y-m-d'));
    }

    public function test_calling_the_wait_off_keeps_every_old_date(): void
    {
        $this->wait();
        $this->actingAs($this->pm)->post(route('projects.dates.pending', $this->project), ['action' => 'cancel'])
            ->assertSessionHas('success', 'ยกเลิกการรอแล้ว · ใช้วันเดิม · รอบส่งที่ดึงกลับยังเป็นร่าง ส่งให้แผนกจัดส่งอีกครั้งที่ "การจัดส่ง"');
        $this->assertFalse($this->project->fresh()->isDuePending());
        $this->assertNull($this->fat->fresh()->date_pending_at);
        $round = $this->round->fresh();
        $this->assertSame([null, 'draft', '2026-11-24'], [$round->date_pending_at, $round->status, $round->delivery_date->format('Y-m-d')], 'its old day; PM sends it again');
        $this->assertSame(1, Project::runningLate()->count(), 'late again: the old date stands');
    }

    public function test_a_wait_needs_why_is_a_projects_own_and_is_not_begun_twice(): void
    {
        $this->wait(['reason_id' => '', 'note' => ''])->assertSessionHasErrors(['reason_id' => 'เลือกสาเหตุ', 'note' => 'ใส่รายละเอียด']);
        $this->assertFalse($this->project->fresh()->isDuePending());

        $cabinet = \App\Models\Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'MDB', 'status' => 'not_started', 'due_date' => '2026-11-05']);
        $this->actingAs($this->pm)->post(route('cabinets.dates.store', $cabinet), ['kind' => 'pending', 'reason_id' => $this->reason()->id, 'note' => 'x'])
            ->assertSessionHasErrors('kind');

        $this->wait()->assertSessionHasNoErrors();
        $this->wait()->assertSessionHasErrors(['kind' => 'โครงการนี้รอฝ่ายขายแจ้งกำหนดส่งอยู่แล้ว']);
    }
}
