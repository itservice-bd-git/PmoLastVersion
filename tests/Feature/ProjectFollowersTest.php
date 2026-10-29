<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\SubtaskAssignmentService;
use App\Support\Access;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Following a project (ProjectFollowers): its พนักงานขาย ticked on the form, or "ติดตามโครงการ" - its news comes to them. */
class ProjectFollowersTest extends TestCase
{
    use RefreshDatabase;

    private Department $sales;

    private Department $production;

    private User $pm;

    private User $saleA;

    private User $saleB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sales = Department::create(['name' => 'ขาย', 'code' => 'SAL', 'is_active' => true, 'sees_all' => true]);
        $this->production = Department::create(['name' => 'Production', 'code' => 'PRO', 'is_active' => true]);
        $this->pm = $this->user('project_manager');
        $this->saleA = $this->user('member', $this->sales, 'เซลล์ เอ');
        $this->saleB = $this->user('member', $this->sales, 'เซลล์ บี');
    }

    private function user(string $role, ?Department $d = null, ?string $name = null): User
    {
        static $n = 0;
        $n++;

        return User::forceCreate(['name' => $name ?? 'U'.$n, 'email' => 'follow-'.$n, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $d?->id, 'is_active' => true]);
    }

    private function form(array $extra = []): array
    {
        return ['project_no' => 'SO-1', 'project_name' => 'MDB', 'customer_name' => 'ลูกค้า', 'priority' => 'normal'] + $extra;
    }

    public function test_the_ticked_sales_people_follow_the_project_and_hear_it(): void
    {
        $this->actingAs($this->pm)->get(route('projects.create'))->assertOk()->assertSee('เซลล์ เอ')->assertSee('เซลล์ บี');

        $this->actingAs($this->pm)->post(route('projects.store'), $this->form(['sales_ids' => [$this->saleA->id, $this->saleB->id], 'sales_person' => 'คุณสมชาย']))->assertRedirect();
        $project = Project::firstWhere('project_no', 'SO-1');

        $this->assertSame('เซลล์ บี, เซลล์ เอ, คุณสมชาย', $project->sales_person, 'their names, then the ones typed');
        $this->assertEqualsCanonicalizing([$this->saleA->id, $this->saleB->id], $project->followers()->pluck('users.id')->all());
        $this->assertStringContainsString('คุณติดตามโครงการนี้', $this->saleA->notifications()->first()->data['body']);

        // the edit form shows them ticked and the typed name apart
        $this->actingAs($this->pm)->get(route('projects.edit', $project))->assertOk()->assertSee('value="คุณสมชาย"', false);

        // unticked: B stops following as its sales; one who follows by themselves stays
        $self = $this->user('member', $this->sales);
        $this->actingAs($self)->postJson(route('projects.follow', $project))->assertOk()->assertJsonPath('following', true);
        $this->actingAs($this->pm)->put(route('projects.update', $project), $this->form(['status' => 'draft', 'sales_ids' => [$this->saleA->id]]))->assertRedirect();
        $this->assertEqualsCanonicalizing([$this->saleA->id, $self->id], $project->followers()->pluck('users.id')->all());
        $this->assertSame('เซลล์ เอ', $project->fresh()->sales_person);
    }

    public function test_anyone_who_sees_the_project_may_follow_it_even_view_only_and_hears_its_news(): void
    {
        \App\Models\AppSetting::put('department_permissions', [(string) $this->sales->id => ['edit.work' => false]]);
        $project = Project::forceCreate(['project_no' => 'SO-2', 'project_name' => 'MCC', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal', 'project_manager_id' => $this->pm->id]);
        $cab = Cabinet::forceCreate(['project_id' => $project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::forceCreate(['cabinet_id' => $cab->id, 'name' => 'T', 'status' => 'not_started', 'progress' => 0]);
        $work = CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => 'ประกอบ', 'department_id' => $this->production->id, 'assignment_status' => 'ASSIGNED', 'status' => 'not_started', 'progress' => 0]);

        $this->assertTrue($this->saleA->isViewOnly());
        $this->actingAs($this->saleA)->postJson(route('projects.follow', $project))->assertOk()->assertJsonPath('following', true);
        $this->actingAs($this->saleA)->get(route('projects.show', $project))->assertOk()->assertSee('ติดตามอยู่');

        // Production accepts: the follower hears it with the PM
        app(SubtaskAssignmentService::class)->accept($work, $this->user('member', $this->production));
        $this->assertStringContainsString('Production รับงาน', $this->saleA->notifications()->first()->data['body']);

        // one who cannot see the project hears nothing of it (following gives no sight)
        $blind = $this->user('member', Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true]));
        $project->followers()->attach($blind->id, ['source' => 'self']);
        app(SubtaskAssignmentService::class)->start($work->fresh(), $this->user('member', $this->production));
        $this->assertSame(0, $blind->notifications()->count());

        // and again: unfollow
        $this->actingAs($this->saleA)->postJson(route('projects.follow', $project))->assertOk()->assertJsonPath('following', false);
        $this->assertFalse(Access::allows($this->saleA, 'edit.work'));
    }
}
