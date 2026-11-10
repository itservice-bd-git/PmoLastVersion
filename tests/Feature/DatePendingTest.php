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
use App\Services\DeliveryFlow;
use App\Services\NotificationService;
use App\Services\PlanningBoard;
use App\Services\ProjectDates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * "รอวันใหม่": the due date is to move but the new one is not known yet - never late meanwhile, the one who owes the answer
 * is chased on the follow-up day, and the postponement that brings the date ends the wait and counts once. A project's
 * date can take its FAT and delivery rounds along (keep / shift / a date / wait too).
 */
class DatePendingTest extends TestCase
{
    use RefreshDatabase;

    private User $pm;

    private User $sales;

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
        $this->sales = User::forceCreate(['name' => 'วีระ', 'email' => 'wait-sales', 'password' => 'secret-pass', 'role' => 'sales', 'is_active' => true]);
        $this->clerk = User::forceCreate(['name' => 'นิด', 'email' => 'wait-ship', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $ship->id, 'is_active' => true]);
        // overdue already: due 5 Nov, today 10 Nov
        $this->project = Project::forceCreate(['project_no' => 'SO-W', 'project_name' => 'ตู้ไฟอาคาร', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal',
            'start_date' => '2026-10-01', 'due_date' => '2026-11-05', 'project_manager_id' => $this->pm->id, 'sales_person_id' => $this->sales->id]);
        $this->fat = ProjectFat::forceCreate(['project_id' => $this->project->id, 'round' => 1, 'fat_date' => '2026-11-17', 'fat_end_date' => '2026-11-18', 'status' => 'scheduled']);
        $this->round = ProjectDelivery::forceCreate(['project_id' => $this->project->id, 'round' => 1, 'delivery_date' => '2026-11-24', 'delivery_end_date' => '2026-11-25',
            'status' => 'waiting', 'cabinet_ids' => [], 'method' => 'company', 'created_by' => $this->pm->id]);
    }

    private function reason(): ProjectDateReason
    {
        return ProjectDateReason::where('name', 'ลูกค้าขอเลื่อน')->firstOrFail();
    }

    private function wait(array $over = [])
    {
        return $this->actingAs($this->pm)->post(route('projects.dates.store', $this->project), $over + [
            'kind' => 'pending', 'from' => 'sales', 'user_id' => $this->sales->id, 'follow_up' => '2026-11-13',
            'reason_id' => $this->reason()->id, 'note' => 'ลูกค้ายังไม่ยืนยันวันเข้าพื้นที่', 'notify' => 1,
            'events' => [
                ['type' => 'fat', 'id' => $this->fat->id, 'action' => 'keep'],
                ['type' => 'delivery', 'id' => $this->round->id, 'action' => 'pending'],
            ],
        ]);
    }

    public function test_waiting_is_never_late_and_is_said_everywhere(): void
    {
        $this->assertSame(1, Project::runningLate()->count(), 'late before the wait');
        $this->wait()->assertSessionHasNoErrors()->assertSessionHas('success', 'บันทึกการรอวันใหม่แล้ว · ไม่ขึ้นเลยกำหนดระหว่างรอ');

        $p = $this->project->fresh();
        $this->assertTrue($p->isDuePending());
        $this->assertSame(['sales', $this->sales->id, '2026-11-13', '2026-11-05'], [$p->due_pending_from, $p->due_pending_user_id, $p->due_pending_follow_up->format('Y-m-d'), $p->due_date->format('Y-m-d')], 'the due date stays');
        $this->assertSame(0, Project::runningLate()->count(), 'not late while waiting');
        $this->assertSame(1, Project::runningLate(false)->count(), 'on track meanwhile');
        $this->assertSame('pending', $p->risk_level);

        // not a round; kept in the history as a wait
        $this->assertSame(0, app(ProjectDates::class)->summary($p)['rounds']);
        $this->assertSame(ProjectDateChange::PENDING, ProjectDateChange::latest('id')->value('kind'));
        $this->assertSame('รอวันใหม่จากเซล (วีระ) · ตามอีกครั้ง 13/11/2569 · สาเหตุ: ลูกค้าขอเลื่อน — ลูกค้ายังไม่ยืนยันวันเข้าพื้นที่ · ส่งมอบ รอบ 1 รอวันใหม่ด้วย',
            ActivityLog::where('action', 'rescheduled')->latest('id')->value('description'));

        // the rounds: FAT kept, the delivery waits - the delivery department hears it, and cannot put vehicles on it yet
        $this->assertNull($this->fat->fresh()->date_pending_at);
        $this->assertNotNull($this->round->fresh()->date_pending_at);
        $this->assertSame(1, $this->clerk->notifications()->count());
        $this->assertSame('รอ PM ยืนยันวันส่ง · ยังไม่ต้องจัดรถ', $this->clerk->notifications()->first()->data['title']);
        try {
            app(DeliveryFlow::class)->assign($this->round->fresh(), ['vehicles' => []], $this->clerk);
            $this->fail('a waiting round takes no vehicles');
        } catch (ValidationException $e) {
            $this->assertSame(DeliveryFlow::PENDING_TEXT, collect($e->errors())->flatten()->first());
        }

        // the one who owes the date hears it (the PM pressed it: not told again)
        $this->assertSame(1, $this->sales->notifications()->count());
        $this->assertSame(0, $this->pm->notifications()->count());

        // pages: the projects list and Planning say รอวันใหม่, not late
        $this->actingAs($this->pm)->get(route('projects.index'))->assertOk()->assertSee('รอวันใหม่จากเซล · 0 วัน')->assertDontSee('เลยกำหนด 5 วัน');
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertOk()->assertSee('ได้วันแล้ว · เลื่อนวัน')->assertSee('ตามอีกครั้ง 13/11/2569');
        $task = collect(app(PlanningBoard::class)->tasks($this->pm))->firstWhere('id', 'p'.$this->project->id);
        $this->assertSame(['label' => 'รอวันใหม่จากเซล', 'days' => 0, 'followUp' => '2026-11-13', 'who' => 'วีระ'], $task['pending']);
        $this->assertTrue($task['deliveries'][0]['pending']);
    }

    public function test_the_postponement_that_brings_the_date_ends_the_wait_counts_once_and_takes_the_rounds_along(): void
    {
        $this->wait();
        $this->travelTo('2026-11-14 10:00:00');

        $this->actingAs($this->pm)->post(route('projects.dates.store', $this->project), [
            'kind' => 'postpone', 'due_date' => '2026-11-19', 'reason_id' => $this->reason()->id, 'note' => 'ได้วันจากเซลแล้ว', 'notify' => 1,
            'events' => [
                ['type' => 'fat', 'id' => $this->fat->id, 'action' => 'date', 'date' => '2026-11-16'],
                ['type' => 'delivery', 'id' => $this->round->id, 'action' => 'shift'],   // +14, as the project
            ],
        ])->assertSessionHasNoErrors()->assertSessionHas('success', 'บันทึกการเลื่อนวันครั้งที่ 1 แล้ว');

        $p = $this->project->fresh();
        $this->assertFalse($p->isDuePending());
        $this->assertNull($p->due_pending_follow_up);
        $this->assertSame('2026-11-19', $p->due_date->format('Y-m-d'));
        $this->assertSame(1, app(ProjectDates::class)->summary($p)['rounds'], 'the wait and its date: one round');
        $this->assertSame('ได้วันจากเซลแล้ว · รอวันใหม่มา 4 วัน', ProjectDateChange::where('kind', 'postpone')->value('note'));

        // FAT on a day of its own (its length kept), the delivery by the project's days; neither waits; both remembered
        $fat = $this->fat->fresh();
        $this->assertSame(['2026-11-16', '2026-11-17'], [$fat->fat_date->format('Y-m-d'), $fat->fat_end_date->format('Y-m-d')]);
        $round = $this->round->fresh();
        $this->assertSame(['2026-12-08', '2026-12-09', null], [$round->delivery_date->format('Y-m-d'), $round->delivery_end_date->format('Y-m-d'), $round->date_pending_at]);
        $this->assertSame(2, EventDateChange::where('project_id', $p->id)->where('kind', 'postpone')->where('note', 'เลื่อนพร้อมวันโครงการ — ได้วันจากเซลแล้ว')->count());
        $this->assertSame('เลื่อนวันโครงการ ครั้งที่ 1: วันครบกำหนด 05/11/2569 → 19/11/2569 (+14 วัน) · สาเหตุ: ลูกค้าขอเลื่อน — ได้วันจากเซลแล้ว · รอวันใหม่มา 4 วัน'
            .' · FAT รอบ 1 17–18/11/2569 (2 วัน) → 16–17/11/2569 (2 วัน) · ส่งมอบ รอบ 1 24–25/11/2569 → 08–09/12/2569',
            ActivityLog::where('action', 'rescheduled')->latest('id')->value('description'));
    }

    public function test_the_follow_up_day_chases_the_one_who_owes_the_date_and_the_pm_once_a_day(): void
    {
        $this->wait();
        $this->sales->unreadNotifications->markAsRead();   // read the first one: a new notice, not one folded into it
        $sent = fn () => [$this->sales->notifications()->where('data->kind', 'date_pending')->count(), $this->pm->notifications()->where('data->kind', 'date_pending')->count()];
        $before = $sent();

        $this->travelTo('2026-11-12 08:00:00');
        app(NotificationService::class)->sendReminders();
        $this->assertSame($before, $sent(), 'not yet: the follow-up day is the 13th');

        $this->travelTo('2026-11-13 08:00:00');
        app(NotificationService::class)->sendReminders();
        app(NotificationService::class)->sendReminders();   // the second run of the day adds nothing
        $after = $sent();
        $this->assertSame([1, 1], [$after[0] - $before[0], $after[1] - $before[1]]);
        $notice = $this->pm->notifications()->where('data->kind', 'date_pending')->latest()->first()->data;
        $this->assertStringStartsWith('ยังไม่ได้วันใหม่ · SO-W — รอวันใหม่จากเซล (วีระ) มา 3 วัน', $notice['latest']);   // a project's notice: its newest event
        $this->assertTrue($notice['todo'] ?? \App\Notifications\PmoNotice::todoOf($notice), 'a "ต้องทำ"');

        // "ตามอีก 3 วัน": asked, not answered - the next day to chase
        $this->actingAs($this->pm)->post(route('projects.dates.pending', $this->project), ['action' => 'later'])->assertSessionHas('success', 'ตามอีกครั้ง 16/11/2569');
        $this->assertSame('2026-11-16', $this->project->fresh()->due_pending_follow_up->format('Y-m-d'));

        // "ยกเลิกการรอ": the old date stands, late again
        $this->actingAs($this->pm)->post(route('projects.dates.pending', $this->project), ['action' => 'cancel'])->assertSessionHas('success', 'ยกเลิกการรอวันใหม่แล้ว');
        $this->assertFalse($this->project->fresh()->isDuePending());
        $this->assertSame(1, Project::runningLate()->count());
    }

    public function test_a_wait_needs_from_whom_when_to_ask_again_and_why_and_is_a_projects_own(): void
    {
        $this->wait(['from' => '', 'follow_up' => '', 'reason_id' => '', 'note' => ''])
            ->assertSessionHasErrors(['from' => 'เลือกว่ารอวันจากใคร', 'follow_up' => 'ใส่วันที่จะตามอีกครั้ง', 'reason_id' => 'เลือกสาเหตุ', 'note' => 'ใส่รายละเอียด']);
        $this->wait(['follow_up' => '2026-11-09'])->assertSessionHasErrors(['follow_up' => 'วันที่จะตามอีกครั้งต้องไม่ก่อนวันนี้']);
        $this->assertFalse($this->project->fresh()->isDuePending());

        // a cabinet's dates: postpone / correct only
        $cabinet = \App\Models\Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'MDB', 'status' => 'not_started', 'due_date' => '2026-11-05']);
        $this->actingAs($this->pm)->post(route('cabinets.dates.store', $cabinet), ['kind' => 'pending', 'from' => 'sales', 'follow_up' => '2026-11-13', 'reason_id' => $this->reason()->id, 'note' => 'x'])
            ->assertSessionHasErrors('kind');

        // a round that is not this project's, or not still to come, is left alone
        $this->wait(['events' => [['type' => 'delivery', 'id' => 999, 'action' => 'pending']]])->assertSessionHasNoErrors();
        $this->assertNull($this->round->fresh()->date_pending_at);
    }

    public function test_pm_saving_a_waiting_round_with_its_day_ends_its_wait(): void
    {
        $this->wait();
        $this->assertNotNull($this->round->fresh()->date_pending_at);
        app(DeliveryFlow::class)->revise($this->round->fresh(), ['delivery_date' => '2026-12-01'], $this->pm);
        $this->assertNull($this->round->fresh()->date_pending_at);
    }
}
