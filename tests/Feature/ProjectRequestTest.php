<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ProjectRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-07 09:00:00');
        RateLimiter::clear('throttle');
    }

    private function user(string $role): User
    {
        static $n = 0;

        return User::forceCreate(['name' => 'U'.++$n, 'email' => 'req'.$n, 'password' => 'secret-pass', 'role' => $role, 'is_active' => true]);
    }

    private function payload(array $over = []): array
    {
        return $over + [
            'requester_name' => 'Somchai', 'contact' => '081-000-0000', 'customer_name' => 'ABC Co.',
            'title' => 'MDB 4 ตู้', 'description' => 'รายละเอียด', 'quantity' => 4, 'needed_by' => '2026-12-01',
        ];
    }

    private function pending(): ProjectRequest
    {
        return ProjectRequest::create($this->payload() + ['status' => 'pending']);
    }

    public function test_public_form_needs_no_login_and_creates_a_pending_request(): void
    {
        $admin = $this->user('admin');
        $pm = $this->user('project_manager');
        $member = $this->user('member');

        $this->get(route('requests.create'))->assertOk();
        $this->post(route('requests.store'), $this->payload())->assertRedirect(route('requests.thanks'));
        $this->get(route('requests.thanks'))->assertOk();

        $r = ProjectRequest::sole();
        $this->assertSame('pending', $r->status);
        $this->assertSame('MDB 4 ตู้', $r->title);
        $this->assertSame(0, Project::count(), 'the public form must never create a Project by itself');

        // only the people who can act on it are notified
        $this->assertSame(1, $admin->notifications()->count());
        $this->assertSame(1, $pm->notifications()->count());
        $this->assertSame(0, $member->notifications()->count());
        $this->assertSame('request', $admin->notifications()->first()->data['kind']);
    }

    public function test_honeypot_and_validation(): void
    {
        $admin = $this->user('admin');

        $this->post(route('requests.store'), $this->payload(['website' => 'http://spam']))->assertRedirect(route('requests.thanks'));
        $this->assertSame(0, ProjectRequest::count());
        $this->assertSame(0, $admin->notifications()->count());

        $this->post(route('requests.store'), [])->assertSessionHasErrors(['requester_name', 'contact', 'customer_name', 'title']);
        $this->post(route('requests.store'), $this->payload(['needed_by' => '2026-01-01']))->assertSessionHasErrors('needed_by');
        $this->post(route('requests.store'), $this->payload(['quantity' => 0]))->assertSessionHasErrors('quantity');
        $this->post(route('requests.store'), $this->payload(['description' => str_repeat('x', 3001)]))->assertSessionHasErrors('description');
        $this->assertSame(0, ProjectRequest::count());
    }

    public function test_submissions_are_throttled_per_ip(): void
    {
        foreach (range(1, 5) as $_) {
            $this->post(route('requests.store'), $this->payload())->assertRedirect(route('requests.thanks'));
        }

        $this->post(route('requests.store'), $this->payload())->assertStatus(429);
        $this->assertSame(5, ProjectRequest::count());
    }

    public function test_only_admin_and_pm_can_open_the_inbox(): void
    {
        $this->get(route('project-requests.index'))->assertRedirect(route('login'));

        foreach (['member', 'sales', 'production'] as $role) {
            $this->actingAs($this->user($role))->get(route('project-requests.index'))->assertForbidden();
        }
        foreach (['admin', 'project_manager'] as $role) {
            $this->actingAs($this->user($role))->get(route('project-requests.index'))->assertOk();
        }
    }

    public function test_import_creates_a_draft_project_once(): void
    {
        Project::forceCreate(['project_no' => 'PJ-690004', 'project_name' => 'old', 'customer_name' => 'x', 'status' => 'planning', 'priority' => 'normal']);
        $r = $this->pending();
        $pm = $this->user('project_manager');

        $this->assertSame('PJ-690005', ProjectRequest::suggestedProjectNo());

        $this->actingAs($pm)->post(route('project-requests.import', $r), ['project_no' => 'PJ-690005'])
            ->assertRedirect(route('projects.show', Project::where('project_no', 'PJ-690005')->first()));

        $project = Project::where('project_no', 'PJ-690005')->sole();
        $this->assertSame('MDB 4 ตู้', $project->project_name);
        $this->assertSame('ABC Co.', $project->customer_name);
        $this->assertSame('draft', $project->status);
        $this->assertSame('2026-12-01', $project->due_date->format('Y-m-d'));
        $this->assertStringContainsString('Somchai', $project->remark);

        $r->refresh();
        $this->assertSame('imported', $r->status);
        $this->assertSame($project->id, $r->project_id);
        $this->assertSame($pm->id, $r->handled_by);

        // double click / second admin: no second Project
        $this->actingAs($pm)->post(route('project-requests.import', $r), ['project_no' => 'PJ-690006'])->assertSessionHasErrors('project_no');
        $this->assertSame(0, Project::where('project_no', 'PJ-690006')->count());
    }

    public function test_import_rejects_duplicate_project_numbers_and_non_pmo_users(): void
    {
        $r = $this->pending();
        Project::forceCreate(['project_no' => 'PJ-1', 'project_name' => 'old', 'customer_name' => 'x', 'status' => 'planning', 'priority' => 'normal']);

        $this->actingAs($this->user('admin'))->post(route('project-requests.import', $r), ['project_no' => 'PJ-1'])->assertSessionHasErrors('project_no');
        $this->assertSame('pending', $r->fresh()->status);

        $this->actingAs($this->user('member'))->post(route('project-requests.import', $r), ['project_no' => 'PJ-2'])->assertForbidden();
        $this->actingAs($this->user('member'))->post(route('project-requests.reject', $r), ['reject_reason' => 'x'])->assertForbidden();
        $this->assertSame('pending', $r->fresh()->status);
    }

    public function test_reject_needs_a_reason_and_only_applies_to_pending_requests(): void
    {
        $r = $this->pending();
        $admin = $this->user('admin');

        $this->actingAs($admin)->post(route('project-requests.reject', $r), [])->assertSessionHasErrors('reject_reason');
        $this->assertSame('pending', $r->fresh()->status);

        $this->actingAs($admin)->post(route('project-requests.reject', $r), ['reject_reason' => 'นอกขอบเขตงาน'])->assertSessionHas('success');
        $r->refresh();
        $this->assertSame('rejected', $r->status);
        $this->assertSame('นอกขอบเขตงาน', $r->reject_reason);

        // a handled request cannot be rejected again or imported afterwards
        $this->actingAs($admin)->post(route('project-requests.reject', $r), ['reject_reason' => 'again'])->assertSessionHas('error');
        $this->assertSame('นอกขอบเขตงาน', $r->fresh()->reject_reason);
        $this->actingAs($admin)->post(route('project-requests.import', $r), ['project_no' => 'PJ-9'])->assertSessionHasErrors('project_no');
        $this->assertSame(0, Project::count());
    }
}
