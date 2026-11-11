<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Cabinet;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\ProjectFat;
use App\Models\User;
use App\Support\EventStatuses;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Settings > สถานะงาน: the FAT and delivery states are renamed / recoloured there, and every page and new log line follows. */
class EventStatusesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $pm;

    private Project $project;

    private Cabinet $cab;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::forceCreate(['name' => 'แอดมิน', 'email' => 'es-admin', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
        $this->pm = User::forceCreate(['name' => 'PM', 'email' => 'es-pm', 'password' => 'secret-pass', 'role' => 'project_manager', 'is_active' => true]);
        $this->project = Project::forceCreate(['project_no' => 'SO-1', 'project_name' => 'MDB', 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal', 'due_date' => '2026-11-20']);
        $this->cab = Cabinet::forceCreate(['project_id' => $this->project->id, 'mo_no' => 'MO-1', 'cabinet_name' => 'A', 'quantity' => 1, 'status' => 'not_started', 'progress' => 100]);
    }

    private function input(array $over = []): array
    {
        $in = [];
        foreach (EventStatuses::all() as $group => $states) {
            foreach ($states as $key => $s) {
                $in[$group][$key] = $over[$group][$key] ?? ['name' => $s['name'], 'color' => $s['color']];
            }
        }

        return ['events' => $in];
    }

    public function test_only_admin_renames_and_recolours_and_only_changes_are_kept(): void
    {
        $this->actingAs($this->pm)->put(route('settings.statuses.events'), $this->input())->assertForbidden();
        $this->actingAs($this->admin)->get(route('settings.statuses.edit'))->assertOk()->assertSee('สถานะ FAT และการจัดส่ง')->assertSee('รอจัดรถ');

        $this->actingAs($this->admin)->put(route('settings.statuses.events'), $this->input(['delivery' => ['waiting' => ['name' => 'รอเลือกวัน', 'color' => '#FF0000']]]))
            ->assertSessionHasNoErrors();
        $this->assertSame(['delivery' => ['waiting' => ['name' => 'รอเลือกวัน', 'color' => '#ff0000']]], AppSetting::get('event_statuses'));
        $this->assertSame(['รอเลือกวัน', '#ff0000', 'จัดรถแล้ว'], [EventStatuses::name('delivery', 'waiting'), EventStatuses::color('delivery', 'waiting'), EventStatuses::name('delivery', 'booked')]);

        $this->actingAs($this->admin)->put(route('settings.statuses.events'), $this->input(['fat' => ['passed' => ['name' => '', 'color' => 'green']]]))->assertSessionHasErrors();
        $this->actingAs($this->admin)->put(route('settings.statuses.events'), ['reset' => 1])->assertSessionHasNoErrors();
        $this->assertSame([], AppSetting::get('event_statuses'));
    }

    public function test_the_pages_the_window_and_new_log_lines_use_the_set_names_and_colours(): void
    {
        EventStatuses::save(['fat' => ['scheduled' => ['name' => 'จองแล้ว', 'color' => '#123456']], 'delivery' => ['delivered' => ['name' => 'ลูกค้ารับของแล้ว', 'color' => '#00aa00'], 'external' => ['name' => 'รถรับจ้าง', 'color' => '#7c3aed']]]);
        ProjectFat::forceCreate(['project_id' => $this->project->id, 'round' => 1, 'fat_date' => '2026-11-12', 'status' => 'scheduled']);
        $d = ProjectDelivery::forceCreate(['project_id' => $this->project->id, 'round' => 1, 'delivery_date' => '2026-11-15',
            'status' => 'scheduled', 'cabinet_ids' => [$this->cab->id], 'packed' => [$this->cab->id => '2026-11-14 10:00'], 'billed_at' => '2026-11-14 11:00', 'method' => 'external', 'carrier' => '']);

        $this->actingAs($this->pm)->get(route('projects.show', $this->project))->assertOk()
            ->assertSee('จองแล้ว')->assertSee('--c: #123456', false)->assertSee('รถรับจ้าง');
        $this->actingAs($this->pm)->get(route('projects.deliveries.form', [$this->project, 'delivery' => $d->id]))->assertSee('รถรับจ้าง');
        $this->actingAs($this->pm)->get(route('planning.board'))->assertOk()->assertSee('ลูกค้ารับของแล้ว');

        $this->actingAs($this->pm)->post(route('deliveries.deliver', $d), ['delivered_date' => '2026-11-15', 'cabinet_ids' => [$this->cab->id]])->assertSessionHasNoErrors();
        $this->assertStringStartsWith('การจัดส่งรอบที่ 1: ลูกค้ารับของแล้ว 15/11/2569 · ', ActivityLog::where('action', 'delivery')->latest('id')->value('description'));
        $this->assertSame(['delivered', '#00aa00'], [$d->fresh()->stateKey(), $d->fresh()->stateColor()]);
    }
}
