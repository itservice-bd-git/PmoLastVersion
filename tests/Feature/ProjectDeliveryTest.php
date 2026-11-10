<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\EventDateChange;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PlanningBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * การจัดส่ง (App\Services\DeliveryFlow): PM keeps a round as a draft or sends it with its day, the kinds of vehicle and
 * how many people; the delivery department puts the vehicles and the people on it (several) or sends it back for another
 * day, packs each cabinet, แจ้งเปิดบิล, records it leaving and arriving - and each of those ticks the cabinets' Checklist
 * (DeliveryChecklist), which a hand may no longer tick.
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

    private Vehicle $pickup;

    private Department $ship;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 08:00');

        $dept = Department::create(['name' => 'ไฟฟ้า', 'code' => 'EL', 'is_active' => true]);
        $ship = $this->ship = Department::create(['name' => 'จัดส่งสินค้า', 'code' => 'SH', 'is_active' => true]);
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
        $this->pickup = Vehicle::create(['name' => 'กระบะขาว', 'type' => 'กระบะ', 'plate' => '2กข-555', 'driver_name' => 'อนันต์', 'is_active' => true]);
        foreach ([$this->cab1, $this->cab2] as $cab) {   // Task 14-15 of the template: the items the round's steps tick
            foreach ([[14, 'แพ็กสินค้าและจัดคิวรถ', ['ห่อตู้เสร็จสิ้น', 'แจ้งเปิดบิล']], [15, 'ส่งมอบ / ติดตั้ง Onsite', ['ดำเนินการส่งมอบ', 'ส่งมอบ เสร็จสิ้น']]] as [$seq, $name, $items]) {
                $t = CabinetTask::forceCreate(['cabinet_id' => $cab->id, 'name' => $name, 'status' => 'not_started', 'progress' => 0, 'sequence' => $seq]);
                $sub = CabinetSubtask::forceCreate(['cabinet_task_id' => $t->id, 'name' => $name, 'department_id' => $ship->id, 'assignment_status' => 'ACCEPTED', 'status' => 'not_started', 'progress' => 0]);
                foreach ($items as $i => $item) {
                    CabinetChecklist::forceCreate(['cabinet_subtask_id' => $sub->id, 'name' => $item, 'is_completed' => false, 'sequence' => $i + 1]);
                }
            }
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function data(array $over = []): array
    {
        return $over + ['delivery_date' => '2026-11-20', 'delivery_time' => '09:00', 'cabinet_ids' => [$this->cab1->id, $this->cab2->id],
            'method' => 'company', 'vehicle_types' => ['6 ล้อเครน', 'กระบะ'], 'crew_size' => 3, 'site' => 'บางนา ประตู 2', 'contact_name' => 'คุณวิทยา', 'contact_phone' => '0812345678'];
    }

    private function send(array $over = []): ProjectDelivery
    {
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data($over))->assertSessionHasNoErrors();

        return ProjectDelivery::latest('id')->firstOrFail();
    }

    /** The delivery department puts the truck (and whatever else is given) on the round. */
    private function assign(ProjectDelivery $d, ?array $vehicles = null)
    {
        return $this->actingAs($this->clerk)->post(route('deliveries.assign', $d), ['vehicles' => $vehicles ?? [['vehicle_id' => $this->truck->id, 'crew' => ['วิชัย']]]]);
    }

    private function pack(ProjectDelivery $d, array $cabinets, bool $packed = true)
    {
        return $this->actingAs($this->clerk)->post(route('deliveries.pack', $d), ['cabinet_ids' => array_map(fn ($c) => $c->id, $cabinets), 'packed' => $packed ? 1 : 0]);
    }

    /** cabinet MO => the ticked items of its Task 14-15 */
    private function ticked(): array
    {
        return collect([$this->cab1, $this->cab2])->mapWithKeys(fn ($c) => [$c->mo_no => CabinetChecklist::whereHas('subtask.cabinetTask', fn ($q) => $q->where('cabinet_id', $c->id))
            ->where('is_completed', true)->orderBy('id')->pluck('name')->all()])->all();
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

    public function test_pm_sends_a_round_with_its_day_kinds_and_people_and_the_delivery_department_has_it_to_do(): void
    {
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data(['vehicle_types' => []]))->assertSessionHasErrors(['vehicle_types' => 'เลือกประเภทรถอย่างน้อย 1 แบบ']);
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data(['vehicle_types' => ['10 ล้อ']]))
            ->assertSessionHasErrors(['vehicle_types.0' => 'ไม่มีรถประเภทนี้ · เพิ่มได้ที่ ตั้งค่า › รถขนส่ง']);
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data(['delivery_date' => '']))->assertSessionHasErrors(['delivery_date' => 'เลือกวันส่ง']);
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data())->assertSessionHas('success', 'ส่งให้แผนกจัดส่งแล้ว');

        $d = ProjectDelivery::firstOrFail();
        $this->assertSame([1, 'waiting', '20/11/2569 · 09:00 น.', ['6 ล้อเครน', 'กระบะ'], 3, 'รอจัดรถ', $this->pm->id, 0, '6 ล้อเครน + กระบะ · 3 คน'],
            [$d->round, $d->status, $d->when(), $d->vehicle_types, $d->crew_size, $d->stateLabel(), $d->sent_by, $d->vehicles()->count(), $d->request()], 'the vehicles and the people are the delivery department\'s');
        $this->assertSame('ส่งให้แผนกจัดส่ง รอบที่ 1: 20/11/2569 · 09:00 น. · 2 ตู้ (MO-1, MO-2) · 6 ล้อเครน + กระบะ · 3 คน', $this->lastLog());
        $this->assertSame(['งานจัดส่งใหม่ · จัดรถ / คน'], $this->notices($this->clerk, 'delivery_todo'));
        $this->assertSame(0, $this->member->notifications()->count(), 'another department does not hear it');

        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertOk()->assertSee('20/11/2569 · 09:00 น.')->assertSee('2 ตู้')->assertDontSee('รอแผนกจัดส่งจัดรถ / คน')->assertSee('ขอ 6 ล้อเครน + กระบะ · 3 คน');   // the state is its pill; the cabinets a count
        $this->actingAs($this->clerk)->get(route('deliveries.index'))->assertOk()->assertViewHas('tab', 'waiting')
            ->assertSee('SO-1')->assertSee('PM ขอ')->assertSee('6 ล้อเครน + กระบะ · 3 คน')->assertSee('จัดรถ / คน')->assertSee('ไม่รับงาน')->assertDontSee('เลือกวันส่ง');

        // each cabinet with its name, type, size and how many
        $this->cab1->forceFill(['cabinet_name' => 'MDB-1 ตู้เมน', 'cabinet_type' => 'MDB', 'size' => '80x200x60 ซม.', 'quantity' => 2])->save();
        $page = $this->actingAs($this->clerk)->get(route('deliveries.index'))->assertOk()->assertSee('ห่อตู้ 0/2')
            ->assertSee('MDB-1 ตู้เมน, B');   // the card: the cabinets' names only; the rest of it in the panel
        // ไม่รับงาน names the cabinets (as the card); จัดรถ / คน lists them with type, size and how many, sizes together
        $this->assertSame(1, substr_count($page->getContent(), 'x-text="r.cabinets.map((c) => c.model || c.name).join(\', \')"'));
        $page->assertSee('aria-label="ตู้ในรอบนี้"', false)->assertSee('x-for="g in cabSizes(r.cabinets)"', false)->assertSee("'รถที่เลือกจุ ~' + asgRoom", false);
        $row =collect($page->viewData('rows'))->firstWhere('id', $d->id);
        $this->assertSame(['MO-1', 'MDB-1 ตู้เมน', 'MDB', '80x200x60 ซม.', 2, true], [$row['cabinets'][0]['name'], $row['cabinets'][0]['model'], $row['cabinets'][0]['type'], $row['cabinets'][0]['size'], $row['cabinets'][0]['qty'], $row['packable']]);

        // the panel ticks without reloading: JSON back with what is packed now
        $this->travelTo(Carbon::parse('2026-11-18 14:20'));
        $this->actingAs($this->clerk)->postJson(route('deliveries.pack', $d), ['cabinet_ids' => [$this->cab1->id], 'packed' => 1])->assertOk()
            ->assertJson(['count' => 1, 'total' => 2, 'packed' => [$this->cab1->id => '18/11 14:20']]);
        $this->assertTrue($d->fresh()->isPacked($this->cab1->id));
        $this->actingAs($this->member)->postJson(route('deliveries.pack', $d), ['cabinet_ids' => [$this->cab2->id], 'packed' => 1])->assertStatus(403);
    }

    public function test_the_cabinets_panel_carries_on_to_bill_leave_and_step_back(): void
    {
        $d = $this->send();
        $this->assign($d)->assertSessionHasNoErrors();
        $page = fn () => $this->actingAs($this->clerk)->get(route('deliveries.index', ['tab' => 'scheduled']));
        $page()->assertSee('ห่อตู้ 0/2')->assertDontSee('ห่อให้ครบก่อน');

        // not all packed: refused with its reason
        $this->actingAs($this->clerk)->postJson(route('deliveries.bill', $d))->assertStatus(422)->assertJsonValidationErrors(['delivery' => 'ห่อตู้ให้ครบก่อน (ห่อแล้ว 0/2)']);
        $this->actingAs($this->clerk)->postJson(route('deliveries.pack', $d), ['cabinet_ids' => [$this->cab1->id, $this->cab2->id], 'packed' => 1])->assertOk();
        $page()->assertSee('>แจ้งเปิดบิล<', false);   // the card's one button names what is next in the panel

        // แจ้งเปิดบิล from the panel: the round back as it is now, so the panel goes on to ออกจากโรงงาน
        $row = $this->actingAs($this->clerk)->postJson(route('deliveries.bill', $d))->assertOk()->assertJson(['message' => 'แจ้งเปิดบิลแล้ว'])->json('row');
        $this->assertSame([$d->id, false, 'scheduled', true], [$row['id'], $row['packable'], $row['status'], $row['billed'] !== '']);
        $page()->assertSee('รายการตู้ (2)')->assertSee('ออกจากโรงงาน');

        // ย้อนขั้น: back to before the bill
        $row = $this->actingAs($this->clerk)->postJson(route('deliveries.back', $d))->assertOk()->assertJson(['message' => 'ย้อนสถานะแล้ว'])->json('row');
        $this->assertSame([true, ''], [$row['packable'], $row['billed']]);
        $this->assertNull($d->fresh()->billed_at);

        $this->actingAs($this->clerk)->postJson(route('deliveries.bill', $d))->assertOk();
        $this->actingAs($this->clerk)->postJson(route('deliveries.depart', $d))->assertOk()->assertJson(['message' => 'บันทึกออกจากโรงงานแล้ว']);
        $this->assertSame('departed', $d->fresh()->status);
        $this->actingAs($this->member)->postJson(route('deliveries.back', $d))->assertForbidden();
    }

    public function test_pm_asks_for_several_vehicles_of_one_kind_and_hears_when_fewer_go(): void
    {
        // a kind once per vehicle; sent in any order, kept each kind together
        $d = $this->send(['vehicle_types' => ['6 ล้อเครน', 'กระบะ', '6 ล้อเครน']]);
        $this->assertSame(['6 ล้อเครน', '6 ล้อเครน', 'กระบะ'], $d->vehicle_types);
        $this->assertSame(['6 ล้อเครน' => 2, 'กระบะ' => 1], $d->typeCounts());
        $this->assertSame('6 ล้อเครน 2 คัน + กระบะ · 3 คน', $d->request());
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data(['cabinet_ids' => [], 'send' => '0', 'vehicle_types' => array_fill(0, 21, 'กระบะ')]))
            ->assertSessionHasErrors(['vehicle_types' => 'รถรวมไม่เกิน 20 คัน']);
        $this->actingAs($this->pm)->get(route('projects.deliveries.form', [$this->project, 'delivery' => $d->id]))->assertOk()
            ->assertViewHas('f', fn ($f) => $f['vehicle_types'] === ['6 ล้อเครน', '6 ล้อเครน', 'กระบะ']);

        // one 6 ล้อเครน of the two: allowed, PM hears it, the delivery department sees it
        $this->assign($d, [['vehicle_id' => $this->truck->id, 'crew' => ['วิชัย']], ['vehicle_id' => $this->pickup->id]])->assertSessionHasNoErrors();
        $d->refresh();
        $this->assertSame([true, 'รถ 2/3 คัน'], [$d->shortOfVehicles(), $d->vehiclesShortText()]);
        $this->assertStringContainsString('รถ 2/3 คัน (น้อยกว่าที่ขอ 6 ล้อเครน 2 คัน + กระบะ)', $this->lastLog());
        $this->assertStringContainsString('รถ 2/3 คัน (น้อยกว่าที่ขอ', $this->pm->notifications()->first()->data['body']);
        $this->actingAs($this->clerk)->get(route('deliveries.index', ['round' => $d->id]))->assertSee('PM ขอ <span class="font-semibold text-slate-700">6 ล้อเครน 2 คัน + กระบะ · 3 คน</span>', false)
            ->assertSee('รถ 2/3 คัน · น้อยกว่าที่ขอ');
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertSee('รถ 2/3 คัน');

        // the third one put on: no longer short
        $second = Vehicle::create(['name' => 'เครน 2', 'type' => '6 ล้อเครน', 'plate' => '4กข-999', 'is_active' => true]);
        $this->assign($d, [['vehicle_id' => $this->truck->id], ['vehicle_id' => $second->id], ['vehicle_id' => $this->pickup->id]])->assertSessionHasNoErrors();
        $this->assertFalse($d->fresh()->shortOfVehicles());

        // the new-project form keeps the counts on its draft
        $this->actingAs($this->pm)->post(route('projects.store'), ['project_no' => 'PJ-CARS', 'project_name' => 'X', 'customer_name' => 'C', 'priority' => 'normal',
            'delivery_date' => '2026-12-01', 'delivery_method' => 'company', 'delivery_vehicle_types' => ['กระบะ', 'กระบะ']])->assertSessionHasNoErrors();
        $this->assertSame(['กระบะ', 'กระบะ'], Project::where('project_no', 'PJ-CARS')->firstOrFail()->deliveries()->firstOrFail()->vehicle_types);
    }

    public function test_the_vehicles_panel_saves_without_reloading(): void
    {
        $d = $this->send();
        // the page carries what the panel redraws from: the tabs, the list, the busy vehicles and the names typed before
        $this->actingAs($this->clerk)->get(route('deliveries.index', ['tab' => 'waiting']))->assertOk()
            ->assertSee('id="dl-tabs"', false)->assertSee('id="dl-rows"', false)->assertSee('id="dl-live"', false)->assertSee('บันทึกจัดรถ');

        $this->actingAs($this->clerk)->postJson(route('deliveries.assign', $d), ['vehicles' => [['vehicle_id' => $this->truck->id], ['vehicle_id' => $this->truck->id]]])
            ->assertStatus(422)->assertJsonValidationErrors(['vehicles.1.vehicle_id' => '6 ล้อเครน · 3กข-1234 ใส่ซ้ำ']);
        $this->actingAs($this->clerk)->postJson(route('deliveries.assign', $d), ['vehicles' => [['vehicle_id' => $this->truck->id, 'crew' => ['วิชัย']]]])
            ->assertOk()->assertJson(['message' => 'จัดรถแล้ว']);
        $this->assertSame('scheduled', $d->fresh()->status);
        $this->actingAs($this->member)->postJson(route('deliveries.assign', $d), ['vehicles' => [['vehicle_id' => $this->truck->id]]])->assertForbidden();

        // ไม่รับงาน, the same way: refused with its reason while a vehicle is on it, then on another round
        $this->actingAs($this->clerk)->postJson(route('deliveries.reject', $d), ['reason' => 'รถไม่ว่างวันนั้น'])->assertStatus(422)->assertJsonValidationErrors('delivery');
        $cab3 = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-3', 'cabinet_name' => 'C', 'quantity' => 1, 'status' => 'not_started', 'progress' => 40]);
        $other = $this->send(['cabinet_ids' => [$cab3->id], 'delivery_date' => '2026-11-25']);
        $this->actingAs($this->clerk)->postJson(route('deliveries.reject', $other), [])->assertStatus(422)->assertJsonValidationErrors(['reason' => 'เลือกเหตุผลที่ไม่รับงาน']);
        $this->actingAs($this->clerk)->postJson(route('deliveries.reject', $other), ['reason' => 'รถไม่ว่างวันนั้น', 'note' => 'ว่าง 26/11'])
            ->assertOk()->assertJson(['message' => 'ส่งกลับให้ PM แล้ว']);
        $this->assertSame(['rejected', 'รถไม่ว่างวันนั้น'], [$other->fresh()->status, $other->fresh()->rejected_reason]);
    }

    public function test_the_delivery_department_puts_several_vehicles_and_people_on_it_and_pm_hears_what_differs(): void
    {
        $d = $this->send();
        $this->actingAs($this->member)->post(route('deliveries.assign', $d), ['vehicles' => [['vehicle_id' => $this->truck->id]]])->assertForbidden();
        $this->assign($d, [['vehicle_id' => '']])->assertSessionHasErrors(['vehicles' => 'เลือกรถอย่างน้อย 1 คัน']);
        $this->assign($d, [['vehicle_id' => $this->truck->id], ['vehicle_id' => $this->truck->id]])->assertSessionHasErrors(['vehicles.1.vehicle_id' => '6 ล้อเครน · 3กข-1234 ใส่ซ้ำ']);

        // each vehicle its driver (its usual one unless typed) and the others on it; blank / repeated names dropped
        $this->assign($d, [['vehicle_id' => $this->truck->id, 'crew' => ['วิชัย', '', 'วิชัย', 'สมศักดิ์']], ['vehicle_id' => $this->pickup->id, 'driver_name' => 'ธนา']])
            ->assertSessionHas('success', 'จัดรถแล้ว');
        $d->refresh();
        $this->assertSame(['scheduled', 'จัดรถแล้ว', $this->clerk->id, '20/11/2569 · 09:00 น.'], [$d->status, $d->stateLabel(), $d->scheduled_by, $d->when()], 'PM\'s day stays');
        $this->assertSame(['6 ล้อเครน · 3กข-1234, กระบะขาว · 2กข-555', ['สมศักดิ์', 'วิชัย', 'ธนา'], 'สมศักดิ์, วิชัย, ธนา (3/3 คน)', [], false],
            [$d->transport(), $d->people(), $d->peopleText(), $d->otherTypes(), $d->shortOfPeople()]);
        $this->assertSame('การจัดส่งรอบที่ 1: แผนกจัดส่งจัดรถแล้ว: 6 ล้อเครน · 3กข-1234, กระบะขาว · 2กข-555 · คน สมศักดิ์, วิชัย, ธนา (3/3 คน)', $this->lastLog());
        $this->assertSame(['แผนกจัดส่งจัดรถแล้ว'], $this->notices($this->pm, 'delivery'));
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertSee('สมศักดิ์, วิชัย, ธนา (3/3 คน)')->assertSee('PM ขอ 6 ล้อเครน + กระบะ · 3 คน');

        // a kind PM did not ask for and fewer people: allowed, PM hears both
        $this->assign($d, [['vehicle_id' => $this->van->id]]);
        $d->refresh();
        $this->assertSame([['4 ล้อ'], true, 'ประยุทธ (1/3 คน)'], [$d->otherTypes(), $d->shortOfPeople(), $d->peopleText()]);
        $this->assertSame(['แผนกจัดส่งเปลี่ยนรถ / คน'], $this->notices($this->pm->fresh(), 'delivery'), 'the round\'s notice, updated');
        $body = $this->pm->notifications()->first()->data['body'];
        $this->assertStringContainsString('PM ขอ 6 ล้อเครน + กระบะ · ใช้ 4 ล้อ ด้วย', $body);
        $this->assertStringContainsString('คน 1/3 (น้อยกว่าที่ขอ)', $body);
        $this->actingAs($this->clerk)->get(route('deliveries.index', ['round' => $d->id]))->assertViewHas('tab', 'scheduled')->assertSee('ไม่ได้ขอ');
        $this->actingAs($this->clerk)->post(route('deliveries.reject', $d), ['reason' => 'รถไม่ว่างวันนั้น'])
            ->assertSessionHasErrors(['delivery' => 'ไม่รับงานได้เฉพาะรอบที่ยังไม่ได้จัดรถ — รอบนี้จัดรถแล้ว · ติดต่อ PM เพื่อเลื่อนหรือยกเลิก']);
    }

    public function test_the_vehicles_a_day_already_has_are_shown_as_busy(): void
    {
        $this->assign($this->send(['cabinet_ids' => [$this->cab1->id]]));
        $this->send(['cabinet_ids' => [$this->cab2->id]]);
        $this->actingAs($this->clerk)->get(route('deliveries.index'))->assertViewHas('busy', fn ($b) => $b[$this->truck->id]['2026-11-20'][0]['label'] === 'SO-1 รอบ 1');
    }

    public function test_sent_back_with_a_reason_pm_picks_another_day_and_sends_it_again(): void
    {
        $d = $this->send();
        $this->actingAs($this->clerk)->post(route('deliveries.reject', $d), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->clerk)->post(route('deliveries.reject', $d), ['reason' => 'อื่น ๆ'])->assertSessionHasErrors(['reason' => 'พิมพ์รายละเอียดของเหตุผล']);
        $this->actingAs($this->clerk)->post(route('deliveries.reject', $d), ['reason' => 'รถไม่ว่างวันนั้น', 'note' => 'ว่างอีกที 24/11'])->assertSessionHas('success');

        $d->refresh();
        $this->assertSame(['rejected', 'รถไม่ว่างวันนั้น', $this->clerk->id], [$d->status, $d->rejected_reason, $d->rejected_by]);
        $this->assertSame('การจัดส่งรอบที่ 1: แผนกจัดส่งไม่รับงาน — เหตุผล: รถไม่ว่างวันนั้น · ว่างอีกที 24/11', $this->lastLog());
        $this->assertSame(['แผนกจัดส่งไม่รับงาน · เลือกวันใหม่'], $this->notices($this->pm, 'delivery_rejected'));
        $this->assign($d)->assertSessionHasErrors(['delivery' => 'รอบนี้ส่งกลับให้ PM แล้ว · รอ PM ส่งมาอีกครั้ง']);
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertSee('แผนกจัดส่งไม่รับงาน · รถไม่ว่างวันนั้น · ว่างอีกที 24/11')->assertSee('เลือกวันใหม่แล้วส่ง');

        $this->actingAs($this->pm)->put(route('project-deliveries.update', $d), $this->data(['delivery_date' => '2026-11-24']))->assertSessionHas('success', 'ส่งให้แผนกจัดส่งอีกครั้งแล้ว');
        $d->refresh();
        $this->assertSame(['waiting', null, '24/11/2569 · 09:00 น.'], [$d->status, $d->rejected_reason, $d->when()]);
        $this->assertSame('การจัดส่งรอบที่ 1: ส่งให้จัดส่งอีกครั้ง · 24/11/2569 · 09:00 น. · 2 ตู้ (MO-1, MO-2) · 6 ล้อเครน + กระบะ · 3 คน', $this->lastLog());
        $this->assertSame(['งานจัดส่งใหม่ · จัดรถ / คน', 'PM ส่งรอบส่งให้อีกครั้ง · จัดรถ / คน'], $this->notices($this->clerk->fresh(), 'delivery_todo'));
    }

    public function test_only_pm_moves_the_day_logged_like_fat_and_the_vehicles_stay(): void
    {
        $d = $this->send();
        $this->assign($d);

        $this->actingAs($this->pm)->put(route('project-deliveries.update', $d), $this->data(['delivery_date' => '2026-11-23']))->assertSessionHasNoErrors();
        $d->refresh();
        $this->assertSame(['scheduled', '23/11/2569 · 09:00 น.', 1], [$d->status, $d->when(), $d->vehicles()->count()], 'the vehicles stay on it');
        $this->assertSame('การจัดส่งรอบที่ 1: เลื่อนวันส่งจาก 20/11/2569 · 09:00 น. เป็น 23/11/2569 · 09:00 น.', $this->lastLog());
        $this->assertContains('PM เลื่อนวันส่งจาก 20/11/2569 · 09:00 น. · ตรวจว่ารถ / คนยังว่าง', $this->notices($this->clerk, 'delivery_todo'));

        // another way of sending takes the vehicles off: to be put on again
        $this->actingAs($this->pm)->put(route('project-deliveries.update', $d), $this->data(['delivery_date' => '2026-11-23', 'method' => 'external', 'carrier' => 'ไทยขนส่ง']))->assertSessionHasNoErrors();
        $d->refresh();
        $this->assertSame(['waiting', 0, null], [$d->status, $d->vehicles()->count(), $d->vehicle_types]);
        $this->assertContains('PM เปลี่ยนวิธีส่ง · จัดรถ / คนใหม่', $this->notices($this->clerk->fresh(), 'delivery_todo'));
        $this->assign($d, [['label' => '70-1234', 'driver_name' => 'คนขับไทยขนส่ง']])->assertSessionHasNoErrors();
        $this->assertSame('ขนส่งภายนอก · ไทยขนส่ง · 70-1234', $d->fresh()->transport());
    }

    public function test_a_moved_delivery_day_is_kept_per_round_with_its_reason(): void
    {
        // a draft's day is not a set day yet: moving it keeps nothing
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data(['send' => '0']))->assertSessionHasNoErrors();
        $draft = $this->project->deliveries()->first();
        $this->actingAs($this->pm)->put(route('project-deliveries.update', $draft), $this->data(['delivery_date' => '2026-11-21', 'send' => '0']))->assertSessionHasNoErrors();
        $this->assertSame(0, EventDateChange::count());
        $draft->delete();

        $d = $this->send();
        $this->assign($d);
        $this->actingAs($this->pm)->put(route('project-deliveries.update', $d), $this->data(['delivery_date' => '2026-11-23', 'move_note' => 'ลูกค้ายังไม่พร้อมรับ']))->assertSessionHasNoErrors();
        $this->actingAs($this->pm)->put(route('project-deliveries.update', $d), $this->data(['delivery_date' => '2026-11-23', 'delivery_time' => '13:00']))->assertSessionHasNoErrors();

        $this->assertSame('การจัดส่งรอบที่ 1: เลื่อนวันส่งจาก 20/11/2569 · 09:00 น. เป็น 23/11/2569 · 09:00 น. — ลูกค้ายังไม่พร้อมรับ',
            ActivityLog::where('action', 'delivery')->orderBy('id')->get()->pluck('description')->first(fn ($l) => str_contains($l, 'ลูกค้ายังไม่พร้อมรับ')));
        $this->assertSame([['delivery', $d->id, 'postpone', '20/11/2569 · 09:00 น.', '23/11/2569 · 09:00 น.'], ['delivery', $d->id, 'postpone', '23/11/2569 · 09:00 น.', '23/11/2569 · 13:00 น.']],
            EventDateChange::orderBy('id')->get()->map(fn ($c) => [$c->event, $c->event_id, $c->kind, $c->fromText(), $c->toText()])->all());
        $this->assertSame(2, \App\Http\Controllers\ProjectDeliveryController::formData($this->project, $d->fresh())['moves']);
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertOk()
            ->assertSee('เลื่อน 2 ครั้ง')->assertSeeInOrder(['ประวัติการเลื่อนวัน (2)', 'ครั้งที่ 2', 'ครั้งที่ 1', '+3 วัน', 'ลูกค้ายังไม่พร้อมรับ']);
    }

    public function test_packing_billing_leaving_and_arriving_tick_the_checklist_and_step_back_unticks(): void
    {
        $d = $this->send();
        $this->actingAs($this->clerk)->post(route('deliveries.bill', $d))->assertSessionHasErrors(['delivery' => 'ห่อตู้ให้ครบก่อน (ห่อแล้ว 0/2)']);

        // packing waits for the cabinet, not for the vehicles
        $this->pack($d, [$this->cab1])->assertSessionHas('success', 'บันทึกห่อตู้แล้ว');
        $d->refresh();
        $this->assertSame([true, false, 1, ['n' => 0, 'text' => 'ห่อ 1/2']], [$d->isPacked($this->cab1->id), $d->isPacked($this->cab2->id), $d->packedCount(), $d->step()]);
        $this->assertSame(['MO-1' => ['ห่อตู้เสร็จสิ้น'], 'MO-2' => []], $this->ticked());
        $this->assertSame('การจัดส่งรอบที่ 1: ห่อตู้เสร็จสิ้น MO-1 (ห่อแล้ว 1/2)', $this->lastLog());
        $this->assertSame(['ห่อตู้แล้ว 1/2'], $this->notices($this->pm, 'delivery'));

        // a hand may not tick the round's items any more (the other items stay the department's)
        $item = CabinetChecklist::where('name', 'แจ้งเปิดบิล')->whereHas('subtask.cabinetTask', fn ($q) => $q->where('cabinet_id', $this->cab1->id))->firstOrFail();
        $this->actingAs($this->clerk)->patchJson(route('checklists.toggle', $item), ['is_completed' => true])->assertStatus(423)
            ->assertJson(['message' => '"แจ้งเปิดบิล" ติ๊กจากหน้า "งานจัดส่ง" (รอบที่ 1) · Checklist นี้ดูได้อย่างเดียว']);

        $this->pack($d, [$this->cab2]);
        $this->assertSame(['ห่อตู้ครบแล้ว · รอแจ้งเปิดบิล'], $this->notices($this->pm->fresh(), 'delivery'), 'one notice for the round, replaced');
        $this->actingAs($this->clerk)->post(route('deliveries.depart', $d))->assertSessionHasErrors(['delivery' => 'รอบนี้ยังไม่ได้จัดรถ']);

        // แจ้งเปิดบิล: the billing department hears it (Settings > รถขนส่ง)
        $acc = Department::create(['name' => 'บัญชี', 'code' => 'AC', 'is_active' => true]);
        $clerkAcc = User::forceCreate(['name' => 'บัญชี', 'email' => 'dl-ac', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $acc->id, 'is_active' => true]);
        $this->actingAs($this->clerk)->put(route('settings.vehicles.billing'), ['department_id' => $acc->id])->assertRedirect()->assertSessionHas('success');
        $this->actingAs($this->clerk)->post(route('deliveries.bill', $d))->assertSessionHas('success', 'แจ้งเปิดบิลแล้ว');
        $this->assertSame(['แจ้งเปิดบิล'], $this->notices($clerkAcc, 'delivery_todo'));
        $this->assertSame(route('notifications.index'), $clerkAcc->notifications()->first()->data['url'], 'billing has no work in the project: the notice list');
        $this->assertSame(['MO-1' => ['ห่อตู้เสร็จสิ้น', 'แจ้งเปิดบิล'], 'MO-2' => ['ห่อตู้เสร็จสิ้น', 'แจ้งเปิดบิล']], $this->ticked());
        $this->pack($d, [$this->cab1], false)->assertSessionHasErrors(['delivery' => 'แจ้งเปิดบิลแล้ว · ย้อนขั้นก่อนถึงจะเอาการห่อออกได้']);
        $this->actingAs($this->pm)->put(route('project-deliveries.update', $d), $this->data(['cabinet_ids' => [$this->cab1->id]]))
            ->assertSessionHasErrors(['cabinet_ids' => 'รอบนี้แจ้งเปิดบิลแล้ว เปลี่ยนตู้ไม่ได้ · ให้แผนกจัดส่งย้อนขั้นก่อน']);

        $this->assign($d);
        $this->actingAs($this->clerk)->post(route('deliveries.depart', $d))->assertSessionHas('success');
        $this->assertSame(['MO-1' => ['ห่อตู้เสร็จสิ้น', 'แจ้งเปิดบิล', 'ดำเนินการส่งมอบ'], 'MO-2' => ['ห่อตู้เสร็จสิ้น', 'แจ้งเปิดบิล', 'ดำเนินการส่งมอบ']], $this->ticked());
        $this->actingAs($this->clerk)->post(route('deliveries.deliver', $d), ['delivered_date' => '2026-11-20', 'delivered_time' => '10:40', 'receiver' => 'คุณสมชาย',
            'doc_no' => 'DO-0412', 'issue' => 'รอยขีดข่วน', 'cabinet_ids' => [$this->cab1->id]])->assertSessionHasNoErrors();
        $d->refresh();
        $this->assertSame(['delivered', [$this->cab1->id], ['n' => 4, 'text' => 'ส่งถึงแล้ว']], [$d->status, $d->cabinet_ids, $d->step()]);
        $this->assertSame(['MO-1' => ['ห่อตู้เสร็จสิ้น', 'แจ้งเปิดบิล', 'ดำเนินการส่งมอบ', 'ส่งมอบ เสร็จสิ้น'], 'MO-2' => ['ห่อตู้เสร็จสิ้น', 'แจ้งเปิดบิล']], $this->ticked(),
            'what did not arrive is back at the factory');
        $this->assertSame(['ส่งถึงแล้ว · มีปัญหาที่หน้างาน'], $this->notices($this->pm, 'delivery_rejected'));
        $this->assertSame(1, (int) CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $this->cab1->id)->where('sequence', 15))->value('progress') / 100, 'progress follows the ticks');

        // ย้อนขั้น one step at a time, unticking with it
        $this->actingAs($this->clerk)->post(route('deliveries.back', $d))->assertSessionHasNoErrors();
        $this->assertSame(['departed', ['ห่อตู้เสร็จสิ้น', 'แจ้งเปิดบิล', 'ดำเนินการส่งมอบ']], [$d->fresh()->status, $this->ticked()['MO-1']]);
        $this->actingAs($this->clerk)->post(route('deliveries.back', $d));
        $this->assertSame(['scheduled', ['ห่อตู้เสร็จสิ้น', 'แจ้งเปิดบิล']], [$d->fresh()->status, $this->ticked()['MO-1']]);
        $this->actingAs($this->clerk)->post(route('deliveries.back', $d));
        $this->assertSame([null, ['ห่อตู้เสร็จสิ้น'], 'การจัดส่งรอบที่ 1: ย้อนขั้น แจ้งเปิดบิลแล้ว → ห่อครบ · รอแจ้งเปิดบิล'], [$d->fresh()->billed_at, $this->ticked()['MO-1'], $this->lastLog()]);
        $this->actingAs($this->clerk)->post(route('deliveries.back', $d))->assertSessionHasErrors(['delivery' => 'ไม่มีขั้นให้ย้อน']);

        // a cancelled round takes its ticks with it, and the items are a hand's again
        $this->actingAs($this->pm)->delete(route('project-deliveries.destroy', $d))->assertSessionHasNoErrors();
        $this->assertSame(['MO-1' => [], 'MO-2' => ['ห่อตู้เสร็จสิ้น', 'แจ้งเปิดบิล']], $this->ticked());
    }

    public function test_arriving_takes_files_kept_as_a_project_comment_for_the_round(): void
    {
        $d = $this->send();
        $this->pack($d, [$this->cab1, $this->cab2]);
        $this->assign($d);
        $this->actingAs($this->clerk)->post(route('deliveries.bill', $d))->assertSessionHasNoErrors();
        $this->actingAs($this->clerk)->post(route('deliveries.depart', $d))->assertSessionHasNoErrors();
        $arrive = fn (array $files) => $this->actingAs($this->clerk)->post(route('deliveries.deliver', $d), ['delivered_date' => '2026-11-20', 'receiver' => 'คุณสมชาย',
            'cabinet_ids' => [$this->cab1->id, $this->cab2->id], 'files' => $files]);
        $uploads = public_path('uploads/comment-attachments');
        $before = is_dir($uploads) ? scandir($uploads) : [];

        try {
            $arrive([\Illuminate\Http\UploadedFile::fake()->create('x.exe', 5)])->assertSessionHasErrors('files.0');
            $arrive(array_fill(0, 6, \Illuminate\Http\UploadedFile::fake()->image('a.jpg')))->assertSessionHasErrors(['files' => 'แนบได้ครั้งละไม่เกิน 5 ไฟล์']);
            $this->assertSame(['departed', 0], [$d->fresh()->status, \App\Models\CommentAttachment::count()], 'a refused save keeps nothing');

            $arrive([\Illuminate\Http\UploadedFile::fake()->create('DO-0412 เซ็นรับ.pdf', 30, 'application/pdf'), \Illuminate\Http\UploadedFile::fake()->image('หน้างาน.jpg')])
                ->assertSessionHasNoErrors()->assertSessionHas('success', 'บันทึกส่งถึงแล้ว');
            $note = ActivityLog::where('action', 'note')->sole();
            $this->assertSame([Project::class, $this->project->id, $this->clerk->id, ['delivery_id' => $d->id], 'ไฟล์การจัดส่งรอบที่ 1 · ส่งถึงแล้ว 20/11/2569 · ผู้รับ คุณสมชาย'],
                [$note->loggable_type, $note->loggable_id, $note->user_id, $note->changes, $note->description], 'a comment of the project, so its Comment tab has it');
            $this->assertStringEndsWith('· แนบไฟล์ 2 ไฟล์', $this->lastLog());
            $said = fn () => $this->pm->fresh()->notifications()->get()->map(fn ($n) => $n->data['title'].' → '.$n->data['url'])->all();
            $this->assertContains('ส่งถึงแล้ว · แนบไฟล์ 2 ไฟล์ → '.route('projects.show', $this->project).'#delivery', $said(), 'PM is told there are files; the notice opens the panel');
            $this->pm->unreadNotifications->markAsRead();

            // แก้การส่งถึง adds; what was there stays
            $arrive([\Illuminate\Http\UploadedFile::fake()->image('เพิ่ม.png')])->assertSessionHasNoErrors();
            $this->assertSame(['DO-0412 เซ็นรับ.pdf', 'หน้างาน.jpg', 'เพิ่ม.png'], ProjectDelivery::filesOf([$d->id])->get($d->id)->pluck('original_name')->all());
            $this->assertTrue(ProjectDelivery::filesOf([$d->id + 1])->isEmpty());
            $this->assertContains('ส่งถึงแล้ว · แนบไฟล์เพิ่ม 1 ไฟล์ → '.route('projects.show', $this->project).'#delivery', $said(), 'files added later are told too');

            // the project page: the photos as pictures that open over the panel, the PDF by name
            $page = $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertOk()->assertSee('aria-label="รูปตอนส่งถึง 2 รูป"', false);
            $this->assertSame(2, substr_count($page->getContent(), 'aria-label="ดูรูป '));
            $this->assertMatchesRegularExpression('/<a href="[^"]+\.pdf" target="_blank"[^>]*title="DO-0412 เซ็นรับ.pdf"/u', $page->getContent());

            foreach ([route('deliveries.index', ['tab' => 'delivered']), route('projects.show', $this->project), route('projects.show', ['project' => $this->project, 'tab' => 'comment'])] as $url) {
                $this->actingAs($this->pm)->get($url)->assertOk()->assertSee('DO-0412 เซ็นรับ.pdf')->assertSee('เพิ่ม.png');
            }
        } finally {
            foreach (array_diff(is_dir($uploads) ? scandir($uploads) : [], $before) as $f) {
                @unlink($uploads.'/'.$f);   // the uploader writes to public/: leave the folder as it was
            }
        }
    }

    public function test_files_the_server_cannot_keep_save_nothing(): void
    {
        $d = $this->send();
        $this->pack($d, [$this->cab1, $this->cab2]);
        $this->assign($d);
        $this->actingAs($this->clerk)->post(route('deliveries.bill', $d));
        $this->actingAs($this->clerk)->post(route('deliveries.depart', $d))->assertSessionHasNoErrors();
        $this->mock(\App\Services\CommentAttachmentUploader::class, fn ($m) => $m->shouldReceive('ready')->andReturn(false)->shouldNotReceive('store'));
        $logs = ActivityLog::count();

        $this->actingAs($this->clerk)->post(route('deliveries.deliver', $d), ['delivered_date' => '2026-11-20', 'receiver' => 'คุณสมชาย', 'cabinet_ids' => [$this->cab1->id],
            'files' => [\Illuminate\Http\UploadedFile::fake()->image('a.jpg')]])->assertSessionHasErrors('files');
        $this->assertSame(['departed', $logs], [$d->fresh()->status, ActivityLog::count()], 'no history line, no empty comment: the log cannot roll back on MyISAM');
    }

    public function test_a_round_is_late_after_its_day(): void
    {
        $d = $this->send(['delivery_date' => '2026-11-16', 'delivery_time' => '']);
        $this->assertSame(['16/11/2569', null], [$d->when(), $d->delivery_end_date]);
        $this->assertFalse($d->isOverdue(Carbon::parse('2026-11-16')));
        $this->assertTrue($d->isOverdue(Carbon::parse('2026-11-17')), 'past its day and not there yet');
    }

    public function test_a_round_may_go_over_several_days_of_pm(): void
    {
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data(['delivery_end_date' => '2026-11-19']))
            ->assertSessionHasErrors(['delivery_end_date' => 'วันสุดท้ายที่ส่งต้องไม่ก่อนวันแรก']);
        $d = $this->send(['cabinet_ids' => [$this->cab1->id], 'delivery_end_date' => '2026-11-22']);
        $this->assertSame(['20–22/11/2569 · 09:00 น.', ['2026-11-20', '2026-11-21', '2026-11-22']], [$d->when(), $d->days()]);
        $this->assertFalse($d->isOverdue(Carbon::parse('2026-11-22')));
        $this->assertTrue($d->isOverdue(Carbon::parse('2026-11-23')));
        $this->assertSame('ส่งให้แผนกจัดส่ง รอบที่ 1: 20–22/11/2569 · 09:00 น. · 1 ตู้ (MO-1) · 6 ล้อเครน + กระบะ · 3 คน', $this->lastLog());
        $task = collect(app(PlanningBoard::class)->tasks($this->pm))->firstWhere('id', 'p'.$this->project->id);
        $this->assertSame(['2026-11-20', '2026-11-22'], [$task['deliveries'][0]['date'], $task['deliveries'][0]['end']]);

        // the truck on it is busy every one of its days; a one-day round the same week is not on them
        $this->assign($d);
        $other = $this->send(['cabinet_ids' => [$this->cab2->id], 'delivery_date' => '2026-11-21']);
        $this->actingAs($this->clerk)->get(route('deliveries.index'))->assertViewHas('busy', fn ($b) => array_keys($b[$this->truck->id]) === ['2026-11-20', '2026-11-21', '2026-11-22']);

        // moved: from - to in the log; the same days again (an end equal to the start = one day) is no move
        $this->actingAs($this->pm)->put(route('project-deliveries.update', $d), $this->data(['cabinet_ids' => [$this->cab1->id], 'delivery_date' => '2026-11-24', 'delivery_end_date' => '2026-11-25']))->assertSessionHasNoErrors();
        $this->assertSame('การจัดส่งรอบที่ 1: เลื่อนวันส่งจาก 20–22/11/2569 · 09:00 น. เป็น 24–25/11/2569 · 09:00 น.', $this->lastLog());
        $this->actingAs($this->pm)->put(route('project-deliveries.update', $other), $this->data(['cabinet_ids' => [$this->cab2->id], 'delivery_date' => '2026-11-21', 'delivery_end_date' => '2026-11-21']))->assertSessionHasNoErrors();
        $this->assertNull($other->fresh()->delivery_end_date);
        $this->assertSame('การจัดส่งรอบที่ 2: แก้รายละเอียด', $this->lastLog());
        $this->actingAs($this->pm)->get(route('projects.deliveries.form', [$this->project, 'delivery' => $d->id]))
            ->assertViewHas('f', fn ($f) => $f['delivery_date'] === '2026-11-24' && $f['delivery_end_date'] === '2026-11-25' && $f['was'] === '2026-11-24|2026-11-25');
    }

    public function test_who_may_do_what_and_a_closed_project_refuses(): void
    {
        $this->actingAs($this->member)->post(route('projects.deliveries.store', $this->project), $this->data())->assertForbidden();
        $this->actingAs($this->member)->get(route('projects.deliveries.form', $this->project))->assertForbidden();
        $this->actingAs($this->clerk)->post(route('projects.deliveries.store', $this->project), $this->data())->assertForbidden();
        $this->actingAs($this->member)->get(route('deliveries.index'))->assertForbidden();
        $this->actingAs($this->member)->get(route('dashboard'))->assertDontSee(route('deliveries.index'));
        $this->actingAs($this->clerk)->get(route('deliveries.index'))->assertOk()->assertSee('ไม่มีรอบที่รอจัดรถ');

        $this->project->update(['status' => 'completed']);
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data());
        $this->assertSame(0, ProjectDelivery::count());
    }

    public function test_planning_carries_the_day_the_steps_and_opens_the_window_that_saves_as_json(): void
    {
        $this->actingAs($this->pm)->get(route('projects.deliveries.form', $this->project))->assertOk()->assertSee('id="project-delivery"', false)->assertSee('MO-2')
            ->assertViewHas('f', fn ($f) => $f['title'] === 'นัดส่งรอบที่ 1' && $f['verb'] === 'POST' && $f['method'] === 'company' && $f['vehicle_types'] === []);
        $this->actingAs($this->pm)->postJson(route('projects.deliveries.store', $this->project), $this->data(['cabinet_ids' => []]))->assertStatus(422)->assertJsonValidationErrors('cabinet_ids');
        $this->actingAs($this->pm)->postJson(route('projects.deliveries.store', $this->project), $this->data())->assertOk()->assertExactJson(['message' => 'ส่งให้แผนกจัดส่งแล้ว']);
        $d = ProjectDelivery::firstOrFail();
        $this->actingAs($this->pm)->get(route('projects.deliveries.form', $this->project))
            ->assertViewHas('cabinets', fn ($c) => $c[0]['where'] === 'อยู่ในรอบที่ 1' && $c[1]['progress'] === 40)
            ->assertViewHas('types', ['4 ล้อ', '6 ล้อเครน', 'กระบะ']);
        $this->actingAs($this->pm)->get(route('projects.deliveries.form', [$this->project, 'delivery' => $d->id]))
            ->assertViewHas('f', fn ($f) => $f['id'] === $d->id && $f['verb'] === 'PUT' && $f['delivery_date'] === '2026-11-20' && $f['vehicle_types'] === ['6 ล้อเครน', 'กระบะ'] && $f['picked'] === '');
        $other = Project::forceCreate(['project_no' => 'SO-2', 'project_name' => 'X', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $this->actingAs($this->pm)->get(route('projects.deliveries.form', [$other, 'delivery' => $d->id]))->assertNotFound();

        $json = fn (User $u) => collect(app(PlanningBoard::class)->tasks($u))->firstWhere('id', 'p'.$this->project->id)['deliveries'] ?? [];
        $this->assertSame([['id' => $d->id, 'round' => 1, 'date' => '2026-11-20', 'end' => '2026-11-20', 'time' => '09:00', 'status' => 'waiting', 'wait' => true, 'why' => '',
            'cabs' => ['c'.$this->cab1->id, 'c'.$this->cab2->id], 'method' => 'company', 'packed' => [], 'step' => 0, 'stepText' => 'ห่อ 0/2', 'billed' => false,
            'car' => '6 ล้อเครน + กระบะ', 'carFull' => '6 ล้อเครน + กระบะ', 'driver' => '', 'crew' => 3, 'people' => '', 'dep' => null, 'at' => null, 'pending' => false]], $json($this->pm), 'no vehicle yet: the kinds PM asked for');
        $this->actingAs($this->clerk)->postJson(route('deliveries.assign', $d), ['vehicles' => [['vehicle_id' => $this->truck->id], ['vehicle_id' => $this->pickup->id]]])
            ->assertOk()->assertExactJson(['message' => 'จัดรถแล้ว']);
        $this->pack($d, [$this->cab2]);
        $this->assertSame(['scheduled', false, ['c'.$this->cab2->id], 'ห่อ 1/2', '6 ล้อเครน + กระบะขาว', 'สมศักดิ์, อนันต์ (2/3 คน)'],
            array_values(array_intersect_key($json($this->pm)[0], array_flip(['status', 'wait', 'car', 'packed', 'stepText', 'people']))));

        $this->actingAs($this->pm)->deleteJson(route('project-deliveries.destroy', $d))->assertOk()->assertExactJson(['message' => 'ยกเลิกรอบส่งแล้ว']);
        $this->assertSame(0, ProjectDelivery::count());
        $this->assertContains('PM ยกเลิกรอบส่ง', $this->notices($this->clerk, 'delivery'));
    }

    public function test_the_delivery_department_keeps_the_vehicles_and_the_reasons(): void
    {
        $this->actingAs($this->member)->get(route('settings.vehicles.index'))->assertForbidden();
        $this->actingAs($this->clerk)->get(route('settings.vehicles.index'))->assertOk()->assertSee('6 ล้อเครน')->assertSee('รถไม่ว่างวันนั้น')->assertSee('แจ้งเปิดบิล');
        $this->actingAs($this->clerk)->post(route('settings.vehicles.store'), ['name' => 'กระบะดำ', 'type' => ' กระบะ ', 'plate' => '1กข-9999', 'capacity' => 1])->assertSessionHasNoErrors();
        $spare = Vehicle::where('name', 'กระบะดำ')->firstOrFail();
        $this->assertSame(['4 ล้อ', '6 ล้อเครน', 'กระบะ'], Vehicle::types(), 'PM asks for the kinds of the vehicles in use');
        $this->actingAs($this->clerk)->delete(route('settings.vehicles.destroy', $spare));
        $this->assertNull(Vehicle::find($spare->id));

        $this->assign($this->send());
        $this->actingAs($this->admin)->delete(route('settings.vehicles.destroy', $this->truck));
        $this->assertFalse($this->truck->fresh()->is_active);
        $this->assertSame('6 ล้อเครน · 3กข-1234', ProjectDelivery::first()->transport(), 'its rounds still say which vehicle went');

        $this->actingAs($this->clerk)->put(route('settings.vehicles.reasons'), ['reasons' => " คิวรถเต็ม \nอื่น ๆ\n\nลูกค้ายังไม่พร้อมรับ\nคิวรถเต็ม"])->assertSessionHasNoErrors();
        $this->assertSame(['คิวรถเต็ม', 'ลูกค้ายังไม่พร้อมรับ'], ProjectDelivery::rejectReasons());
        $this->actingAs($this->clerk)->get(route('deliveries.index'))->assertViewHas('reasons', ['คิวรถเต็ม', 'ลูกค้ายังไม่พร้อมรับ']);
        $this->actingAs($this->clerk)->put(route('settings.vehicles.reasons'), ['reasons' => '']);
        $this->assertSame(ProjectDelivery::REJECT_REASONS, ProjectDelivery::rejectReasons());
    }

    public function test_pm_keeps_a_draft_for_every_cabinet_that_only_pm_sees_and_sends_it_when_the_cabinets_are_checked(): void
    {
        $this->actingAs($this->pm)->post(route('projects.deliveries.store', $this->project), $this->data(['send' => '0', 'cabinet_ids' => [], 'vehicle_types' => []]))
            ->assertSessionHas('success', 'บันทึกร่างการจัดส่งแล้ว');
        $d = ProjectDelivery::firstOrFail();
        $this->assertSame(['draft', [], null, 'ร่าง', 'ทุกตู้'], [$d->status, $d->cabinet_ids, $d->sent_by, $d->stateLabel(), $d->cabinetsText()]);
        $this->assertSame('ร่างการจัดส่ง รอบที่ 1: 20/11/2569 · 09:00 น. · ทุกตู้ · - · 3 คน', $this->lastLog());
        $this->assertSame(0, $this->clerk->notifications()->count(), 'a draft is PM\'s own');
        $this->actingAs($this->clerk)->get(route('deliveries.index'))->assertSee('ไม่มีรอบที่รอจัดรถ')->assertDontSee('SO-1');
        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertSee('ยังไม่ได้ส่งให้แผนกจัดส่ง')->assertSee('ตรวจแล้วส่งให้แผนกจัดส่ง');
        $planning = fn (User $u) => collect(app(PlanningBoard::class)->tasks($u))->firstWhere('id', 'p'.$this->project->id)['deliveries'] ?? [];
        $this->assertTrue($planning($this->pm)[0]['wait']);
        $this->assertSame([], $planning($this->clerk), 'Planning hides PM\'s drafts from the others');

        $this->actingAs($this->pm)->get(route('projects.deliveries.form', [$this->project, 'delivery' => $d->id]))
            ->assertViewHas('f', fn ($f) => $f['draft'] && $f['all'] && $f['cabinet_ids'] === [$this->cab1->id, $this->cab2->id] && $f['title'] === 'ร่างการจัดส่งรอบที่ 1');
        $this->actingAs($this->pm)->put(route('project-deliveries.update', $d), $this->data(['send' => '1', 'cabinet_ids' => [], 'vehicle_types' => []]))
            ->assertSessionHasErrors(['cabinet_ids', 'vehicle_types']);
        $this->actingAs($this->pm)->put(route('project-deliveries.update', $d), $this->data(['send' => '1']))->assertSessionHas('success', 'ส่งให้แผนกจัดส่งแล้ว');
        $d->refresh();
        $this->assertSame(['waiting', $this->pm->id, 2], [$d->status, $d->sent_by, count($d->cabinet_ids)]);
        $this->assertSame(['งานจัดส่งใหม่ · จัดรถ / คน'], $this->notices($this->clerk, 'delivery_todo'));
    }

    public function test_the_new_project_page_books_the_fat_day_and_a_draft_delivery(): void
    {
        $this->actingAs($this->pm)->get(route('projects.create'))->assertOk()->assertSee('วัน FAT')->assertSee('การจัดส่ง')->assertSee('วันส่ง')->assertSee('ขนส่งภายนอก')->assertSee('6 ล้อเครน')->assertSee('กระบะ');
        $base = ['project_no' => 'SO-9', 'project_name' => 'ใหม่', 'customer_name' => 'ลูกค้า', 'priority' => 'normal'];
        $this->actingAs($this->pm)->post(route('projects.store'), $base + ['delivery_site' => 'บางนา'])->assertSessionHasErrors(['delivery_date' => 'ใส่วันส่งก่อน']);
        $this->assertNull(Project::where('project_no', 'SO-9')->first(), 'nothing saved without the day');
        $this->actingAs($this->pm)->post(route('projects.store'), $base + ['fat_time' => '13:30'])->assertSessionHasErrors(['fat_date' => 'ใส่วัน FAT ก่อน']);

        $this->actingAs($this->pm)->post(route('projects.store'), $base + ['fat_date' => '2026-12-01', 'fat_end_date' => '2026-12-02', 'fat_time' => '13:30', 'fat_status' => 'scheduled', 'fat_note' => 'ลูกค้า 3 คน',
            'delivery_method' => 'company', 'delivery_date' => '2026-12-10', 'delivery_end_date' => '2026-12-11', 'delivery_time' => '08:30', 'delivery_vehicle_types' => ['6 ล้อเครน', 'กระบะ'], 'delivery_crew_size' => '4',
            'delivery_carrier' => 'ไม่ใช้', 'delivery_site' => 'บางนา ประตู 2', 'delivery_contact_name' => 'คุณวิทยา', 'delivery_contact_phone' => '0812345678', 'delivery_note' => 'ต้องใช้เครน'])->assertSessionHasNoErrors();
        $p = Project::where('project_no', 'SO-9')->firstOrFail();
        $fat = $p->fats()->firstOrFail();
        $this->assertSame([1, '2026-12-01', '2026-12-02', '13:30', null, 'scheduled', 'ลูกค้า 3 คน'],
            [$fat->round, $fat->fat_date->format('Y-m-d'), $fat->fat_end_date->format('Y-m-d'), $fat->fat_time, $fat->cabinet_ids, $fat->status, $fat->note]);
        $d = $p->deliveries()->firstOrFail();
        $this->assertSame(['draft', 1, '10–11/12/2569 · 08:30 น.', [], 'company', ['6 ล้อเครน', 'กระบะ'], 4, null, 'บางนา ประตู 2', 'คุณวิทยา', '0812345678', 'ต้องใช้เครน'],
            [$d->status, $d->round, $d->when(), $d->cabinet_ids, $d->method, $d->vehicle_types, $d->crew_size,
                $d->carrier, $d->site, $d->contact_name, $d->contact_phone, $d->note]);
        $this->assertSame(0, $this->clerk->notifications()->count());
        $cab = Cabinet::forceCreate(['project_id' => $p->id, 'mo_no' => 'MO-9', 'cabinet_name' => 'A', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->actingAs($this->pm)->get(route('projects.deliveries.form', [$p, 'delivery' => $d->id]))
            ->assertViewHas('f', fn ($f) => $f['draft'] && $f['site'] === 'บางนา ประตู 2' && $f['crew_size'] === '4' && $f['cabinet_ids'] === [$cab->id]);

        // an outside carrier: no kinds of vehicle, its carrier and crew size kept
        $this->actingAs($this->pm)->post(route('projects.store'), ['project_no' => 'SO-11'] + $base + ['delivery_method' => 'external', 'delivery_date' => '2026-12-10',
            'delivery_vehicle_types' => ['6 ล้อเครน'], 'delivery_carrier' => 'ไทยขนส่ง', 'delivery_crew_size' => '2'])->assertSessionHasNoErrors();
        $d = Project::where('project_no', 'SO-11')->firstOrFail()->deliveries()->firstOrFail();
        $this->assertSame(['external', null, 'ไทยขนส่ง', 2, 'draft'], [$d->method, $d->vehicle_types, $d->carrier, $d->crew_size, $d->status]);

        $this->actingAs($this->pm)->post(route('projects.store'), ['project_no' => 'SO-10'] + $base)->assertSessionHasNoErrors();
        $p = Project::where('project_no', 'SO-10')->firstOrFail();
        $this->assertSame([0, 0], [$p->fats()->count(), $p->deliveries()->count()]);
    }
}
