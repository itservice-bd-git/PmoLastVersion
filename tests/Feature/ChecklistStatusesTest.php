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
}
