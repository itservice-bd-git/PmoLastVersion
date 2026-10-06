<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Project;
use App\Models\User;
use App\Services\ProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Activity Log tab regroups existing activity_logs rows into a tree; it
 * must never lose a row, whether its entity still exists or not.
 */
class ActivityTreeTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    private Cabinet $cabinet;

    private CabinetSubtask $subtask;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::forceCreate(['name' => 'Admin', 'email' => 'tree@example.com', 'password' => 'secret-pass', 'role' => User::ROLE_ADMIN, 'is_active' => true]);
        $this->actingAs($this->user);

        $this->project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'Test', 'customer_name' => 'C', 'status' => 'planning']);
        $this->cabinet = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0]);
        $task = CabinetTask::forceCreate(['cabinet_id' => $this->cabinet->id, 'name' => 'Drawing', 'status' => 'not_started', 'progress' => 0, 'sequence' => 1]);
        $this->subtask = CabinetSubtask::forceCreate(['cabinet_task_id' => $task->id, 'name' => 'Housing', 'status' => 'not_started', 'progress' => 0]);
    }

    private function tree(array $query = [], ?Cabinet $cabinet = null): array
    {
        $route = $cabinet ? route('cabinets.activity-tree', $cabinet) : route('projects.activity-tree', $this->project);

        return $this->getJson($route.'?'.http_build_query($query))->assertOk()->json();
    }

    public function test_checklist_activity_is_nested_under_its_real_ancestors(): void
    {
        $checklist = $this->subtask->checklists()->create(['name' => 'Done', 'is_completed' => false, 'sequence' => 1]);
        app(ProgressService::class)->toggleChecklist($checklist, true, $this->user);

        $cabinetNode = $this->tree()['nodes'][0];
        $this->assertSame('MO-1', $cabinetNode['label']);

        $leaf = $cabinetNode['children'][0]['children'][0]['children'][0];
        $this->assertSame(['Drawing', 'Housing', 'Done'], [
            $cabinetNode['children'][0]['label'],
            $cabinetNode['children'][0]['children'][0]['label'],
            $leaf['label'],
        ]);
        $this->assertContains('ทำเครื่องหมายเสร็จ', array_column($leaf['activities'], 'title'));
        // Checklist toggles carry the before/after count (1 checklist in this subtask: 0/1 -> 1/1).
        $checked = collect($leaf['activities'])->firstWhere('action', 'checked');
        $this->assertSame(['label' => 'Checklist ที่เสร็จ', 'old' => '0/1', 'new' => '1/1'], $checked['changes'][0]);
        // Counts roll up from real rows: total is exactly the sum of the top-level nodes.
        $data = $this->tree();
        $this->assertSame($data['total'], collect($data['nodes'])->sum('count'));
        $this->assertSame(\App\Models\ActivityLog::where('project_id', $this->project->id)->count(), $data['total']);
    }

    public function test_project_level_activity_is_kept_in_its_own_section(): void
    {
        $this->project->update(['project_name' => 'Renamed']);

        $section = collect($this->tree()['nodes'])->firstWhere('key', 'project-level');

        $this->assertNotNull($section);
        $this->assertSame('Project Changes', $section['label']);
        $this->assertSame('ชื่อโครงการ', $section['activities'][0]['changes'][0]['label']);
    }

    public function test_trashed_entity_keeps_its_place_in_the_tree(): void
    {
        $checklist = $this->subtask->checklists()->create(['name' => 'Gone', 'is_completed' => false, 'sequence' => 1]);
        $before = $this->tree()['total'];
        $checklist->delete();

        $data = $this->tree();
        $leaf = $data['nodes'][0]['children'][0]['children'][0]['children'][0];

        $this->assertSame($before + 1, $data['total']);
        $this->assertSame('Gone [ถูกลบ]', $leaf['label']);
        $this->assertNull(collect($data['nodes'])->firstWhere('key', 'orphans'));
    }

    public function test_hard_deleted_entity_activity_is_not_dropped_and_page_does_not_error(): void
    {
        $checklist = $this->subtask->checklists()->create(['name' => 'Gone', 'is_completed' => false, 'sequence' => 1]);
        $before = $this->tree()['total'];
        $checklist->forceDelete();

        $data = $this->tree();
        $orphans = collect($data['nodes'])->firstWhere('key', 'orphans');

        $this->assertSame($before + 1, $data['total']);
        $this->assertNotNull($orphans);
        $this->assertStringContainsString('[ถูกลบ]', $orphans['children'][0]['label']);
    }

    public function test_filters_and_search_narrow_the_tree(): void
    {
        $checklist = $this->subtask->checklists()->create(['name' => 'Alpha', 'is_completed' => false, 'sequence' => 1]);
        app(ProgressService::class)->toggleChecklist($checklist, true, $this->user);

        $this->assertSame(1, $this->tree(['category' => 'checklist'])['total']);
        $this->assertSame(0, $this->tree(['q' => 'no-such-thing'])['total']);
        $this->assertCount(0, $this->tree(['q' => 'no-such-thing'])['nodes']);
        // Search keeps the ancestor path of a hit.
        $hit = $this->tree(['q' => 'Housing'])['nodes'][0];
        $this->assertSame('MO-1', $hit['label']);
        $this->assertSame('Housing', $hit['children'][0]['children'][0]['label']);
        $this->assertCount(0, $this->tree(['from' => '2099-01-01', 'to' => '2099-01-02'])['nodes']);
        $this->assertCount(0, $this->tree(['user_id' => $this->user->id + 999])['nodes']);
    }

    public function test_cabinet_scope_starts_at_task_level(): void
    {
        $this->subtask->checklists()->create(['name' => 'Alpha', 'is_completed' => false, 'sequence' => 1]);

        $nodes = $this->tree([], $this->cabinet)['nodes'];

        $this->assertSame('task', $nodes[0]['type']);
    }

    public function test_tree_query_count_does_not_grow_with_activity_count(): void
    {
        foreach (range(1, 20) as $i) {
            $this->subtask->checklists()->create(['name' => "C{$i}", 'is_completed' => false, 'sequence' => $i]);
        }

        \DB::enableQueryLog();
        $this->tree();
        $queries = count(\DB::getQueryLog());

        $this->assertLessThan(20, $queries);
    }

    public function test_project_and_cabinet_pages_still_render_with_the_tree_tab(): void
    {
        $this->get(route('projects.show', $this->project))->assertOk()->assertSee('activityTree(', false)->assertSee('projects\\/'.$this->project->id.'\\/activity-tree', false);
        $this->get(route('cabinets.show', $this->cabinet))->assertOk()->assertSee('cabinets\\/'.$this->cabinet->id.'\\/activity-tree', false);
    }

    public function test_guests_cannot_read_the_tree(): void
    {
        auth()->logout();
        $this->getJson(route('projects.activity-tree', $this->project))->assertUnauthorized();
    }
}
