<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\ChecklistNoticeRule;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\ChecklistNoticeRules;
use App\Services\SubtaskPairs;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Settings > แจ้งเตือน Checklist: จัดซื้อ → คลัง → ช่าง. A department's rules say who hears what; one without rules keeps
 * the built-in notices.
 */
class ChecklistNoticeRuleTest extends TestCase
{
    use RefreshDatabase;

    private Department $pur;

    private Department $wh;

    private Department $tech;

    private Project $project;

    private CabinetSubtask $buy;

    private CabinetSubtask $receive;

    private CabinetSubtask $build;

    private User $buyer;

    private User $keeper;

    private User $maker;

    protected function setUp(): void
    {
        parent::setUp();
        [$this->pur, $this->wh, $this->tech] = array_map(fn ($n) => Department::create(['name' => $n, 'code' => $n, 'is_active' => true]), ['จัดซื้อ', 'คลัง', 'ช่าง']);
        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);
        $cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::forceCreate(['cabinet_id' => $cabinet->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
        $sub = fn ($d, $n) => CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => $n, 'department_id' => $d->id, 'assignment_status' => 'ACCEPTED', 'status' => 'not_started', 'progress' => 0]);
        [$this->buy, $this->receive, $this->build] = [$sub($this->pur, 'สั่งซื้อ'), $sub($this->wh, 'รับของ'), $sub($this->tech, 'ประกอบ')];
        foreach (['Breaker', 'Busbar'] as $i => $n) {
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $this->buy->id, 'name' => $n, 'is_completed' => false, 'sequence' => $i + 1]);
        }
        app(SubtaskPairs::class)->setCabinetPairs($this->receive, [$this->buy->id]);
        app(SubtaskPairs::class)->setCabinetPairs($this->build, [$this->receive->id]);
        $user = fn ($d) => User::forceCreate(['name' => $d->name.'คน', 'email' => $d->code.'@x', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $d->id, 'is_active' => true]);
        [$this->buyer, $this->keeper, $this->maker] = [$user($this->pur), $user($this->wh), $user($this->tech)];
    }

    private function rule(array $data): ChecklistNoticeRule
    {
        return ChecklistNoticeRule::create($data + ['is_active' => true, 'recipients' => ['linked']]);
    }

    /** @return list<string> "title — latest" of each notice the user has */
    private function heard(User $u): array
    {
        return $u->fresh()->notifications()->orderBy('created_at')->get()->map(fn ($n) => $n->data['latest'] ?? $n->data['title'])->all();
    }

    private function tick(User $u, CabinetSubtask $s, string $name): void
    {
        $item = CabinetChecklist::where('cabinet_subtask_id', $s->id)->where('name', $name)->firstOrFail();
        $this->actingAs($u)->patchJson(route('checklists.toggle', $item), ['is_completed' => true])->assertOk();
    }

    public function test_admin_sets_rules_on_the_settings_page(): void
    {
        $admin = User::forceCreate(['name' => 'A', 'email' => 'a@x', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        $this->actingAs($this->keeper)->get(route('settings.notice-rules.index'))->assertForbidden();
        $this->actingAs($admin)->get(route('settings.notice-rules.index'))->assertOk()->assertSee('ยังไม่มีกฎ');

        $this->actingAs($admin)->post(route('settings.notice-rules.store'), ['event' => 'late', 'department_id' => $this->pur->id, 'message' => 'ของเข้าช้า {n} วัน',
            'recipients' => ['linked', 'pm'], 'is_active' => '1'])->assertSessionHasErrors('days');
        $this->actingAs($admin)->post(route('settings.notice-rules.store'), ['event' => 'late', 'department_id' => $this->pur->id, 'days' => 3, 'repeat' => '1',
            'message' => 'ของเข้าช้า {n} วัน', 'recipients' => ['linked', 'pm', 'hack'], 'is_active' => '1'])->assertSessionHasErrors('recipients.2');
        $this->actingAs($admin)->post(route('settings.notice-rules.store'), ['event' => 'late', 'department_id' => $this->pur->id, 'days' => 3, 'repeat' => '1',
            'message' => 'ของเข้าช้า {n} วัน', 'recipients' => ['linked', 'pm', 'dept:'.$this->tech->id], 'is_active' => '1'])->assertSessionHasNoErrors();

        $rule = ChecklistNoticeRule::sole();
        $this->assertSame(['late', 3, true, ['linked', 'pm', 'dept:'.$this->tech->id]], [$rule->event, $rule->days, $rule->repeat, $rule->recipients]);
        $this->actingAs($admin)->get(route('settings.notice-rules.index'))->assertSee('เลยวัน 3 วัน')->assertSee('PM ของโครงการ')->assertSee('ช่าง');

        $this->actingAs($admin)->put(route('settings.notice-rules.update', $rule), ['toggle' => 1]);
        $this->assertFalse($rule->fresh()->is_active);
        $this->actingAs($admin)->post(route('settings.notice-rules.test', $rule))->assertSessionHas('success');
        $this->assertStringContainsString('ของเข้าช้า 3 วัน', $admin->notifications()->first()->data['title']);
        $this->actingAs($admin)->delete(route('settings.notice-rules.destroy', $rule));
        $this->assertSame(0, ChecklistNoticeRule::count());
    }

    public function test_the_keepers_rules_tell_purchasing_and_the_builders_instead_of_the_built_in_notice(): void
    {
        $this->rule(['event' => 'tick', 'department_id' => $this->wh->id, 'message' => 'ของเข้าแล้ว {รายการ}', 'recipients' => ['dept:'.$this->pur->id]]);
        $this->rule(['event' => 'cabinet', 'department_id' => $this->wh->id, 'message' => 'ของครบแล้ว {ตู้} นำไปประกอบได้', 'recipients' => ['dept:'.$this->tech->id]]);

        $this->tick($this->keeper, $this->receive, 'Breaker');
        $this->assertSame(['คลัง: ของเข้าแล้ว "Breaker" — MO-1 · P-1'], $this->heard($this->buyer), 'the rule, not "คลัง รับแล้ว"');
        $this->assertSame([], $this->heard($this->maker), 'not yet: the list is not complete, and the built-in "คลัง เสร็จ" is replaced');
        $this->assertTrue(CabinetChecklist::where('cabinet_subtask_id', $this->buy->id)->where('name', 'Breaker')->value('is_completed'), 'ticking along still happens');

        $this->tick($this->keeper, $this->receive, 'Busbar');
        $this->assertSame(['คลัง: ของครบแล้ว MO-1 นำไปประกอบได้ — MO-1 · P-1'], $this->heard($this->maker));
        $this->assertStringContainsString('คลัง: ของเข้าแล้ว (2)', $this->buyer->fresh()->notifications()->first()->data['body'], 'one project notice adding up');
        $this->assertSame([], $this->heard($this->keeper), 'never the person who did it');
    }

    public function test_a_department_without_rules_keeps_the_built_in_notices(): void
    {
        $this->rule(['event' => 'tick', 'department_id' => $this->tech->id, 'message' => 'ประกอบแล้ว', 'recipients' => ['linked']]);   // another department's rule
        $this->tick($this->keeper, $this->receive, 'Breaker');
        $this->assertStringContainsString('คลัง รับ "Breaker" แล้ว', $this->heard($this->buyer)[0]);
        $this->assertStringContainsString('คลัง เสร็จ "Breaker"', $this->heard($this->maker)[0]);
    }

    public function test_the_morning_round_warns_before_the_date_and_when_late(): void
    {
        Carbon::setTestNow('2026-11-04 08:00');   // Wednesday
        $breaker = CabinetChecklist::where('cabinet_subtask_id', $this->buy->id)->where('name', 'Breaker')->first();
        $busbar = CabinetChecklist::where('cabinet_subtask_id', $this->buy->id)->where('name', 'Busbar')->first();
        $breaker->update(['expected_date' => '2026-10-30']);   // Friday: 3 working days late on Wednesday
        $busbar->update(['expected_date' => '2026-11-06']);    // Friday: 2 working days ahead
        $this->rule(['event' => 'late', 'department_id' => $this->pur->id, 'days' => 3, 'message' => 'ของเข้าช้า {n} วัน {รายการ}', 'recipients' => ['dept:'.$this->pur->id, 'linked']]);
        $this->rule(['event' => 'before', 'department_id' => $this->pur->id, 'days' => 2, 'message' => 'ของจะเข้า {วันที่}', 'recipients' => ['linked']]);

        $rules = app(ChecklistNoticeRules::class);
        $this->assertSame(3, $rules->daily(today()), 'late: จัดซื้อ + คลัง (the linked side); before: คลัง');
        $body = $this->keeper->notifications()->sole()->data['body'];   // both in the one project notice
        $this->assertStringContainsString('จัดซื้อ: ของจะเข้า', $body);
        $this->assertStringContainsString('จัดซื้อ: ของเข้าช้า 3 วัน', $body);
        $this->assertSame([], $this->heard($this->maker), 'ช่าง is not paired with จัดซื้อ');
        $this->assertStringContainsString('จัดซื้อ: ของเข้าช้า 3 วัน "Breaker"', $this->heard($this->buyer)[0]);
        $this->assertSame(0, $rules->daily(today()), 'once a day');

        Carbon::setTestNow('2026-11-05 08:00');   // 4 working days late: only on the 3rd day without "repeat"
        $this->assertSame(0, $rules->daily(today()));
        ChecklistNoticeRule::where('event', 'late')->update(['repeat' => true]);
        $this->assertSame(2, $rules->daily(today()), 'with "repeat": every day');

        Carbon::setTestNow('2026-11-06 08:00');
        $this->tick($this->keeper, $this->receive, 'Breaker');   // received: no more warnings
        $this->assertSame(0, (new ChecklistNoticeRules)->daily(today()));
        Carbon::setTestNow();
    }
}
