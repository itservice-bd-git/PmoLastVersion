<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\ChecklistStatus;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\PlanningBoard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Settings > สีสถานะ Checklist: coloured tags on checklist items, several per item, a group's ones exclusive. */
class ChecklistStatusesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $wiring;   // a member of ไฟฟ้า

    private Department $metal;

    private Project $project;

    private CabinetSubtask $el;

    private CabinetChecklist $breaker;

    private ChecklistStatus $out;

    private ChecklistStatus $wait;

    private ChecklistStatus $in;

    protected function setUp(): void
    {
        parent::setUp();

        $electric = Department::create(['name' => 'ไฟฟ้า', 'code' => 'EL', 'is_active' => true]);
        $this->metal = Department::create(['name' => 'โลหะ', 'code' => 'ME', 'is_active' => true]);
        $this->admin = User::forceCreate(['name' => 'Admin', 'email' => 'cs-admin', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        $this->wiring = User::forceCreate(['name' => 'วิภา', 'email' => 'cs-el', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $electric->id, 'is_active' => true]);

        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::forceCreate(['cabinet_id' => $cabinet->id, 'name' => 'งานเดินสาย', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
        $this->el = CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => 'ติดตั้ง Breaker', 'department_id' => $electric->id, 'assignment_status' => 'ACCEPTED', 'status' => 'in_progress', 'progress' => 0]);
        $this->breaker = CabinetChecklist::forceCreate(['cabinet_subtask_id' => $this->el->id, 'name' => 'Main 400A', 'is_completed' => false, 'sequence' => 1]);

        $this->actingAs($this->admin)->post(route('settings.checklist-statuses.starter'))->assertRedirect();
        [$this->out, $this->wait, $this->in] = [ChecklistStatus::where('name', 'ของสั่งนอก')->first(), ChecklistStatus::where('name', 'รอของ')->first(), ChecklistStatus::where('name', 'ของเข้าแล้ว')->first()];
    }

    private function tag(User $user, array $ids)
    {
        return $this->actingAs($user)->postJson(route('checklist-editor.apply', ['subtask', $this->el->id]), ['type' => 'tags', 'id' => $this->breaker->id, 'tags' => $ids]);
    }

    public function test_admin_sets_up_statuses_and_only_unused_ones_can_be_deleted(): void
    {
        $this->actingAs($this->admin)->get(route('settings.checklist-statuses.index'))->assertOk()->assertSee('ของสั่งนอก')->assertSee('การรับของ · เลือกได้อย่างเดียว');
        $this->actingAs($this->wiring)->get(route('settings.checklist-statuses.index'))->assertForbidden();

        $this->actingAs($this->admin)->post(route('settings.checklist-statuses.store'), ['name' => 'ต้องแก้งาน', 'color' => 'pink', 'scope' => 'some', 'department_ids' => [$this->metal->id]])->assertSessionHasNoErrors();
        $redo = ChecklistStatus::where('name', 'ต้องแก้งาน')->first();
        $this->assertSame([$this->metal->id], $redo->department_ids);
        $this->actingAs($this->admin)->post(route('settings.checklist-statuses.store'), ['name' => 'ของสั่งนอก', 'color' => 'red', 'scope' => 'all'])->assertSessionHasErrors('name');

        $this->tag($this->wiring, [$this->out->id])->assertOk();
        $this->actingAs($this->admin)->delete(route('settings.checklist-statuses.destroy', $this->out))->assertSessionHas('error');
        $this->assertModelExists($this->out);
        $this->actingAs($this->admin)->delete(route('settings.checklist-statuses.destroy', $redo));
        $this->assertModelMissing($redo);
    }

    public function test_an_item_takes_several_statuses_and_a_group_keeps_one(): void
    {
        $res = $this->tag($this->wiring, [$this->out->id, $this->wait->id])->assertOk()->assertJsonPath('ok', true);
        $this->assertSame([$this->out->id, $this->wait->id], collect($res->json('items'))->firstWhere('id', $this->breaker->id)['tags']);

        // the menu swaps the group's one: ของเข้าแล้ว replaces รอของ
        $this->tag($this->wiring, [$this->out->id, $this->wait->id, $this->in->id])->assertOk();
        $this->assertEqualsCanonicalizing([$this->out->id, $this->in->id], $this->breaker->statuses()->pluck('checklist_statuses.id')->all());
        $this->assertSame('สถานะของ Checklist "Main 400A": เปลี่ยน "รอของ" → "ของเข้าแล้ว"', ActivityLog::where('action', 'checklist_status')->latest('id')->value('description'));

        // two new ones of a group at once: refused
        $this->tag($this->wiring, [])->assertOk();
        $this->tag($this->wiring, [$this->wait->id, $this->in->id])->assertStatus(422)->assertJsonPath('message', 'กลุ่ม "การรับของ" เลือกได้อย่างเดียว');
    }

    public function test_switched_off_and_other_department_statuses_cannot_be_picked(): void
    {
        $this->tag($this->wiring, [$this->out->id])->assertOk();
        $this->actingAs($this->admin)->patch(route('settings.checklist-statuses.toggle', $this->out));

        $this->tag($this->wiring, [$this->out->id, $this->wait->id])->assertOk();   // kept: it was on already
        $this->tag($this->wiring, [$this->wait->id])->assertOk();                    // taken off: fine
        $this->tag($this->wiring, [$this->out->id])->assertStatus(422);              // picked again: no

        $this->wait->update(['department_ids' => [$this->metal->id]]);
        $this->tag($this->wiring, [$this->wait->id])->assertOk();                    // already on
        $this->tag($this->wiring, [])->assertOk();
        $this->tag($this->wiring, [$this->wait->id])->assertStatus(422);
    }

    public function test_who_may_tag_and_a_closed_project(): void
    {
        $other = User::forceCreate(['name' => 'สมชาย', 'email' => 'cs-me', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $this->metal->id, 'is_active' => true]);
        $this->tag($other, [$this->out->id])->assertStatus(422);

        $this->project->update(['status' => 'completed']);
        $this->tag($this->wiring, [$this->out->id])->assertStatus(423);
        $this->assertSame(0, $this->breaker->statuses()->count());
    }

    public function test_planning_shows_and_sets_them(): void
    {
        $this->tag($this->wiring, [$this->out->id])->assertOk();
        $board = app(PlanningBoard::class)->build($this->wiring);
        $this->assertSame('ของสั่งนอก', $board['boards'][PlanningBoard::BOARD_ID]['checklistStatuses'][0]['name']);
        $item = collect($board['boards'][PlanningBoard::BOARD_ID]['tasks'][0]['subtasks'][0]['checklist'])->firstWhere('id', 'k'.$this->breaker->id);
        $this->assertSame([$this->out->id], $item['tags']);

        $res = $this->actingAs($this->wiring)->postJson(route('planning.sync'), ['ops' => [
            ['type' => 'checklist_tags', 'taskId' => 'p'.$this->project->id, 'itemId' => 'k'.$this->breaker->id, 'tags' => [$this->wait->id]],
        ]])->assertOk();
        $this->assertTrue($res->json('results.0.ok'));
        $this->assertSame([$this->wait->id], $this->breaker->statuses()->pluck('checklist_statuses.id')->all());
    }

    public function test_the_cabinet_page_carries_the_statuses(): void
    {
        $this->tag($this->wiring, [$this->out->id])->assertOk();
        $this->actingAs($this->wiring)->get(route('cabinets.show', $this->el->cabinetTask->cabinet))->assertOk()
            ->assertSee('window.pmoChecklistStatuses', false)->assertSee('สถานะในตู้นี้');
    }

    public function test_each_side_of_a_pair_sees_the_others_statuses_read_only(): void
    {
        // โลหะ compares with ไฟฟ้า: it holds a copy of the breaker item
        $metalSub = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->el->cabinet_task_id, 'name' => 'ประกอบตู้', 'department_id' => $this->metal->id, 'assignment_status' => 'ACCEPTED', 'status' => 'in_progress', 'progress' => 0]);
        $copy = CabinetChecklist::forceCreate(['cabinet_subtask_id' => $metalSub->id, 'name' => 'Main 400A', 'is_completed' => false, 'sequence' => 1, 'source_checklist_id' => $this->breaker->id]);
        $this->tag($this->wiring, [$this->wait->id])->assertOk();
        $copy->statuses()->attach($this->out->id);

        $peers = app(\App\Services\ChecklistStatuses::class)->peersFor([$this->breaker->fresh(), $copy->fresh()]);
        $this->assertSame([['id' => $this->out->id, 'dept' => 'โลหะ', 'sub' => 's'.$metalSub->id]], $peers[$this->breaker->id]);
        $this->assertSame([['id' => $this->wait->id, 'dept' => 'ไฟฟ้า', 'sub' => 's'.$this->el->id]], $peers[$copy->id]);

        // the copy keeps its own: the source's are not added to it
        $this->assertSame([$this->out->id], $copy->statuses()->pluck('checklist_statuses.id')->all());

        $board = app(PlanningBoard::class)->build($this->wiring);
        $item = collect($board['boards'][PlanningBoard::BOARD_ID]['tasks'][0]['subtasks'][0]['checklist'])->firstWhere('id', 'k'.$this->breaker->id);
        $this->assertSame([['id' => $this->out->id, 'dept' => 'โลหะ', 'sub' => 's'.$metalSub->id]], $item['peerTags']);
    }

    public function test_every_department_along_a_linked_list_sees_the_others(): void
    {
        // จัดซื้อ's item → คลัง's copy → Production's copy of that copy, and QC's second copy of the source
        [$buy, $store, $prod, $qc] = collect(['จัดซื้อ', 'คลัง', 'Production', 'QC'])->map(fn ($n, $i) => CabinetSubtask::forceCreate(['cabinet_task_id' => $this->el->cabinet_task_id, 'name' => $n, 'department_id' => Department::create(['name' => $n, 'code' => 'D'.$i, 'is_active' => true])->id, 'assignment_status' => 'ACCEPTED', 'status' => 'in_progress', 'progress' => 0]))->all();
        $item = fn ($sub, $src = null) => CabinetChecklist::forceCreate(['cabinet_subtask_id' => $sub->id, 'name' => 'Main 400A', 'is_completed' => false, 'sequence' => 1, 'source_checklist_id' => $src?->id]);
        $a = $item($buy);
        $b = $item($store, $a);
        $c = $item($prod, $b);
        $d = $item($qc, $a);
        $a->statuses()->attach($this->wait->id);
        $c->statuses()->attach($this->out->id);

        $peers = app(\App\Services\ChecklistStatuses::class)->peersFor([$b->fresh(), $d->fresh()]);
        $this->assertEqualsCanonicalizing([['id' => $this->wait->id, 'dept' => 'จัดซื้อ', 'sub' => 's'.$buy->id], ['id' => $this->out->id, 'dept' => 'Production', 'sub' => 's'.$prod->id]], $peers[$b->id]);
        $this->assertEqualsCanonicalizing([['id' => $this->wait->id, 'dept' => 'จัดซื้อ', 'sub' => 's'.$buy->id], ['id' => $this->out->id, 'dept' => 'Production', 'sub' => 's'.$prod->id]], $peers[$d->id]);
        $this->assertSame([['id' => $this->wait->id, 'dept' => 'จัดซื้อ', 'sub' => 's'.$buy->id]], app(\App\Services\ChecklistStatuses::class)->peersFor([$c->fresh()])[$c->id]);
    }

    public function test_a_status_set_to_tick_ticks_the_item_and_taking_it_off_keeps_the_tick(): void
    {
        $this->actingAs($this->admin)->put(route('settings.checklist-statuses.update', $this->in), ['name' => 'ของเข้าแล้ว', 'color' => 'teal', 'group_name' => 'การรับของ', 'scope' => 'all', 'ticks_item' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue($this->in->fresh()->ticks_item);

        // an ordinary status does not tick
        $this->tag($this->wiring, [$this->wait->id])->assertOk();
        $this->assertFalse($this->breaker->fresh()->is_completed);

        // "รอของ" → "ของเข้าแล้ว": ticked as a hand would, and said so in the log
        $res = $this->tag($this->wiring, [$this->in->id])->assertOk();
        $this->assertTrue($this->breaker->fresh()->is_completed);
        $this->assertSame($this->wiring->id, $this->breaker->fresh()->completed_by);
        $this->assertTrue(collect($res->json('items'))->firstWhere('id', $this->breaker->id)['done']);
        $this->assertSame(100, $this->el->fresh()->progress);
        $this->assertStringContainsString('ติ๊กรายการให้', ActivityLog::where('action', 'checklist_status')->latest('id')->value('description'));
        $this->assertSame(1, ActivityLog::where('action', 'checked')->count());

        // off again: the tick stays
        $this->tag($this->wiring, [])->assertOk();
        $this->assertTrue($this->breaker->fresh()->is_completed);
    }

    public function test_a_compared_list_carries_its_items_statuses(): void
    {
        $metalSub = CabinetSubtask::forceCreate(['cabinet_task_id' => $this->el->cabinet_task_id, 'name' => 'ประกอบตู้', 'department_id' => $this->metal->id, 'assignment_status' => 'ACCEPTED', 'status' => 'in_progress', 'progress' => 0]);
        \Illuminate\Support\Facades\DB::table('cabinet_subtask_pairs')->insert(['cabinet_subtask_id' => $metalSub->id, 'source_subtask_id' => $this->el->id, 'view_only' => true, 'own_ticks' => false, 'created_at' => now(), 'updated_at' => now()]);
        $this->tag($this->wiring, [$this->out->id])->assertOk();

        $watched = app(\App\Services\HandoffService::class)->linkedForSubtask($metalSub->fresh());
        $this->assertTrue($watched[0]['watch']);
        $this->assertSame([$this->out->id], collect($watched[0]['items'])->firstWhere('id', 'k'.$this->breaker->id)['tags']);
    }
}
