<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\User;
use App\Services\CeoOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The dashboard's "ภาพรวม CEO (ใหม่)" view (App\Services\CeoOverview): money in hand, what is late, next month's deliveries. */
class CeoOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 23)->setTime(9, 0));
    }

    private function project(string $no, string $status, string $due, float $value, int $progress, string $customer = 'บริษัท ฤทธา จำกัด'): Project
    {
        $p = Project::forceCreate(['project_no' => $no, 'project_name' => 'P '.$no, 'customer_name' => $customer, 'status' => $status, 'priority' => 'normal',
            'start_date' => '2026-10-01', 'due_date' => $due, 'contract_value' => $value]);
        Cabinet::forceCreate(['project_id' => $p->id, 'mo_no' => 'MO-'.$no, 'cabinet_name' => $no, 'quantity' => 1, 'progress' => $progress,
            'status' => $progress >= 100 ? 'completed' : 'in_progress', 'due_date' => $due]);

        return $p;
    }

    private function admin2(): User
    {
        return User::forceCreate(['name' => 'B', 'email' => 'ceo-admin-2', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
    }

    private function admin(): User
    {
        return User::forceCreate(['name' => 'A', 'email' => 'ceo-admin', 'password' => 'secret-pass', 'role' => 'admin', 'is_active' => true]);
    }

    public function test_it_reads_the_money_in_hand_what_is_late_and_next_month(): void
    {
        // late and already out of the factory: closable; late and still being made
        $gone = $this->project('GONE', 'in_progress', '2026-11-20', 500000, 100);
        ProjectDelivery::forceCreate(['project_id' => $gone->id, 'round' => 1, 'delivery_date' => '2026-11-19', 'status' => ProjectDelivery::DEPARTED, 'cabinet_ids' => []]);
        $this->project('SLOW', 'in_progress', '2026-11-22', 100000, 40, 'บริษัท ซีพี-เมจิ จำกัด');
        // next month: one barely started, one half made
        $this->project('DEC1', 'in_progress', '2026-12-03', 2000000, 10);
        $this->project('DEC2', 'in_progress', '2026-12-18', 1000000, 50);
        // delivered this month: one on time, one late
        $done = $this->project('DONE', 'in_progress', '2026-11-15', 300000, 100);
        ProjectDelivery::forceCreate(['project_id' => $done->id, 'round' => 1, 'delivery_date' => '2026-11-10', 'status' => ProjectDelivery::DELIVERED, 'delivered_at' => '2026-11-10 15:00', 'cabinet_ids' => []]);
        ProjectDelivery::forceCreate(['project_id' => $done->id, 'round' => 2, 'delivery_date' => '2026-11-12', 'status' => ProjectDelivery::DELIVERED, 'delivered_at' => '2026-11-14 10:00', 'cabinet_ids' => []]);
        DB::table('projects')->where('id', $done->id)->update(['status' => 'completed']);   // closed after its deliveries (a closed project is locked)

        $c = app(CeoOverview::class)->build($this->admin());

        $this->assertSame([4, 3600000.0], [$c['backlog']['projects'], $c['backlog']['value']]);
        $this->assertSame([2, 600000.0], [$c['late']['projects'], $c['late']['value']]);
        $this->assertSame(['GONE', 'SLOW'], array_column($c['late']['rows'], 'no'), 'worth most first');
        $this->assertSame(['ฤทธา', 3, true], [$c['late']['rows'][0]['customer'], $c['late']['rows'][0]['days'], $c['late']['rows'][0]['shipped']]);
        $this->assertSame([1, 500000.0, 1, 100000.0], [$c['closable']['projects'], $c['closable']['value'], $c['closable']['leftProjects'], $c['closable']['leftValue']]);
        $this->assertSame(['rounds' => 2, 'onTime' => 1, 'rate' => 50, 'month' => 'พ.ย.'], $c['onTime']);

        $f = $c['focus'];
        $this->assertSame(['ธ.ค.', 2, 3000000.0, 30, 1, 2000000.0], [$f['month'], $f['projects'], $f['value'], $f['progress'], $f['low'], $f['lowValue']]);
        $this->assertSame(['DEC1', 'DEC2'], array_column($f['top'], 'no'));
        $this->assertSame(['projects' => 1, 'value' => 2000000.0, 'progress' => 10], $f['firstWeek']);
        $dec = collect($c['months'])->firstWhere('label', 'ธ.ค.');
        $this->assertSame([true, 2, 3000000.0, 30], [$dec['next'], $dec['projects'], $dec['value'], $dec['progress']]);
        $this->assertSame('ฤทธา', $c['customers'][0]['name']);

        // each department: what it finished in 30 days and how much on time, what it holds and how late
        $qc = Department::create(['name' => 'QC', 'code' => 'QC', 'is_active' => true]);
        $task = CabinetTask::forceCreate(['cabinet_id' => Cabinet::where('mo_no', 'MO-SLOW')->value('id'), 'name' => '1. QC', 'status' => 'in_progress', 'progress' => 0, 'sequence' => 0]);
        $sub = fn (array $a) => CabinetSubtask::forceCreate($a + ['cabinet_task_id' => $task->id, 'name' => 'ตรวจ', 'department_id' => $qc->id, 'status' => 'in_progress', 'progress' => 0]);
        $sub(['status' => 'completed', 'assignment_status' => 'COMPLETED', 'due_date' => '2026-11-20', 'completed_at' => '2026-11-19 10:00']);   // on time
        $sub(['status' => 'completed', 'assignment_status' => 'COMPLETED', 'due_date' => '2026-11-10', 'completed_at' => '2026-11-12 10:00']);   // late
        $sub(['assignment_status' => 'ASSIGNED', 'due_date' => '2026-11-13']);   // still open, 10 days late
        $sub(['assignment_status' => 'ASSIGNED', 'due_date' => '2026-11-30']);   // still open, not yet due
        $d = app(CeoOverview::class)->build($this->admin2())['departments'];
        $this->assertSame(['QC', 2, 1, 50, 2, 1, 10, 'watch'], [$d['rows'][0]['name'], $d['rows'][0]['done'], $d['rows'][0]['onTime'], $d['rows'][0]['rate'],
            $d['rows'][0]['open'], $d['rows'][0]['late'], $d['rows'][0]['oldest'], $d['rows'][0]['state']]);
    }

    public function test_money_is_left_out_without_the_finance_permission_and_the_tab_shows(): void
    {
        $this->project('A', 'in_progress', '2026-11-20', 500000, 50);
        $pm = User::forceCreate(['name' => 'P', 'email' => 'ceo-pm', 'password' => 'secret-pass', 'role' => 'project_manager', 'is_active' => true]);

        if (! $pm->allows('data.finance')) {
            $c = app(CeoOverview::class)->build($pm);
            $this->assertNull($c['backlog']['value']);
            $this->assertNull($c['late']['rows'][0]['value']);
        }

        // the dashboard has one view each now: no ภาพรวม CEO tab (the report itself is still built, above)
        $this->actingAs($this->admin())->get(route('dashboard', ['view' => 'ceo']))->assertOk()->assertViewHas('view', 'pm')->assertDontSeeText('ภาพรวม CEO (ใหม่)');
    }
}
