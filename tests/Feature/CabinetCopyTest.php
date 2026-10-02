<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CabinetCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_copying_a_cabinet_carries_over_its_schedule(): void
    {
        $user = User::forceCreate(['name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        $project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'P', 'customer_name' => 'C', 'status' => 'planning', 'priority' => 'normal']);
        $source = Cabinet::forceCreate([
            'project_id' => $project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Source', 'quantity' => 1,
            'status' => 'in_progress', 'progress' => 40,
            'start_date' => '2026-09-01', 'due_date' => '2026-10-01', 'expected_completion_date' => '2026-09-28',
        ]);

        $this->actingAs($user)
            ->post(route('cabinets.copy', $source), ['mo_no' => 'MO-2', 'cabinet_name' => 'Copy'])
            ->assertRedirect();

        $copy = Cabinet::where('mo_no', 'MO-2')->firstOrFail();
        $this->assertSame('2026-09-01', $copy->start_date->format('Y-m-d'));
        $this->assertSame('2026-10-01', $copy->due_date->format('Y-m-d'));
        $this->assertSame('2026-09-28', $copy->expected_completion_date->format('Y-m-d'));

        // Only the schedule carries over - progress/status reset like everything else about a copy.
        $this->assertSame('not_started', $copy->status);
        $this->assertSame(0, $copy->progress);
    }

    public function test_copying_a_cabinet_without_dates_leaves_the_copy_undated(): void
    {
        $user = User::forceCreate(['name' => 'Admin', 'email' => 'admin2@example.com', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        $project = Project::forceCreate(['project_no' => 'P-2', 'project_name' => 'P', 'customer_name' => 'C', 'status' => 'planning', 'priority' => 'normal']);
        $source = Cabinet::forceCreate(['project_id' => $project->id, 'mo_no' => 'MO-3', 'cabinet_name' => 'Source', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);

        $this->actingAs($user)
            ->post(route('cabinets.copy', $source), ['mo_no' => 'MO-4', 'cabinet_name' => 'Copy'])
            ->assertRedirect();

        $copy = Cabinet::where('mo_no', 'MO-4')->firstOrFail();
        $this->assertNull($copy->start_date);
        $this->assertNull($copy->due_date);
        $this->assertNull($copy->expected_completion_date);
    }
}
