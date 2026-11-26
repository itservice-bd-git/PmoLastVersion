<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** ทีม of a department (Settings › แผนก), a user's team, and the โครงการ list's ทีม filter (a project's team is its sales person's). */
class TeamsTest extends TestCase
{
    use RefreshDatabase;

    private Department $sales;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sales = Department::create(['name' => 'ฝ่ายขาย', 'code' => 'SALE']);
        $this->admin = $this->user('admin');
    }

    private function user(string $role = 'sales', ?Team $team = null): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'U'.++$n, 'email' => 'team'.$n.'@example.com', 'password' => 'secret-pass', 'role' => $role,
            'department_id' => $role === 'admin' ? null : $this->sales->id, 'team_id' => $team?->id, 'is_active' => true]);
    }

    private function project(string $no, ?User $sales): Project
    {
        return Project::forceCreate(['project_no' => $no, 'project_name' => $no, 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal', 'sales_person_id' => $sales?->id]);
    }

    public function test_admin_adds_renames_and_removes_a_team_of_a_department(): void
    {
        $this->actingAs($this->admin)->post(route('settings.departments.teams.store', $this->sales), ['name' => 'ทีม A'])->assertRedirect();
        $team = Team::sole();
        $this->assertSame([$this->sales->id, 'ทีม A'], [$team->department_id, $team->name]);
        $this->actingAs($this->admin)->get(route('settings.departments.index'))->assertOk()->assertSee('ทีม A');

        $this->actingAs($this->admin)->post(route('settings.departments.teams.store', $this->sales), ['name' => 'ทีม A'])->assertSessionHasErrors(['name' => 'แผนกนี้มีทีมชื่อนี้แล้ว']);
        $this->actingAs($this->admin)->put(route('settings.teams.update', $team), ['name' => 'ทีมเหนือ'])->assertRedirect();
        $this->assertSame('ทีมเหนือ', $team->fresh()->name);

        $member = $this->user('sales', $team);
        $this->actingAs($this->admin)->delete(route('settings.teams.destroy', $team))->assertSessionHas('success', 'ลบทีม "ทีมเหนือ" แล้ว · ผู้ใช้ 1 คนไม่มีทีมแล้ว');
        $this->assertNull($member->fresh()->team_id, 'the person stays, without a team');
        $this->assertNotNull($member->fresh());
    }

    public function test_only_admin_manages_teams(): void
    {
        $this->actingAs($this->user())->post(route('settings.departments.teams.store', $this->sales), ['name' => 'ทีม X'])->assertForbidden();
        $this->assertSame(0, Team::count());
    }

    public function test_a_user_is_put_in_a_team_from_settings(): void
    {
        $team = $this->sales->teams()->create(['name' => 'ทีม B']);
        $person = $this->user();

        $this->actingAs($this->admin)->get(route('settings.users.edit', $person))->assertOk()->assertSee('ทีม B');
        $this->actingAs($this->admin)->put(route('settings.users.update', $person), [
            'name' => $person->name, 'email' => $person->email, 'role' => 'sales', 'department_id' => $this->sales->id, 'team_id' => $team->id, 'is_active' => 1,
        ])->assertRedirect();
        $this->assertSame($team->id, $person->fresh()->team_id);
    }

    public function test_the_project_list_shows_one_teams_projects(): void
    {
        $a = $this->sales->teams()->create(['name' => 'ทีม A']);
        $b = $this->sales->teams()->create(['name' => 'ทีม B']);
        $this->project('P-A1', $this->user('sales', $a));
        $this->project('P-A2', $this->user('sales', $a));
        $this->project('P-B1', $this->user('sales', $b));
        $this->project('P-NONE', null);

        $this->actingAs($this->admin)->get(route('projects.index', ['team' => $a->id]))->assertOk()
            ->assertSee('P-A1')->assertSee('P-A2')->assertDontSee('P-B1')->assertDontSee('P-NONE');
        $this->actingAs($this->admin)->get(route('projects.index'))->assertOk()
            ->assertSee('P-A1')->assertSee('P-B1')->assertSee('P-NONE')->assertSee('ทุกทีม');
    }

    public function test_no_team_filter_until_a_department_has_teams(): void
    {
        $this->actingAs($this->admin)->get(route('projects.index'))->assertOk()->assertDontSee('ทุกทีม');
    }
}
