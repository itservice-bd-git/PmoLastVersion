<?php

namespace Tests\Feature;

use App\Models\AutomationRule;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\DateLimitRule;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationTest extends TestCase
{
    use RefreshDatabase;

    private Department $qc;

    private Department $delivery;

    private Cabinet $cabinet;

    private CabinetTask $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-24 09:00:00');
        $this->qc = Department::create(['name' => 'QC', 'code' => 'QC']);
        $this->delivery = Department::create(['name' => 'Delivery', 'code' => 'DEL']);

        $project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'Test', 'customer_name' => 'C', 'status' => 'planning', 'priority' => 'normal']);
        $this->cabinet = Cabinet::forceCreate(['project_id' => $project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $this->task = CabinetTask::forceCreate(['cabinet_id' => $this->cabinet->id, 'name' => 'Task', 'status' => 'not_started', 'progress' => 0]);
    }

    private function user(?Department $d, string $role = 'member'): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'U'.++$n, 'email' => 'auto'.$n.'@example.com', 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    private function subtask(Department $d, string $status = 'ACCEPTED', ?string $due = null, int $checks = 2): CabinetSubtask
    {
        $s = CabinetSubtask::forceCreate([
            'cabinet_task_id' => $this->task->id, 'name' => 'S', 'department_id' => $d->id,
            'assignment_status' => $status, 'due_date' => $due, 'status' => 'not_started', 'progress' => 0,
        ]);
        for ($i = 1; $i <= $checks; $i++) {
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $s->id, 'name' => "c$i", 'is_completed' => false, 'sequence' => $i]);
        }

        return $s;
    }

    private function rule(string $trigger, string $action, ?Department $d = null, bool $active = true): AutomationRule
    {
        return AutomationRule::create(['name' => 'r', 'department_id' => $d?->id, 'trigger' => $trigger, 'action' => $action, 'is_active' => $active]);
    }

    private function tick(User $u, CabinetChecklist $c, bool $on = true)
    {
        return $this->actingAs($u)->patchJson(route('checklists.toggle', $c), ['is_completed' => $on]);
    }

    public function test_all_done_rule_completes_the_subtask_even_from_accepted(): void
    {
        $this->rule('checklist_all_done', 'complete', $this->qc);
        $s = $this->subtask($this->qc, 'ACCEPTED');
        $user = $this->user($this->qc);
        [$a, $b] = $s->checklists;

        $this->tick($user, $a)->assertOk()->assertJson(['assignment' => null]);
        $this->assertSame('ACCEPTED', $s->fresh()->assignment_status);

        $res = $this->tick($user, $b)->assertOk();
        $this->assertSame('COMPLETED', $s->fresh()->assignment_status);
        $this->assertSame('COMPLETED', $res->json('assignment.assignment_status'));
        $this->assertSame($user->id, $s->fresh()->completed_by);
    }

    public function test_first_done_rule_starts_the_subtask(): void
    {
        $this->rule('checklist_first_done', 'start');
        $s = $this->subtask($this->qc, 'ACCEPTED');

        $this->tick($this->user($this->qc), $s->checklists[0])->assertOk();

        $this->assertSame('IN_PROGRESS', $s->fresh()->assignment_status);
    }

    public function test_no_effect_when_rule_inactive_other_department_or_no_rule(): void
    {
        $s = $this->subtask($this->qc);
        $u = $this->user($this->qc);

        $this->tick($u, $s->checklists[0]);
        $this->tick($u, $s->checklists[1]);                                                       // no rules at all
        $this->assertSame('ACCEPTED', $s->fresh()->assignment_status);

        $this->rule('checklist_all_done', 'complete', $this->qc, active: false);
        $this->rule('checklist_all_done', 'complete', $this->delivery);                          // other department
        $other = $this->subtask($this->qc);
        $this->tick($u, $other->checklists[0]);
        $this->tick($u, $other->checklists[1]);
        $this->assertSame('ACCEPTED', $other->fresh()->assignment_status);
    }

    public function test_unticking_never_reverts_and_a_pm_outside_the_department_cannot_trigger_a_move(): void
    {
        $this->rule('checklist_all_done', 'complete');
        $s = $this->subtask($this->qc);
        $dept = $this->user($this->qc);
        [$a, $b] = $s->checklists;

        // a PM may tick (checklist editable) but is not in the department -> the rule is skipped, tick still saved
        $pm = $this->user($this->delivery, 'project_manager');
        $this->tick($pm, $a)->assertOk();
        $this->tick($pm, $b)->assertOk();
        $this->assertSame('ACCEPTED', $s->fresh()->assignment_status);
        $this->assertTrue($a->fresh()->is_completed);

        // the department user unticks and reticks: completes; a later untick leaves it COMPLETED
        $this->tick($dept, $a, false);
        $this->tick($dept, $a, true);
        $this->assertSame('COMPLETED', $s->fresh()->assignment_status);
        $this->tick($dept, $a, false)->assertStatus(403);   // completed work is no longer checklist-editable at all
        $this->assertSame('COMPLETED', $s->fresh()->assignment_status);
    }

    public function test_date_limit_blocks_a_later_due_date_but_not_unrelated_edits(): void
    {
        DateLimitRule::create(['anchor_department_id' => $this->qc->id, 'blocked_department_id' => $this->delivery->id, 'is_active' => true]);
        $this->subtask($this->qc, 'ACCEPTED', '2026-10-10');
        $mine = $this->subtask($this->delivery, 'ACCEPTED', '2026-10-05');
        $pm = $this->user($this->qc, 'project_manager');
        $base = ['status' => 'not_started', 'start_date' => '2026-10-01'];

        $this->actingAs($pm)->putJson(route('cabinet-subtasks.update', $mine), $base + ['due_date' => '2026-10-11'])
            ->assertUnprocessable()->assertJsonValidationErrors('due_date');
        $this->assertSame('2026-10-05', $mine->fresh()->due_date->format('Y-m-d'));

        // exactly the anchor's date, and an earlier one, are fine
        $this->actingAs($pm)->putJson(route('cabinet-subtasks.update', $mine), $base + ['due_date' => '2026-10-10'])->assertOk();
        $this->actingAs($pm)->putJson(route('cabinet-subtasks.update', $mine), $base + ['due_date' => '2026-10-02'])->assertOk();

        // re-saving without changing the date (remark only) is never blocked, even if the anchor moved earlier
        CabinetSubtask::where('department_id', $this->qc->id)->update(['due_date' => '2026-09-30']);
        $this->actingAs($pm)->putJson(route('cabinet-subtasks.update', $mine), $base + ['due_date' => '2026-10-02', 'remark' => 'note'])->assertOk();

        // an inactive rule is ignored
        DateLimitRule::query()->update(['is_active' => false]);
        $this->actingAs($pm)->putJson(route('cabinet-subtasks.update', $mine), $base + ['due_date' => '2026-12-01'])->assertOk();
    }

    public function test_settings_page_and_actions_are_admin_only(): void
    {
        $member = $this->user($this->qc);
        $pm = $this->user($this->qc, 'project_manager');
        $admin = $this->user($this->qc, 'admin');
        $payload = ['name' => 'x', 'trigger' => 'checklist_all_done', 'action' => 'complete'];
        $limit = ['anchor_department_id' => $this->qc->id, 'blocked_department_id' => $this->delivery->id];

        foreach ([$member, $pm] as $u) {
            $this->actingAs($u)->get(route('settings.automation.index'))->assertForbidden();
            $this->actingAs($u)->post(route('settings.automation.rules.store'), $payload)->assertForbidden();
            $this->actingAs($u)->post(route('settings.automation.limits.store'), $limit)->assertForbidden();
        }
        $this->assertSame(0, AutomationRule::count() + DateLimitRule::count());

        $this->actingAs($admin)->get(route('settings.automation.index'))->assertOk();
        $this->actingAs($admin)->post(route('settings.automation.rules.store'), $payload)->assertRedirect();
        $this->actingAs($admin)->post(route('settings.automation.limits.store'), $limit)->assertRedirect();
        $this->actingAs($admin)->post(route('settings.automation.limits.store'), ['anchor_department_id' => $this->qc->id, 'blocked_department_id' => $this->qc->id])->assertSessionHasErrors('blocked_department_id');
        $this->assertSame(1, AutomationRule::count());
        $this->assertSame(1, DateLimitRule::count());

        $rule = AutomationRule::first();
        $this->actingAs($admin)->patch(route('settings.automation.rules.toggle', $rule))->assertRedirect();
        $this->assertFalse($rule->fresh()->is_active);
        $this->actingAs($pm)->delete(route('settings.automation.rules.destroy', $rule))->assertForbidden();
        $this->actingAs($admin)->delete(route('settings.automation.rules.destroy', $rule))->assertRedirect();
        $this->assertSame(0, AutomationRule::count());
    }
}
