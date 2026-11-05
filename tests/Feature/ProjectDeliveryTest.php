<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Cabinet;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PlanningBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * การจัดส่ง (App\Services\DeliveryFlow): PM keeps a round as a draft or sends it with a window of days, the kind of vehicle
 * and how many people; the delivery department picks the day in it, the vehicle and the people, or sends it back with a
 * reason, then records it leaving and arriving; Planning shows the window dashed and the day solid.
 */
class ProjectDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $pm;

    private User $admin;

    private User $member;

    private User $clerk;   // แผนกจัดส่งสินค้า

    private Project $project;

    private Cabinet $cab1;

    private Cabinet $cab2;

    private Vehicle $truck;

    private Vehicle $van;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 08:00');

        $dept = Department::create(['name' => 'ไฟฟ้า', 'code' => 'EL', 'is_active' => true]);
        $ship = Department::create(['name' => 'จัดส่งสินค้า', 'code' => 'SH', 'is_active' => true]);
        AppSetting::put('department_permissions', [(string) $ship->id => ['edit.delivery' => true]]);
        $this->pm = User::forceCreate(['name' => 'PM', 'email' => 'dl-pm', 'password' => 'secret-pass', 'role' => 'project_manager', 'is_active' => true]);
        $this->admin = User::forceCreate(['name' => 'แอดมิน', 'email' => 'dl-admin', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        $this->member = User::forceCreate(['name' => 'วิภา', 'email' => 'dl-el', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $dept->id, 'is_active' => true]);
        $this->clerk = User::forceCreate(['name' => 'นิด', 'email' => 'dl-sh', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $ship->id, 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'SO-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal',
            'due_date' => '2026-11-20', 'project_manager_id' => $this->pm->id]);
        $this->cab1 = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'A', 'quantity' => 1, 'status' => 'not_started', 'progress' => 100]);
        $this->cab2 = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-2', 'cabinet_name' => 'B', 'quantity' => 1, 'status' => 'not_started', 'progress' => 40]);
        $this->truck = Vehicle::create(['name' => '6 ล้อเครน', 'type' => '6 ล้อเครน', 'plate' => '3กข-1234', 'capacity' => 1, 'driver_name' => 'สมศักดิ์', 'is_active' => true]);
        $this->van = Vehicle::create(['name' => '4 ล้อ', 'type' => '4 ล้อ', 'plate' => '1กก-4455', 'driver_name' => 'ประยุทธ', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function data(array $over = []): array
    {
        return $over + ['window_from' => '2026-11-18', 'window_to' => '2026-11-22', 'cabinet_ids' => [$this->cab1->id, $this->cab2->id],
            'method' => 'company', 'vehicle_type' => '6 ล้อเครน', 'crew_size' => 3, 'site' => 'บางนา ประตู 2', 'contact_name' => 'คุณวิทยา', 'contact_phone' => '0812345678'];
    }

    private function send(array $over = []): ProjectDelivery
    {
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data($over))->assertSessionHasNoErrors();

        return ProjectDelivery::latest('id')->firstOrFail();
    }

    /** The delivery department picks the day (on the truck unless said otherwise). */
    private function pick(ProjectDelivery $d, array $over = [])
    {
        return $this->actingAs($this->clerk)->post(route('deliveries.schedule', $d), $over + ['delivery_date' => '2026-11-20', 'delivery_time' => '09:00', 'vehicle_id' => $this->truck->id]);
    }

    private function lastLog(): string
    {
        return (string) ActivityLog::where('action', 'delivery')->latest('id')->value('description');
    }

    /** @return list<string> the titles of $user's notices of $kind */
    private function notices(User $user, string $kind): array
    {
        return $user->notifications()->get()->filter(fn ($n) => $n->data['kind'] === $kind)->map(fn ($n) => $n->data['title'])->values()->all();
    }

    public function test_pm_sends_a_round_with_a_window_and_the_delivery_department_has_it_to_do(): void
    {
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data(['vehicle_type' => '']))->assertSessionHasErrors(['vehicle_type' => 'เลือกประเภทรถ']);
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data(['vehicle_type' => '10 ล้อ']))
            ->assertSessionHasErrors(['vehicle_type' => 'ไม่มีรถประเภทนี้ · เพิ่มได้ที่ ตั้งค่า › รถขนส่ง']);
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data(['window_to' => '2026-11-17']))
            ->assertSessionHasErrors(['window_to' => 'วันสุดท้ายที่ส่งได้ต้องไม่ก่อนวันแรก']);
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data())->assertSessionHas('success', 'ส่งให้แผนกจัดส่งแล้ว');

        $d = ProjectDelivery::firstOrFail();
        $this->assertSame([1, 'waiting', null, null, null, '6 ล้อเครน', 3, 'รอกำหนดวัน', $this->pm->id],
            [$d->round, $d->status, $d->delivery_date, $d->vehicle_id, $d->driver_name, $d->vehicle_type, $d->crew_size, $d->stateLabel(), $d->sent_by], 'the vehicle and the people are the delivery department\'s');
        $this->assertSame('ส่งให้แผนกจัดส่ง รอบที่ 1: ส่งได้ 18–22/11/2569 · 2 ตู้ (MO-1, MO-2) · 6 ล้อเครน · 3 คน', $this->lastLog());
        $this->assertSame(['งานจัดส่งใหม่ · เลือกวันส่ง'], $this->notices($this->clerk, 'delivery_todo'));
        $this->assertTrue($this->clerk->notifications()->first()->data['todo']);
        $this->assertSame(0, $this->member->notifications()->count(), 'another department does not hear it');

        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertOk()
            ->assertSee('ส่งได้ 18–22/11/2569')->assertSee('รอแผนกจัดส่งเลือกวันส่งในช่วงนี้')->assertSee('6 ล้อเครน · 3 คน');
        $this->actingAs($this->clerk)->get(route('deliveries.index'))->assertOk()->assertViewHas('tab', 'waiting')
            ->assertSee('SO-1')->assertSee('ช่วงส่งได้ 18–22/11/2569')->assertSee('PM ขอ')->assertSee('6 ล้อเครน · 3 คน')->assertSee('เลือกวันส่ง')->assertSee('ไม่รับงาน');
    }

    public function test_the_delivery_department_picks_the_day_the_vehicle_and_the_people_and_pm_hears_of_another_kind(): void
    {
        $d = $this->send();
        $this->actingAs($this->member)->post(route('deliveries.schedule', $d), ['delivery_date' => '2026-11-20'])->assertForbidden();
        $this->pick($d, ['delivery_date' => '2026-11-23'])->assertSessionHasErrors(['delivery_date' => 'เลือกวันในช่วงที่ PM ให้: 18–22/11/2569']);
        $this->pick($d, ['vehicle_id' => ''])->assertSessionHasErrors(['vehicle_id' => 'เลือกรถ']);

        // the vehicle's usual driver unless another is typed, and the others who go (blank / repeated names dropped)
        $this->pick($d, ['crew' => ['วิชัย', '', 'อนันต์', 'วิชัย', 'สมศักดิ์']])->assertSessionHas('success', 'กำหนดวันส่งแล้ว');
        $d->refresh();
        $this->assertSame(['scheduled', '20/11/2569 · 09:00 น.', $this->clerk->id, 'กำหนดวันแล้ว'], [$d->status, $d->when(), $d->scheduled_by, $d->stateLabel()]);
        $this->assertSame([['สมศักดิ์', 'วิชัย', 'อนันต์'], 'สมศักดิ์, วิชัย, อนันต์ (3/3 คน)', false], [$d->people(), $d->peopleText(), $d->otherType()]);
        $this->assertSame('การจัดส่งรอบที่ 1: แผนกจัดส่งกำหนดวันส่ง 20/11/2569 · 09:00 น. · 6 ล้อเครน · 3กข-1234 · คน สมศักดิ์, วิชัย, อนันต์ (3/3 คน)', $this->lastLog());
        $this->assertSame(['แผนกจัดส่งกำหนดวันส่งแล้ว'], $this->notices($this->pm, 'delivery'));
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertSee('สมศักดิ์, วิชัย, อนันต์ (3/3 คน)')->assertSee('PM ขอ 6 ล้อเครน · 3 คน');

        // another kind of vehicle than PM asked for (its usual driver comes with it): allowed, PM hears it once; refusing is too late now
        $this->pick($d, ['vehicle_id' => $this->van->id, 'crew' => ['วิชัย']]);
        $d->refresh();
        $this->assertSame([$this->van->id, 'ประยุทธ', true, 'ประยุทธ, วิชัย (2/3 คน)'], [$d->vehicle_id, $d->driver_name, $d->otherType(), $d->peopleText()]);
        $this->assertSame(['แผนกจัดส่งใช้รถคนละประเภท'], $this->notices($this->pm->fresh(), 'delivery'), 'the round\'s notice, updated');
        $this->assertStringContainsString('PM ขอ 6 ล้อเครน · ได้ 4 ล้อ (4 ล้อ · 1กก-4455)', $this->pm->notifications()->first()->data['body']);
        $this->pick($d, ['vehicle_id' => $this->van->id, 'delivery_time' => '10:00']);
        $this->assertSame(['แผนกจัดส่งเลื่อนวันส่ง'], $this->notices($this->pm->fresh(), 'delivery'));
        $this->assertStringNotContainsString('PM ขอ', $this->pm->notifications()->first()->data['body'], 'the same vehicle again: told once');
        $this->actingAs($this->clerk)->get(route('deliveries.index', ['round' => $d->id]))->assertSee('คนละประเภท')->assertSee('ยังไม่ครบที่ PM ขอ', false);
        $this->actingAs($this->clerk)->post(route('deliveries.reject', $d), ['reason' => 'รถไม่ว่างทั้งช่วงวัน'])
            ->assertSessionHasErrors(['delivery' => 'ไม่รับงานได้เฉพาะรอบที่ยังรอกำหนดวัน — รอบนี้กำหนดวันแล้ว · ติดต่อ PM เพื่อแก้หรือยกเลิก']);

        $this->actingAs($this->clerk)->get(route('deliveries.index', ['round' => $d->id]))->assertViewHas('tab', 'scheduled')->assertSee('20/11/2569 · 10:00 น.');
    }

    public function test_sent_back_with_a_reason_pm_fixes_it_and_sends_it_again(): void
    {
        $d = $this->send();
        $this->actingAs($this->clerk)->post(route('deliveries.reject', $d), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->clerk)->post(route('deliveries.reject', $d), ['reason' => 'อื่น ๆ'])->assertSessionHasErrors(['reason' => 'พิมพ์รายละเอียดของเหตุผล']);
        $this->actingAs($this->clerk)->post(route('deliveries.reject', $d), ['reason' => 'รถไม่ว่างทั้งช่วงวัน', 'note' => 'ขอเลื่อนเป็น 28–29/11'])->assertSessionHas('success');

        $d->refresh();
        $this->assertSame(['rejected', 'รถไม่ว่างทั้งช่วงวัน', $this->clerk->id], [$d->status, $d->rejected_reason, $d->rejected_by]);
        $this->assertSame('การจัดส่งรอบที่ 1: แผนกจัดส่งไม่รับงาน — เหตุผล: รถไม่ว่างทั้งช่วงวัน · ขอเลื่อนเป็น 28–29/11', $this->lastLog());
        $this->assertSame(['แผนกจัดส่งไม่รับงาน'], $this->notices($this->pm, 'delivery_rejected'));
        $this->assertTrue($this->pm->notifications()->first()->data['todo']);
        $this->actingAs($this->clerk)->post(route('deliveries.schedule', $d), ['delivery_date' => '2026-11-20'])->assertSessionHasErrors(['delivery' => 'รอบนี้ส่งกลับให้ PM แล้ว · รอ PM ส่งมาอีกครั้ง']);
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertSee('แผนกจัดส่งไม่รับงาน · รถไม่ว่างทั้งช่วงวัน · ขอเลื่อนเป็น 28–29/11')->assertSee('แก้แล้วส่งอีกครั้ง');

        $this->actingAs($this->pm)->put(route('project-deliveries.update', $d), $this->data(['window_from' => '2026-11-28', 'window_to' => '2026-11-29']))
            ->assertSessionHas('success', 'ส่งให้แผนกจัดส่งอีกครั้งแล้ว');
        $d->refresh();
        $this->assertSame(['waiting', null, null], [$d->status, $d->rejected_reason, $d->rejected_at]);
        $this->assertSame('การจัดส่งรอบที่ 1: ส่งให้จัดส่งอีกครั้ง · ส่งได้ 28–29/11/2569 · 2 ตู้ (MO-1, MO-2) · 6 ล้อเครน · 3 คน', $this->lastLog());
        $this->assertSame(['งานจัดส่งใหม่ · เลือกวันส่ง', 'PM ส่งรอบส่งให้อีกครั้ง · เลือกวันส่ง'], $this->notices($this->clerk->fresh(), 'delivery_todo'));
    }

    public function test_a_new_window_that_leaves_out_the_picked_day_sends_it_back_to_be_picked(): void
    {
        $d = $this->send();
        $this->pick($d);

        $this->actingAs($this->pm)->put(route('project-deliveries.update', $d), $this->data(['window_from' => '2026-11-19']))->assertSessionHasNoErrors();
        $this->assertSame(['scheduled', '2026-11-20'], [$d->fresh()->status, $d->fresh()->delivery_date->format('Y-m-d')], 'still fits: kept');
        $this->assertSame(['PM แก้รอบส่ง: ช่วงวัน ส่งได้ 19–22/11/2569'], $this->notices($this->clerk, 'delivery'));

        $this->actingAs($this->pm)->put(route('project-deliveries.update', $d), $this->data(['window_from' => '2026-11-21']))->assertSessionHasNoErrors();
        $d->refresh();
        $this->assertSame(['waiting', null, null], [$d->status, $d->delivery_date, $d->delivery_time]);
        $this->assertStringEndsWith('วันส่งเดิม 20/11/2569 · 09:00 น. ไม่อยู่ในช่วงใหม่ · รอแผนกจัดส่งเลือกวันใหม่', $this->lastLog());
        $this->assertContains('PM เปลี่ยนช่วงวัน · เลือกวันส่งใหม่', $this->notices($this->clerk, 'delivery_todo'));
    }

    public function test_a_round_goes_on_one_day_of_the_window_and_is_late_after_it(): void
    {
        $d = $this->send(['window_from' => '2026-11-12', 'window_to' => '2026-11-16']);
        $this->assertSame('ส่งได้ 12–16/11/2569', $d->windowText());
        $this->assertFalse($d->isOverdue(Carbon::parse('2026-11-16')), 'a day can still be picked on the window\'s last day');
        $this->assertTrue($d->isOverdue(Carbon::parse('2026-11-17')));
        $this->pick($d, ['delivery_date' => '2026-11-11'])->assertSessionHasErrors(['delivery_date' => 'เลือกวันในช่วงที่ PM ให้: 12–16/11/2569']);
        $this->pick($d, ['delivery_date' => '2026-11-16', 'delivery_time' => ''])->assertSessionHasNoErrors();

        $d->refresh();
        $this->assertSame(['16/11/2569', '2026-11-16'], [$d->when(), $d->lastDay()->format('Y-m-d')]);
        $this->assertTrue($d->isOverdue(Carbon::parse('2026-11-17')), 'past its day and not there yet');
        $task = collect(app(PlanningBoard::class)->tasks($this->pm))->firstWhere('id', 'p'.$this->project->id);
        $this->assertSame(['2026-11-16', '2026-11-16', false], [$task['deliveries'][0]['date'], $task['deliveries'][0]['end'], $task['deliveries'][0]['wait']]);
    }

    public function test_it_leaves_and_arrives_and_what_did_not_arrive_can_be_booked_again(): void
    {
        $d = $this->send();
        $this->actingAs($this->clerk)->post(route('deliveries.depart', $d))->assertSessionHasErrors(['delivery' => 'รอบนี้ยังไม่ได้กำหนดวันส่ง']);
        $this->pick($d, ['delivery_date' => '2026-11-18']);
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data(['cabinet_ids' => [$this->cab2->id]]))
            ->assertSessionHasErrors(['cabinet_ids' => 'MO-2 อยู่ในรอบที่ 1 แล้ว · แก้รอบนั้นก่อน']);

        $this->actingAs($this->clerk)->post(route('deliveries.depart', $d))->assertSessionHas('success');
        $this->assertSame('departed', $d->fresh()->status);
        $this->actingAs($this->pm)->put(route('project-deliveries.update', $d), $this->data())->assertSessionHasErrors('delivery');
        $this->actingAs($this->clerk)->post(route('deliveries.deliver', $d), ['delivered_date' => '2026-11-18', 'cabinet_ids' => [$this->cab1->id]])->assertSessionHasErrors('receiver');
        $this->actingAs($this->clerk)->post(route('deliveries.deliver', $d), ['delivered_date' => '2026-11-18', 'delivered_time' => '10:40', 'receiver' => 'คุณสมชาย',
            'doc_no' => 'DO-0412', 'issue' => 'รอยขีดข่วน', 'cabinet_ids' => [$this->cab1->id]])->assertSessionHasNoErrors();

        $d->refresh();
        $this->assertSame(['delivered', [$this->cab1->id], '2026-11-18 10:40'], [$d->status, $d->cabinet_ids, $d->delivered_at->format('Y-m-d H:i')]);
        $this->assertSame('การจัดส่งรอบที่ 1: ส่งถึงแล้ว 18/11/2569 10:40 น. · 18/11/2569 · 09:00 น. · 1 ตู้ (MO-1) · 6 ล้อเครน · 3กข-1234 · 3 คน · ผู้รับ คุณสมชาย · ใบส่งของ DO-0412 · ปัญหา: รอยขีดข่วน · ไม่ได้ส่ง MO-2', $this->lastLog());
        $this->assertSame(['ส่งถึงแล้ว · มีปัญหาที่หน้างาน'], $this->notices($this->pm, 'delivery_rejected'), 'a problem on site is for PM to act on');

        // MO-2 is free again; MO-1 has arrived
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data(['cabinet_ids' => [$this->cab1->id]]))
            ->assertSessionHasErrors(['cabinet_ids' => 'MO-1 อยู่ในรอบที่ 1 แล้ว (ส่งถึงแล้ว)']);
        $this->assertNull($this->send(['cabinet_ids' => [$this->cab2->id], 'method' => 'external', 'carrier' => 'ไทยขนส่ง'])->vehicle_type, 'only the company\'s vehicles have a kind');
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertSee('ส่งแล้ว 1 / 2 ตู้')->assertSee('ขนส่งภายนอก · ไทยขนส่ง')->assertSee('คุณสมชาย · ใบส่งของ DO-0412');

        // pressed by mistake: one step back at a time
        $this->actingAs($this->clerk)->post(route('deliveries.back', $d))->assertSessionHasNoErrors();
        $this->assertSame(['departed', null], [$d->fresh()->status, $d->fresh()->receiver]);
        $this->actingAs($this->clerk)->post(route('deliveries.back', $d));
        $this->assertSame(['scheduled', null], [$d->fresh()->status, $d->fresh()->departed_at]);
    }

    public function test_who_may_do_what_and_a_closed_project_refuses(): void
    {
        $this->actingAs($this->member)->post(route('projects.deliveries.store', $this->project), $this->data())->assertForbidden();
        $this->actingAs($this->member)->get(route('projects.deliveries.form', $this->project))->assertForbidden();
        $this->actingAs($this->clerk)->post(route('projects.deliveries.store', $this->project), $this->data())->assertForbidden();
        $this->actingAs($this->member)->get(route('deliveries.index'))->assertForbidden();
        $this->actingAs($this->member)->get(route('dashboard'))->assertDontSee(route('deliveries.index'));
        $this->actingAs($this->clerk)->get(route('deliveries.index'))->assertOk()->assertSee('ไม่มีรอบที่รอกำหนดวัน');

        $this->project->update(['status' => 'completed']);
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data());
        $this->assertSame(0, ProjectDelivery::count());
    }

    public function test_planning_carries_the_window_then_the_day_and_opens_the_window_that_saves_as_json(): void
    {
        $this->actingAs($this->pm)->get(route('projects.deliveries.form', $this->project))->assertOk()->assertSee('id="project-delivery"', false)->assertSee('MO-2')
            ->assertViewHas('f', fn ($f) => $f['title'] === 'นัดส่งรอบที่ 1' && $f['verb'] === 'POST' && $f['method'] === 'company');
        $this->actingAs($this->pm)->postJson(route('projects.deliveries.store', $this->project), $this->data(['cabinet_ids' => []]))->assertStatus(422)->assertJsonValidationErrors('cabinet_ids');
        $this->actingAs($this->pm)->postJson(route('projects.deliveries.store', $this->project), $this->data())->assertOk()->assertExactJson(['message' => 'ส่งให้แผนกจัดส่งแล้ว']);
        $d = ProjectDelivery::firstOrFail();
        $this->actingAs($this->pm)->get(route('projects.deliveries.form', $this->project))
            ->assertViewHas('cabinets', fn ($c) => $c[0]['where'] === 'อยู่ในรอบที่ 1' && $c[1]['progress'] === 40)
            ->assertViewHas('types', ['4 ล้อ', '6 ล้อเครน']);
        // a card of the จัดส่ง tab opens its own round; a round of another project is not found
        $this->actingAs($this->pm)->get(route('projects.deliveries.form', [$this->project, 'delivery' => $d->id]))
            ->assertViewHas('f', fn ($f) => $f['id'] === $d->id && $f['verb'] === 'PUT' && $f['window_from'] === '2026-11-18' && $f['picked'] === '');
        $other = Project::forceCreate(['project_no' => 'SO-2', 'project_name' => 'X', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $this->actingAs($this->pm)->get(route('projects.deliveries.form', [$other, 'delivery' => $d->id]))->assertNotFound();

        $json = fn () => collect(app(PlanningBoard::class)->tasks($this->pm))->firstWhere('id', 'p'.$this->project->id)['deliveries'];
        $this->assertSame([['id' => $d->id, 'round' => 1, 'date' => '2026-11-18', 'end' => '2026-11-22', 'time' => '', 'status' => 'waiting', 'wait' => true, 'why' => '',
            'cabs' => ['c'.$this->cab1->id, 'c'.$this->cab2->id], 'method' => 'company', 'car' => '6 ล้อเครน', 'carFull' => '6 ล้อเครน', 'driver' => '', 'crew' => 3, 'people' => '',
            'dep' => null, 'at' => null]], $json(), 'no vehicle yet: the kind PM asked for');
        $this->actingAs($this->clerk)->postJson(route('deliveries.schedule', $d), ['delivery_date' => '2026-11-20', 'delivery_time' => '09:00', 'vehicle_id' => $this->truck->id])
            ->assertOk()->assertExactJson(['message' => 'กำหนดวันส่งแล้ว']);
        $this->assertSame(['2026-11-20', '2026-11-20', '09:00', 'scheduled', false, '6 ล้อเครน · 3กข-1234', 'สมศักดิ์ (1/3 คน)'],
            array_values(array_intersect_key($json()[0], array_flip(['date', 'end', 'time', 'status', 'wait', 'carFull', 'people']))));

        $this->actingAs($this->pm)->deleteJson(route('project-deliveries.destroy', $d))->assertOk()->assertExactJson(['message' => 'ยกเลิกรอบส่งแล้ว']);
        $this->assertSame(0, ProjectDelivery::count());
        $this->assertContains('PM ยกเลิกรอบส่ง', $this->notices($this->clerk, 'delivery'));
    }

    public function test_the_delivery_department_keeps_the_vehicles_and_the_reasons(): void
    {
        $this->actingAs($this->member)->get(route('settings.vehicles.index'))->assertForbidden();
        $this->actingAs($this->clerk)->get(route('settings.vehicles.index'))->assertOk()->assertSee('6 ล้อเครน')->assertSee('รถไม่ว่างทั้งช่วงวัน');
        $this->actingAs($this->clerk)->post(route('settings.vehicles.store'), ['name' => 'กระบะขาว', 'type' => ' กระบะ ', 'plate' => '1กข-9999', 'capacity' => 1])->assertSessionHasNoErrors();
        $pickup = Vehicle::where('name', 'กระบะขาว')->firstOrFail();
        $this->assertSame(['4 ล้อ', '6 ล้อเครน', 'กระบะ'], Vehicle::types(), 'PM asks for the kinds of the vehicles in use');
        $this->actingAs($this->clerk)->delete(route('settings.vehicles.destroy', $pickup));
        $this->assertNull(Vehicle::find($pickup->id));

        $this->pick($this->send());
        $this->actingAs($this->admin)->delete(route('settings.vehicles.destroy', $this->truck));
        $this->assertFalse($this->truck->fresh()->is_active);
        $this->assertSame('6 ล้อเครน · 3กข-1234', ProjectDelivery::first()->transport(), 'its rounds still say which vehicle went');

        $this->actingAs($this->clerk)->put(route('settings.vehicles.reasons'), ['reasons' => " คิวรถเต็ม \nอื่น ๆ\n\nลูกค้ายังไม่พร้อมรับ\nคิวรถเต็ม"])->assertSessionHasNoErrors();
        $this->assertSame(['คิวรถเต็ม', 'ลูกค้ายังไม่พร้อมรับ'], ProjectDelivery::rejectReasons());
        $this->actingAs($this->clerk)->get(route('deliveries.index'))->assertViewHas('reasons', ['คิวรถเต็ม', 'ลูกค้ายังไม่พร้อมรับ']);
        $this->actingAs($this->clerk)->put(route('settings.vehicles.reasons'), ['reasons' => '']);
        $this->assertSame(ProjectDelivery::REJECT_REASONS, ProjectDelivery::rejectReasons());
    }

    public function test_pm_keeps_a_draft_for_every_cabinet_and_sends_it_when_the_cabinets_are_checked(): void
    {
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data(['send' => '0', 'cabinet_ids' => [], 'vehicle_type' => '']))
            ->assertSessionHas('success', 'บันทึกร่างการจัดส่งแล้ว');
        $d = ProjectDelivery::firstOrFail();
        $this->assertSame(['draft', [], null, 'ร่าง', 'ทุกตู้'], [$d->status, $d->cabinet_ids, $d->sent_by, $d->stateLabel(), $d->cabinetsText()]);
        $this->assertSame('ร่างการจัดส่ง รอบที่ 1: ส่งได้ 18–22/11/2569 · ทุกตู้ · - · 3 คน', $this->lastLog());
        $this->assertSame(0, $this->clerk->notifications()->count(), 'a draft is PM\'s own');
        $this->actingAs($this->clerk)->get(route('deliveries.index'))->assertSee('ไม่มีรอบที่รอกำหนดวัน')->assertDontSee('SO-1');
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertSee('ร่าง · ยังไม่ได้ส่งให้แผนกจัดส่ง')->assertSee('ตรวจแล้วส่งให้แผนกจัดส่ง');
        $this->assertTrue(collect(app(PlanningBoard::class)->tasks($this->pm))->firstWhere('id', 'p'.$this->project->id)['deliveries'][0]['wait']);

        // its window ticks the cabinets free to go; sending needs them and the kind of vehicle
        $this->actingAs($this->pm)->get(route('projects.deliveries.form', [$this->project, 'delivery' => $d->id]))
            ->assertViewHas('f', fn ($f) => $f['draft'] && $f['all'] && $f['cabinet_ids'] === [$this->cab1->id, $this->cab2->id] && $f['title'] === 'ร่างการจัดส่งรอบที่ 1');
        $this->actingAs($this->pm)->put(route('project-deliveries.update', $d), $this->data(['send' => '1', 'cabinet_ids' => [], 'vehicle_type' => '']))
            ->assertSessionHasErrors(['cabinet_ids', 'vehicle_type']);
        $this->actingAs($this->pm)->put(route('project-deliveries.update', $d), $this->data(['send' => '1']))->assertSessionHas('success', 'ส่งให้แผนกจัดส่งแล้ว');
        $d->refresh();
        $this->assertSame(['waiting', $this->pm->id, 2], [$d->status, $d->sent_by, count($d->cabinet_ids)]);
        $this->assertSame('ส่งให้แผนกจัดส่ง รอบที่ 1: ส่งได้ 18–22/11/2569 · 2 ตู้ (MO-1, MO-2) · 6 ล้อเครน · 3 คน', $this->lastLog());
        $this->assertSame(['งานจัดส่งใหม่ · เลือกวันส่ง'], $this->notices($this->clerk, 'delivery_todo'));
    }

    public function test_the_new_project_page_books_the_fat_day_and_a_draft_delivery(): void
    {
        $this->actingAs($this->pm)->get(route('projects.create'))->assertOk()->assertSee('วัน FAT')->assertSee('ถึงวันที่')->assertSee('การจัดส่ง')->assertSee('สถานที่ส่ง')->assertSee('ขนส่งภายนอก')->assertSee('6 ล้อเครน');
        $base = ['project_no' => 'SO-9', 'project_name' => 'ใหม่', 'customer_name' => 'ลูกค้า', 'priority' => 'normal'];
        $this->actingAs($this->pm)->post(route('projects.store'), $base + ['delivery_from' => '2026-12-10'])->assertSessionHasErrors(['delivery_to' => 'ใส่ช่วงวันส่งให้ครบ (ตั้งแต่ – ถึง)']);
        $this->assertNull(Project::where('project_no', 'SO-9')->first(), 'nothing saved with a half window');

        $this->actingAs($this->pm)->post(route('projects.store'), $base + ['delivery_site' => 'บางนา'])->assertSessionHasErrors(['delivery_from' => 'ใส่ช่วงวันส่งให้ครบ (ตั้งแต่ – ถึง)']);
        $this->actingAs($this->pm)->post(route('projects.store'), $base + ['fat_time' => '13:30'])->assertSessionHasErrors(['fat_date' => 'ใส่วัน FAT ก่อน']);

        // the same fields as the project page's windows, for every cabinet; the delivery stays a draft
        $this->actingAs($this->pm)->post(route('projects.store'), $base + ['fat_date' => '2026-12-01', 'fat_end_date' => '2026-12-02', 'fat_time' => '13:30', 'fat_status' => 'scheduled', 'fat_note' => 'ลูกค้า 3 คน',
            'delivery_method' => 'company', 'delivery_from' => '2026-12-10', 'delivery_to' => '2026-12-12', 'delivery_vehicle_type' => '6 ล้อเครน', 'delivery_crew_size' => '4',
            'delivery_carrier' => 'ไม่ใช้', 'delivery_site' => 'บางนา ประตู 2', 'delivery_contact_name' => 'คุณวิทยา', 'delivery_contact_phone' => '0812345678', 'delivery_note' => 'ต้องใช้เครน'])->assertSessionHasNoErrors();
        $p = Project::where('project_no', 'SO-9')->firstOrFail();
        $fat = $p->fats()->firstOrFail();
        $this->assertSame([1, '2026-12-01', '2026-12-02', '13:30', null, 'scheduled', 'ลูกค้า 3 คน'],
            [$fat->round, $fat->fat_date->format('Y-m-d'), $fat->fat_end_date->format('Y-m-d'), $fat->fat_time, $fat->cabinet_ids, $fat->status, $fat->note]);
        $d = $p->deliveries()->firstOrFail();
        $this->assertSame(['draft', 1, '2026-12-10', '2026-12-12', [], 'company', '6 ล้อเครน', 4, null, 'บางนา ประตู 2', 'คุณวิทยา', '0812345678', 'ต้องใช้เครน'],
            [$d->status, $d->round, $d->window_from->format('Y-m-d'), $d->window_to->format('Y-m-d'), $d->cabinet_ids, $d->method, $d->vehicle_type, $d->crew_size,
                $d->carrier, $d->site, $d->contact_name, $d->contact_phone, $d->note]);
        $this->assertSame(0, $this->clerk->notifications()->count());
        // its window opens with them, the cabinets added since ticked
        $cab = Cabinet::forceCreate(['project_id' => $p->id, 'mo_no' => 'MO-9', 'cabinet_name' => 'A', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->actingAs($this->pm)->get(route('projects.deliveries.form', [$p, 'delivery' => $d->id]))
            ->assertViewHas('f', fn ($f) => $f['draft'] && $f['site'] === 'บางนา ประตู 2' && $f['crew_size'] === '4' && $f['cabinet_ids'] === [$cab->id]);

        // an outside carrier: no kind of vehicle, its carrier kept
        $this->actingAs($this->pm)->post(route('projects.store'), ['project_no' => 'SO-11'] + $base + ['delivery_method' => 'external', 'delivery_from' => '2026-12-10', 'delivery_to' => '2026-12-10',
            'delivery_vehicle_type' => '6 ล้อเครน', 'delivery_carrier' => 'ไทยขนส่ง'])->assertSessionHasNoErrors();
        $d = Project::where('project_no', 'SO-11')->firstOrFail()->deliveries()->firstOrFail();
        $this->assertSame(['external', null, 'ไทยขนส่ง', 'draft'], [$d->method, $d->vehicle_type, $d->carrier, $d->status]);

        // without the นัดหมาย fields: just the project
        $this->actingAs($this->pm)->post(route('projects.store'), ['project_no' => 'SO-10'] + $base)->assertSessionHasNoErrors();
        $p = Project::where('project_no', 'SO-10')->firstOrFail();
        $this->assertSame([0, 0], [$p->fats()->count(), $p->deliveries()->count()]);
    }
}
