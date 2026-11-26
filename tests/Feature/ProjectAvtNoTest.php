<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** เลข AVT: typed in the project form, a column of the project list (in place of แผนก), found by the list's search. */
class ProjectAvtNoTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_shows_and_searches_the_avt_number_instead_of_departments(): void
    {
        $admin = User::forceCreate(['name' => 'A', 'email' => 'a@x', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        Project::forceCreate(['project_no' => 'SO-1', 'project_name' => 'Spec Zeal', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal', 'avt_no' => 'AVT69502']);
        Project::forceCreate(['project_no' => 'SO-2', 'project_name' => 'Other', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);

        $this->actingAs($admin)->get(route('projects.index'))
            ->assertOk()->assertSee('เลข AVT')->assertSee('AVT69502')->assertDontSee('>แผนก</th>', false);

        $this->get(route('projects.index', ['q' => '69502']))->assertSee('SO-1')->assertDontSee('SO-2');
    }

    public function test_the_list_shows_the_sales_team(): void
    {
        $admin = User::forceCreate(['name' => 'A', 'email' => 'a@x', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        $dept = Department::create(['name' => 'Sales', 'code' => 'SAL', 'is_active' => true]);
        $team = Team::forceCreate(['department_id' => $dept->id, 'name' => 'ทีมขายเหนือ']);
        $sales = User::forceCreate(['name' => 'S', 'email' => 's@x', 'password' => 'secret-pass', 'role' => 'sales', 'is_active' => true, 'team_id' => $team->id]);
        Project::forceCreate(['project_no' => 'SO-1', 'project_name' => 'P', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal', 'sales_person_id' => $sales->id]);

        $this->actingAs($admin)->get(route('projects.index'))->assertOk()->assertSee('>ทีมขาย</th>', false)->assertSee('ทีมขายเหนือ');
    }

    public function test_the_form_saves_the_avt_number(): void
    {
        $admin = User::forceCreate(['name' => 'A', 'email' => 'a@x', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        $project = Project::forceCreate(['project_no' => 'SO-1', 'project_name' => 'Spec Zeal', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal']);

        $this->actingAs($admin)->get(route('projects.edit', $project))->assertOk()->assertSee('name="avt_no"', false);
        $this->put(route('projects.update', $project), ['avt_no' => 'AVT69502'] + $project->only(['project_no', 'project_name', 'customer_name', 'status', 'priority']));

        $this->assertSame('AVT69502', $project->fresh()->avt_no);
    }
}
