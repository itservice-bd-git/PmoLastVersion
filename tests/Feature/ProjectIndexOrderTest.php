<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectIndexOrderTest extends TestCase
{
    use RefreshDatabase;

    private function project(string $no, ?string $due, string $status = 'in_progress'): void
    {
        Project::forceCreate(['project_no' => $no, 'project_name' => $no, 'customer_name' => 'C', 'status' => $status, 'due_date' => $due, 'priority' => 'normal']);
    }

    public function test_projects_are_sorted_by_nearest_due_date_with_days_remaining(): void
    {
        $this->travelTo('2026-09-24 09:00:00');

        $this->project('P-FAR', '2026-12-01');
        $this->project('P-NODATE', null);
        $this->project('P-DONE', '2026-09-01', 'completed');
        $this->project('P-LATE', '2026-09-20');
        $this->project('P-SOON', '2026-09-27');
        $this->project('P-TODAY', '2026-09-24');

        $user = User::forceCreate(['name' => 'U', 'email' => 'u@example.com', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);

        $response = $this->actingAs($user)->get(route('projects.index'))->assertOk();

        $response->assertSeeInOrder(['P-LATE', 'P-TODAY', 'P-SOON', 'P-FAR', 'P-NODATE', 'P-DONE']);
        $response->assertSee('เลยกำหนด 4 วัน');
        $response->assertSee('ครบกำหนดวันนี้');
        $response->assertSee('เหลือ 3 วัน');
        $response->assertSee('เหลือ 68 วัน');
        // Finished projects get no countdown: only the 4 dated, active ones do.
        $this->assertSame(4, substr_count($response->getContent(), 'data-countdown'));
    }

    public function test_the_order_is_planning_dashboards_late_first_then_nearest_due_then_planning_order(): void
    {
        $this->travelTo('2026-09-24 09:00:00');
        $done = \App\Models\BoardStatus::create(['name' => 'ส่งมอบแล้ว', 'color' => '#16a34a', 'is_done' => true, 'sort' => 9]);

        $this->project('P-SOON-A', '2026-09-27');
        $this->project('P-MARKED-DONE', '2026-09-10');                  // past its date but done on Planning: not late
        Project::where('project_no', 'P-MARKED-DONE')->update(['board_status_id' => $done->id]);
        $this->project('P-LATE-NEW', '2026-09-22');
        $this->project('P-LATE-OLD', '2026-09-15');
        $this->project('P-SOON-B', '2026-09-27');                      // same day as P-SOON-A: Planning's order (made later)
        $this->project('P-CANCELLED', '2026-09-01', 'cancelled');

        $user = User::forceCreate(['name' => 'U', 'email' => 'u@example.com', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);

        $this->actingAs($user)->get(route('projects.index'))->assertOk()
            ->assertSeeInOrder(['P-LATE-OLD', 'P-LATE-NEW', 'P-MARKED-DONE', 'P-SOON-A', 'P-SOON-B', 'P-CANCELLED']);
    }
}
