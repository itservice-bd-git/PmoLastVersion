<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The one-off remark -> Activity Log data move, now a migration (it used to be a
 * public script with no login). Must copy each remark once and be safe to re-run.
 */
class RemarkMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_remarks_are_copied_into_the_activity_log_exactly_once(): void
    {
        $project = Project::forceCreate(['project_no' => 'P-1', 'project_name' => 'T', 'customer_name' => 'C', 'status' => 'planning', 'remark' => 'old project note']);
        Project::forceCreate(['project_no' => 'P-2', 'project_name' => 'T', 'customer_name' => 'C', 'status' => 'planning', 'remark' => '']);
        $cabinet = Cabinet::forceCreate(['project_id' => $project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'Cab', 'quantity' => 1, 'status' => 'not_started', 'progress' => 0, 'remark' => 'old cabinet note']);
        ActivityLog::query()->delete();

        $migration = require database_path('migrations/2026_10_05_000001_migrate_remarks_to_activity_notes.php');
        $migration->up();
        $migration->up(); // re-running (e.g. the old script already ran) must not duplicate

        $rows = ActivityLog::where('action', 'remark_migrated')->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['old project note', 'old cabinet note'], $rows->pluck('description')->all());
        $this->assertSame([Project::class, Cabinet::class], $rows->pluck('loggable_type')->all());
        $this->assertSame([$project->id, $project->id], $rows->pluck('project_id')->all());
        $this->assertSame([$project->id, $cabinet->id], $rows->pluck('loggable_id')->all());
    }
}
