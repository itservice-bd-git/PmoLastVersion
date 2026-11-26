<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\ProjectFat;
use App\Models\Team;
use App\Models\User;
use App\Services\DailyWork;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** "งานวันนี้": per department, what came in and what got done on a day, and what it holds now. */
class DailyWorkTest extends TestCase
{
    use RefreshDatabase;

    private Department $production;

    private Department $shipping;

    private CabinetTask $task;

    private CabinetTask $closedTask;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-19 10:00'));   // the cabinets came in earlier
        $this->production = Department::create(['name' => 'Production', 'code' => 'PRO', 'is_active' => true]);
        $this->shipping = Department::create(['name' => 'จัดส่งสินค้า', 'code' => 'SHP', 'is_active' => true]);
        $this->task = $this->taskIn($this->project('SO-OPEN'), 2);
        $closed = $this->project('SO-CLOSED');
        $this->closedTask = $this->taskIn($closed, 1);
        $closed->forceFill(['status' => 'completed'])->saveQuietly();
        $this->travelTo(Carbon::parse('2026-10-21 14:00'));
    }

    private function project(string $projectNo): Project
    {
        return Project::forceCreate(['project_no' => $projectNo, 'project_name' => 'P '.$projectNo, 'customer_name' => 'ลูกค้า '.$projectNo, 'status' => 'in_progress', 'priority' => 'normal']);
    }

    private function taskIn(Project $project, int $quantity): CabinetTask
    {
        $cabinet = Cabinet::forceCreate(['project_id' => $project->id, 'mo_no' => 'MO-'.$project->project_no, 'cabinet_name' => 'Cab', 'quantity' => $quantity, 'progress' => 0, 'status' => 'in_progress']);

        return CabinetTask::forceCreate(['cabinet_id' => $cabinet->id, 'name' => 'ผลิต', 'status' => 'in_progress', 'progress' => 0, 'sequence' => 1]);
    }

    private function subtask(Department $department, string $assignmentStatus, array $fields = [], ?CabinetTask $task = null): CabinetSubtask
    {
        return CabinetSubtask::forceCreate(array_merge(['cabinet_task_id' => ($task ?? $this->task)->id, 'name' => 'งาน', 'department_id' => $department->id,
            'assignment_status' => $assignmentStatus, 'status' => 'in_progress', 'progress' => 0], $fields));
    }

    private function tick(CabinetSubtask $subtask, string $at): void
    {
        CabinetChecklist::forceCreate(['cabinet_subtask_id' => $subtask->id, 'name' => 'ข้อ', 'is_completed' => true, 'completed_at' => $at]);
    }

    private function user(string $role, ?Department $department = null): User
    {
        static $userNumber = 0;
        $userNumber++;

        return User::forceCreate(['name' => 'U'.$userNumber, 'email' => 'today-'.$userNumber, 'password' => 'secret-pass', 'role' => $role, 'department_id' => $department?->id, 'is_active' => true]);
    }

    /** the day's work, as on the page */
    private function dayOfWork(): array
    {
        $work = [];
        $work['receivedToday'] = $this->subtask($this->production, 'ASSIGNED', ['name' => 'เข้าวันนี้', 'assigned_at' => '2026-10-21 09:00']);
        $work['overdue'] = $this->subtask($this->production, 'ACCEPTED', ['assigned_at' => '2026-10-20 09:00', 'accepted_at' => '2026-10-21 08:10', 'due_date' => '2026-10-20']);
        $work['completedByButton'] = $this->subtask($this->production, 'COMPLETED', ['name' => 'กดเสร็จวันนี้', 'status' => 'completed', 'assigned_at' => '2026-10-18 09:00', 'completed_at' => '2026-10-21 10:00']);
        // done by its checklist: no completed_at; the time is its last tick
        $work['completedByChecklist'] = $this->subtask($this->shipping, 'ACCEPTED', ['name' => 'เช็คลิสต์ครบ', 'status' => 'completed', 'assigned_at' => '2026-10-15 09:00', 'completed_date' => '2026-10-21']);
        $this->tick($work['completedByChecklist'], '2026-10-21 08:00');
        $this->tick($work['completedByChecklist'], '2026-10-21 11:15');
        // pressed yesterday, its checklist finished today: done yesterday, once
        $work['completedYesterday'] = $this->subtask($this->shipping, 'COMPLETED', ['status' => 'completed', 'assigned_at' => '2026-10-15 09:00', 'completed_at' => '2026-10-20 16:00', 'completed_date' => '2026-10-21']);
        $this->tick($work['completedYesterday'], '2026-10-20 15:00');
        // deleted work counts nowhere
        $deleted = $this->subtask($this->production, 'ASSIGNED', ['assigned_at' => '2026-10-21 09:30']);
        $deleted->delete();
        // a closed project: its finished work still counts as done today, its open work is not held "now"
        $this->subtask($this->shipping, 'COMPLETED', ['status' => 'completed', 'assigned_at' => '2026-10-10 09:00', 'completed_at' => '2026-10-21 09:45'], $this->closedTask);
        $this->subtask($this->shipping, 'ASSIGNED', ['assigned_at' => '2026-10-10 09:00', 'due_date' => '2026-10-11'], $this->closedTask);

        return $work;
    }

    public function test_it_counts_the_day_per_department(): void
    {
        $work = $this->dayOfWork();
        $summary = app(DailyWork::class)->build(null, today());
        $departmentRows = collect($summary['departments'])->keyBy('id');
        $productionRow = $departmentRows[$this->production->id];
        $shippingRow = $departmentRows[$this->shipping->id];

        $this->assertSame([1, 2, 1, 2], [$productionRow['received'], $productionRow['receivedCabinets'], $productionRow['completed'], $productionRow['completedCabinets']], 'a cabinet of 2 counts as 2 ตู้');
        $this->assertSame(['waiting' => 1, 'inProgress' => 1, 'overdue' => 1, 'pending' => 2], array_intersect_key($productionRow, array_flip(['pending', 'waiting', 'inProgress', 'overdue'])));
        $this->assertSame([0, 2], [$shippingRow['received'], $shippingRow['completed']], 'checklist done today + the closed project\'s');
        $this->assertSame(0, $shippingRow['pending'], 'a closed project\'s open work is not held');
        $this->assertSame(['received' => 1, 'completed' => 3, 'newCabinetQuantity' => 0, 'overdue' => 1, 'pending' => 2], array_intersect_key($summary['totals'], array_flip(['received', 'completed', 'pending', 'overdue', 'newCabinetQuantity'])));

        // the last 7 days end on the day shown; done yesterday is yesterday's
        $this->assertCount(DailyWork::CHART_DAYS_FOR_ONE_DAY, $shippingRow['chart']);
        $this->assertSame(['2026-10-21', true, 2], [end($shippingRow['chart'])['date'], end($shippingRow['chart'])['isHighlighted'], end($shippingRow['chart'])['completed']]);
        $this->assertSame(1, $shippingRow['chart'][5]['completed']);
        $this->assertSame(1, $productionRow['chart'][5]['received']);

        $feed = collect($summary['feed'])->keyBy('key');
        $this->assertSame('11:15', $feed['completed-'.$work['completedByChecklist']->id]['time'], 'timed by the last tick');
        $this->assertSame('08:10', $feed['accepted-'.$work['overdue']->id]['time']);
        $this->assertSame(['SO-OPEN', 'P SO-OPEN'], [$feed['accepted-'.$work['overdue']->id]['projectNo'], $feed['accepted-'.$work['overdue']->id]['projectName']]);
        $this->assertSame(route('cabinets.show', $this->task->cabinet_id).'#goto-subtask-'.$work['overdue']->id, $feed['accepted-'.$work['overdue']->id]['url'], 'opens the cabinet at that Sub Task');
        $this->assertArrayNotHasKey('completed-'.$work['completedYesterday']->id, $feed->all());
    }

    public function test_it_counts_the_day_per_project(): void
    {
        $work = $this->dayOfWork();
        $team = Team::forceCreate(['department_id' => $this->production->id, 'name' => 'ทีมขายเหนือ']);
        $sales = User::forceCreate(['name' => 'S', 'email' => 'sales-1', 'password' => 'secret-pass', 'role' => 'sales', 'is_active' => true, 'team_id' => $team->id]);
        Project::where('project_no', 'SO-OPEN')->update(['sales_person_id' => $sales->id]);
        $this->project('SO-IDLE');
        $open = Project::where('project_no', 'SO-OPEN')->first();
        $open->forceFill(['due_date' => '2026-10-20'])->saveQuietly();   // past due, nothing made: late
        $summary = app(DailyWork::class)->build(null, today(), by: DailyWork::BY_PROJECT);
        $rows = collect($summary['departments'])->keyBy('projectNo');

        $this->assertSame(DailyWork::BY_PROJECT, $summary['by']);
        $openRow = $rows['SO-OPEN'];
        $this->assertSame([1, 2, 2, 2], [$openRow['received'], $openRow['receivedCabinets'], $openRow['completed'], $openRow['completedCabinets']], 'every department of the project');
        $this->assertSame(['waiting' => 1, 'inProgress' => 1, 'overdue' => 1, 'pending' => 2], array_intersect_key($openRow, array_flip(['pending', 'waiting', 'inProgress', 'overdue'])));
        $this->assertSame([['name' => 'Production', 'count' => 2]], $openRow['pendingDepartments']);
        $this->assertSame(['late', -1, route('projects.show', $open)], [$openRow['dueTone'], $openRow['daysLeft'], $openRow['url']]);
        $this->assertSame(['ทีมขายเหนือ', ''], [$openRow['team'], $rows['SO-CLOSED']['team']], 'the sales person\'s team');
        $this->assertSame(2, $openRow['ticked'], 'the Checklist items ticked today (completedByChecklist\'s two ticks; yesterday\'s tick not)');
        $this->assertSame(2, $openRow['tickedToday']);
        $this->assertSame([Carbon::parse('2026-10-21 11:15')->getTimestamp(), 'วันนี้ 11:15'], [$openRow['lastTickAt'], $openRow['lastTickLabel']], 'the latest tick: for "เคลื่อนไหวล่าสุด"');
        $this->assertNull($rows['SO-IDLE']['lastTickAt']);
        $this->assertSame(2, collect(app(DailyWork::class)->build(null, Carbon::parse('2026-10-15'), Carbon::parse('2026-10-18'), by: DailyWork::BY_PROJECT)['departments'])->firstWhere('projectNo', 'SO-OPEN')['tickedToday'], 'today\'s ticks whatever the range');

        $this->assertSame([1, 0, 'เสร็จสิ้น'], [$rows['SO-CLOSED']['completed'], $rows['SO-CLOSED']['pending'], $rows['SO-CLOSED']['statusLabel']], 'a closed project shows when its work moved');
        $this->assertTrue($rows['SO-IDLE']['isQuiet'], 'every running project gets a card');
        $this->assertSame(['SO-OPEN', 'SO-IDLE', 'SO-CLOSED'], array_column($summary['departments'], 'projectNo'), 'running first (quiet after), closed last');
        $this->assertNull($rows['SO-CLOSED']['daysLeft'], 'a closed project is never late');
        $this->assertSame(2, $summary['totals']['activeProjectCount']);
        $this->assertSame($open->id, collect($summary['feed'])->firstWhere('key', 'accepted-'.$work['overdue']->id)['projectId']);

        // a department-scoped viewer: only its departments' work, and only projects it has work in
        $scoped = collect(app(DailyWork::class)->build([$this->shipping->id], today(), by: DailyWork::BY_PROJECT)['departments'])->keyBy('projectNo');
        $this->assertSame(['SO-CLOSED', 'SO-OPEN'], $scoped->keys()->sort()->values()->all());
        $this->assertSame([0, 1, 0], [$scoped['SO-OPEN']['received'], $scoped['SO-OPEN']['completed'], $scoped['SO-OPEN']['pending']]);
    }

    public function test_the_page_by_project(): void
    {
        $this->dayOfWork();
        $this->actingAs($this->user('admin'))->get(route('reports.projects'))->assertOk()
            ->assertSee('สรุปงานรายโครงการ')->assertSee('projectSummary(', false)->assertDontSee('แยกตามแผนก');
        $this->getJson(route('reports.projects.data'))->assertOk()->assertJsonPath('by', DailyWork::BY_PROJECT);
        // the dashboard's panel: one project as the panel reads it
        $open = Project::where('project_no', 'SO-OPEN')->first();
        $one = $this->getJson(route('reports.projects.one', $open))->assertOk();
        $this->assertSame(['SO-OPEN'], array_column($one->json('departments'), 'projectNo'));
        $this->assertSame([$open->id], array_values(array_unique(array_column($one->json('feed'), 'projectId'))));
        // read a month at a time: with no days asked for, this month so far; days asked for are kept
        $this->assertSame(['2026-10-01', '2026-10-21'], array_values(array_intersect_key($this->getJson(route('reports.projects.data'))->json('range'), array_flip(['from', 'to']))));
        $this->assertSame(['2026-09-01', '2026-09-30'], array_values(array_intersect_key($this->getJson(route('reports.projects.data', ['from' => '2026-09-01', 'to' => '2026-09-30']))->json('range'), array_flip(['from', 'to']))));
        $this->assertSame('2026-10-21', $this->getJson(route('reports.today.data'))->json('range.from'), 'สรุปงาน still opens on today');
        $this->get(route('dashboard'))->assertSee(route('reports.projects'), false);   // in the menu
        $this->get(route('reports.today'))->assertOk()->assertDontSee('แยกตามโครงการ')->assertSee('workSummary(', false);   // สรุปงาน as it was
    }

    public function test_the_production_line_is_worked_out_from_the_ticks(): void
    {
        // started 10 days ago, due in 10 days: the plan says 50% today
        $project = Project::where('project_no', 'SO-OPEN')->first();
        $project->forceFill(['start_date' => '2026-10-11', 'due_date' => '2026-10-31'])->saveQuietly();
        $subtask = $this->subtask($this->production, 'ACCEPTED');
        foreach (['2026-10-13 09:00', '2026-10-17 09:00', null, null] as $at) {
            CabinetChecklist::forceCreate(['cabinet_subtask_id' => $subtask->id, 'name' => 'ข้อ', 'is_completed' => $at !== null, 'completed_at' => $at]);
        }
        Cabinet::whereKey($this->task->cabinet_id)->update(['progress' => 50]);   // what ProgressService keeps: 2 of 4

        $row = collect(app(DailyWork::class)->build(null, today(), by: DailyWork::BY_PROJECT)['departments'])->firstWhere('projectNo', 'SO-OPEN');
        $curve = collect($row['curve']['actual'])->mapWithKeys(fn ($point) => [$point[0] => $point[1]]);

        $this->assertSame([0, 10, 20], [$curve[0], $row['curve']['todayDay'], $row['curve']['dueDay']]);
        $this->assertSame(25, $curve[3], 'one of four ticked on 13 ต.ค.');
        $this->assertSame(50, $curve[10], 'today: the real %');
        // the bars: a tick on the 13th and one on the 17th (days 2 and 6 from the start), a bar a day up to today
        $this->assertSame([1, 11, 1, 1], [$row['curve']['barDays'], count($row['curve']['bars']), $row['curve']['bars'][2], $row['curve']['bars'][6]]);
        $this->assertSame(2, array_sum($row['curve']['bars']));

        // the ▲ beside the %: from 25% before the 17th's tick (the range 17 - 21 ต.ค.) to today's real 50%
        $week = collect(app(DailyWork::class)->build(null, Carbon::parse('2026-10-17'), today(), by: DailyWork::BY_PROJECT)['departments'])->firstWhere('projectNo', 'SO-OPEN');
        $this->assertSame(25, $week['curve']['gain']);
        $this->assertNull($row['curve']['gain'] ?: null, 'nothing ticked today: no rise');
        $this->assertSame([50, 0, '11 ต.ค.', 'ส่ง 31 ต.ค.'], [$row['curve']['planToday'], $row['curve']['gap'], $row['curve']['startLabel'], $row['curve']['endLabel']]);
    }

    public function test_the_panel_gets_a_project_as_its_levels(): void
    {
        $project = Project::where('project_no', 'SO-OPEN')->first();
        Cabinet::whereKey($this->task->cabinet_id)->update(['progress' => 40]);
        $this->task->forceFill(['progress' => 50, 'due_date' => '2026-10-20'])->saveQuietly();   // past its due date, half done
        $subtask = $this->subtask($this->production, 'ACCEPTED', ['name' => 'ประกอบ', 'progress' => 50]);
        CabinetChecklist::forceCreate(['cabinet_subtask_id' => $subtask->id, 'name' => 'ข้อแรก', 'is_completed' => true, 'completed_at' => '2026-10-20 09:00']);
        CabinetChecklist::forceCreate(['cabinet_subtask_id' => $subtask->id, 'name' => 'ข้อสอง', 'is_completed' => false]);
        ProjectFat::forceCreate(['project_id' => $project->id, 'round' => 1, 'fat_date' => '2026-10-10', 'status' => ProjectFat::PASSED, 'cabinet_ids' => []]);
        ProjectDelivery::forceCreate(['project_id' => $project->id, 'round' => 1, 'delivery_date' => '2026-10-27', 'status' => ProjectDelivery::WAITING, 'cabinet_ids' => [$this->task->cabinet_id]]);

        $cabinet = $this->actingAs($this->user('admin'))->getJson(route('reports.projects.tree', $project))->assertOk()->json('cabinets.0');
        // ข้อมูลโครงการ: [label, value] lines, the empty ones left out, no money
        $info = collect($this->getJson(route('reports.projects.tree', $project))->json('info'))->mapWithKeys(fn ($line) => [$line[0] => $line[1]]);
        $this->assertSame('ลูกค้า SO-OPEN', $info['ลูกค้า']);
        $this->assertArrayNotHasKey('ส่งรอบถัดไป', $info->all(), 'the delivery has a section of its own');
        // การจัดส่ง: each round with its state and its four steps; how many cabinets have arrived
        $deliveries = $this->getJson(route('reports.projects.tree', $project))->json('deliveries');
        $this->assertSame([0, 1], [$deliveries['arrived'], $deliveries['cabinets']]);
        $this->assertSame([1, '27/10/2569', 'รอจัดรถ'], [$deliveries['rounds'][0]['round'], $deliveries['rounds'][0]['when'], $deliveries['rounds'][0]['state']]);
        $this->assertSame(['ห่อ 0/1', 'เปิดบิล', 'ออกจากโรงงาน', 'ส่งถึง'], array_column($deliveries['rounds'][0]['steps'], 'label'));
        $this->assertCount(1, $deliveries['rounds'][0]['cabinetNames'], 'the round names the cabinets it sends');
        $chip = $this->getJson(route('reports.projects.tree', $project))->json('cabinets.0.deliveryChip');
        $this->assertSame('ส่งรอบ 1 · 27/10/2569 · รอจัดรถ', $chip['text']);
        $this->assertSame('waiting', $chip['step']);
        $this->assertSame('รอบ 1 · 10/10/2569 · ผ่าน', $info['FAT']);
        $this->assertArrayNotHasKey('เลข PO', $info->all(), 'none given: left out');
        $this->assertStringNotContainsString('มูลค่า', $info->keys()->implode(' '));

        $this->assertSame([40, 'MO-SO-OPEN', 'ส่งรอบ 1 · รอจัดรถ', 'FAT 10/10/2569 · ผ่าน'], [$cabinet['progress'], $cabinet['moNo'], $cabinet['delivery'], $cabinet['fat']]);
        $this->assertSame(['ผลิต', 50], [$cabinet['tasks'][0]['name'], $cabinet['tasks'][0]['progress']]);
        // each level's result, for its bar's colour: going / late (with its due date) / done / not started
        $this->assertSame(['going', 'late', '20/10/2569', 'going'], [$cabinet['result'], $cabinet['tasks'][0]['result'], $cabinet['tasks'][0]['dueLabel'], $cabinet['tasks'][0]['subtasks'][0]['result']]);
        $this->assertSame(['ประกอบ', 50, 'Production'], [$cabinet['tasks'][0]['subtasks'][0]['name'], $cabinet['tasks'][0]['subtasks'][0]['progress'], $cabinet['tasks'][0]['subtasks'][0]['department']]);
        $this->assertSame([['ข้อแรก', true], ['ข้อสอง', false]], array_map(fn ($item) => [$item['name'], $item['done']], $cabinet['tasks'][0]['subtasks'][0]['checklist']));

        // someone whose departments have no work in it cannot ask for it (no page permission: 403, not their project: 404)
        $outsider = $this->user('member', Department::create(['name' => 'อื่น', 'code' => 'OTH', 'is_active' => true]));
        $this->assertContains($this->actingAs($outsider)->getJson(route('reports.projects.tree', $project))->status(), [403, 404]);
    }

    public function test_the_feed_is_newest_first(): void
    {
        $this->dayOfWork();
        $times = array_column(app(DailyWork::class)->build(null, today())['feed'], 'time');

        $this->assertSame(['11:15', '10:00', '09:45', '09:00', '08:10'], $times);
    }

    public function test_cabinets_that_came_in(): void
    {
        $this->dayOfWork();
        $this->travelTo(Carbon::parse('2026-10-21 13:20'));
        $newProjectTask = $this->taskIn($this->project('SO-NEW'), 3);   // 3 ตู้, with Production's work in it
        $this->subtask($this->production, 'ASSIGNED', ['assigned_at' => '2026-10-21 13:25'], $newProjectTask);
        $this->travelTo(Carbon::parse('2026-10-21 13:40'));
        $this->taskIn(Project::where('project_no', 'SO-OPEN')->first(), 1);   // no work dispatched in it yet
        $this->travelTo(Carbon::parse('2026-10-21 14:00'));

        $summary = app(DailyWork::class)->build(null, today());
        $this->assertSame([4, 2, 2], [$summary['totals']['newCabinetQuantity'], $summary['totals']['newCabinetCount'], $summary['totals']['newCabinetProjectCount']]);
        $newCabinetEvents = array_values(array_filter($summary['feed'], fn ($event) => $event['type'] === DailyWork::EVENT_NEW_CABINET));
        $this->assertSame(['13:40', '13:20'], array_column($newCabinetEvents, 'time'));
        $this->assertSame(['Cab', '3 ตู้ · MO-SO-NEW', 'P SO-NEW', null], [$newCabinetEvents[1]['title'], $newCabinetEvents[1]['detail'], $newCabinetEvents[1]['projectName'], $newCabinetEvents[1]['departmentId']]);
        $this->assertSame(route('cabinets.show', $newProjectTask->cabinet_id), $newCabinetEvents[1]['url'], 'a new cabinet opens at its top');
        $this->assertSame(0, app(DailyWork::class)->build(null, Carbon::parse('2026-10-20'))['totals']['newCabinetQuantity']);

        // a busy day does not push them out of the feed
        foreach (range(1, DailyWork::FEED_WORK_LIMIT + 5) as $extraTask) {
            $this->subtask($this->production, 'ASSIGNED', ['assigned_at' => '2026-10-21 13:50']);
        }
        $summary = app(DailyWork::class)->build(null, today());
        $feed = $summary['feed'];
        $this->assertCount(DailyWork::FEED_WORK_LIMIT + 2, $feed);
        $this->assertSame(DailyWork::FEED_WORK_LIMIT + 5 + 6, $summary['workEventCount'], 'the page says how many it left out');
        $this->assertSame(['13:40', '13:20'], array_column(array_values(array_filter($feed, fn ($event) => $event['type'] === DailyWork::EVENT_NEW_CABINET)), 'time'));

        // a department sees the cabinets it has work in
        $this->assertSame(3, app(DailyWork::class)->build([$this->production->id], today())['totals']['newCabinetQuantity']);
        $this->assertSame(0, app(DailyWork::class)->build([$this->shipping->id], today())['totals']['newCabinetQuantity']);
        $this->assertSame(0, app(DailyWork::class)->build([], today())['totals']['newCabinetQuantity']);
    }

    public function test_an_earlier_day(): void
    {
        $this->dayOfWork();
        $summary = app(DailyWork::class)->build(null, Carbon::parse('2026-10-20'));
        $shippingRow = collect($summary['departments'])->firstWhere('id', $this->shipping->id);

        $this->assertSame([false, false, 1], [$summary['range']['isToday'], $summary['range']['isLive'], $summary['range']['dayCount']]);
        $this->assertSame([['from' => '2026-10-19', 'to' => '2026-10-19'], ['from' => '2026-10-21', 'to' => '2026-10-21']], [$summary['range']['previous'], $summary['range']['next']]);
        $this->assertSame('วันอังคารที่ 20 ต.ค. 2569', $summary['range']['label']);
        $this->assertSame(1, $shippingRow['completed']);
        $this->assertNull(app(DailyWork::class)->build(null, today())['range']['next'], 'no tomorrow');
    }

    public function test_a_range_of_days(): void
    {
        $work = $this->dayOfWork();
        $summary = app(DailyWork::class)->build(null, Carbon::parse('2026-10-15'), today());
        $departmentRows = collect($summary['departments'])->keyBy('id');

        $this->assertSame(['2026-10-15', '2026-10-21', 7, true, false], [$summary['range']['from'], $summary['range']['to'], $summary['range']['dayCount'], $summary['range']['isLive'], $summary['range']['isToday']]);
        $this->assertSame('15 – 21 ต.ค. 2569', $summary['range']['label']);
        $this->assertSame(['from' => '2026-10-08', 'to' => '2026-10-14'], $summary['range']['previous']);
        $this->assertNull($summary['range']['next']);

        // over the week: Production got its 3 (18th, 20th, 21st) and finished 1; shipping got 2 and finished 3, done once each
        $this->assertSame([3, 1], [$departmentRows[$this->production->id]['received'], $departmentRows[$this->production->id]['completed']]);
        $this->assertSame([2, 3], [$departmentRows[$this->shipping->id]['received'], $departmentRows[$this->shipping->id]['completed']]);
        $this->assertSame(['received' => 5, 'completed' => 4], array_intersect_key($summary['totals'], array_flip(['received', 'completed'])));

        // the chart is the range itself, a bar a day
        $shippingChart = $departmentRows[$this->shipping->id]['chart'];
        $this->assertSame(['2026-10-15', '2026-10-21'], [$shippingChart[0]['date'], end($shippingChart)['date']]);
        $this->assertSame([2, 0, 0, 0, 0, 0, 0], array_column($shippingChart, 'received'));
        $this->assertSame([0, 0, 0, 0, 0, 1, 2], array_column($shippingChart, 'completed'));
        $this->assertSame('รายวัน', $summary['range']['chartLabel']);

        // the feed says which day
        $feed = collect($summary['feed'])->keyBy('key');
        $this->assertSame(['20 ต.ค.', '16:00'], [$feed['completed-'.$work['completedYesterday']->id]['date'], $feed['completed-'.$work['completedYesterday']->id]['time']]);
        $this->assertNull(app(DailyWork::class)->build(null, today())['feed'][0]['date'], 'one day: no date on each line');

        // longer ranges: a bar a week, then a month
        $this->assertSame('รายสัปดาห์', app(DailyWork::class)->build(null, Carbon::parse('2026-08-01'), today())['range']['chartLabel']);
        $yearSummary = app(DailyWork::class)->build(null, Carbon::parse('2025-11-01'), today());
        $this->assertSame(['รายเดือน', 12, '28 ธ.ค. 2568 – 5 ม.ค. 2569'], [$yearSummary['range']['chartLabel'], count($yearSummary['departments'][0]['chart']),
            (new \ReflectionMethod(DailyWork::class, 'rangeLabel'))->invoke(app(DailyWork::class), Carbon::parse('2025-12-28'), Carbon::parse('2026-01-05'))]);
    }

    public function test_a_department_sees_only_its_own_work_however_it_asks(): void
    {
        $this->dayOfWork();
        $member = $this->user('member', $this->production);

        $summaryJson = $this->actingAs($member)->getJson(route('reports.today.data'))->assertOk()->json();
        $this->assertSame([$this->production->id], array_column($summaryJson['departments'], 'id'));
        $this->assertSame([$this->production->id], array_values(array_unique(array_column($summaryJson['feed'], 'departmentId'))));
        $this->assertSame(1, $summaryJson['totals']['received']);

        $this->actingAs($member)->get(route('reports.today'))->assertOk();

        $projectManager = $this->user('project_manager');
        $departmentIds = array_column($this->actingAs($projectManager)->getJson(route('reports.today.data'))->json('departments'), 'id');
        $this->assertEqualsCanonicalizing([$this->production->id, $this->shipping->id], $departmentIds);

        $this->actingAs($this->user('member'))->get(route('reports.today'))->assertOk()->assertSee('ยังไม่ได้อยู่ในแผนกใด');
    }

    public function test_new_work_is_flagged_until_someone_opens_it(): void
    {
        $work = $this->dayOfWork();
        $work['seen'] = $this->subtask($this->production, 'ASSIGNED', ['assigned_at' => '2026-10-21 09:10', 'is_active' => true]);
        $closedWork = CabinetSubtask::where('cabinet_task_id', $this->closedTask->id)->where('assignment_status', 'ASSIGNED')->first();

        // Production holds 3 pending, 2 not opened yet; a closed project's work is not new work
        $summary = app(DailyWork::class)->build(null, today());
        $productionRow = collect($summary['departments'])->firstWhere('id', $this->production->id);
        $this->assertSame([2, 0, 2], [$productionRow['unacknowledged'], collect($summary['departments'])->firstWhere('id', $this->shipping->id)['unacknowledged'], $summary['totals']['unacknowledged']]);
        $this->assertSame(['new-'.$work['receivedToday']->id, 'new-'.$work['overdue']->id], array_column($summary['newWork'], 'key'), 'newest first');
        $this->assertSame(['21 ต.ค.', true, $work['receivedToday']->id], [$summary['newWork'][0]['date'], $summary['newWork'][0]['isNew'], $summary['newWork'][0]['subtaskId']]);
        $this->assertStringEndsWith('#goto-subtask-'.$work['receivedToday']->id, $summary['newWork'][0]['url']);
        $feed = collect($summary['feed'])->keyBy('key');
        $this->assertSame([true, false], [$feed['received-'.$work['receivedToday']->id]['isNew'], $feed['received-'.$work['seen']->id]['isNew']]);

        // another department, or a closed project: not marked
        $shippingMember = $this->user('member', $this->shipping);
        $this->actingAs($shippingMember)->postJson(route('reports.today.acknowledge'), ['subtask_id' => $work['receivedToday']->id])->assertNotFound();
        $this->actingAs($this->user('admin'))->postJson(route('reports.today.acknowledge'), ['subtask_id' => $closedWork->id])->assertNotFound();
        $this->assertFalse((bool) $work['receivedToday']->fresh()->is_active);
        $this->assertFalse((bool) $closedWork->fresh()->is_active);

        // its own department opens it: seen for everyone, nothing else touched
        $updatedAt = $work['receivedToday']->fresh()->updated_at;
        $this->travel(5)->minutes();
        $this->actingAs($this->user('member', $this->production))->postJson(route('reports.today.acknowledge'), ['subtask_id' => $work['receivedToday']->id])
            ->assertOk()->assertJson(['acknowledged' => true]);
        $this->assertTrue((bool) $work['receivedToday']->fresh()->is_active);
        $this->assertEquals($updatedAt, $work['receivedToday']->fresh()->updated_at);

        $summary = app(DailyWork::class)->build(null, today());
        $this->assertSame([1, ['new-'.$work['overdue']->id]], [$summary['totals']['unacknowledged'], array_column($summary['newWork'], 'key')]);
        $this->assertFalse(collect($summary['feed'])->firstWhere('key', 'received-'.$work['receivedToday']->id)['isNew']);
        $this->assertSame([], app(DailyWork::class)->build([], today())['newWork']);

        // listed per department: a busy one does not push the others' out
        $shippingWork = $this->subtask($this->shipping, 'ASSIGNED', ['assigned_at' => '2026-10-01 09:00']);
        foreach (range(1, DailyWork::NEW_WORK_LIMIT + 3) as $extraTask) {
            $this->subtask($this->production, 'ASSIGNED', ['assigned_at' => '2026-10-21 13:00']);
        }
        $summary = app(DailyWork::class)->build(null, today());
        $listed = collect($summary['newWork'])->countBy('departmentId');
        $this->assertSame([DailyWork::NEW_WORK_LIMIT, 1], [$listed[$this->production->id], $listed[$this->shipping->id]]);
        $this->assertSame(DailyWork::NEW_WORK_LIMIT + 4, collect($summary['departments'])->firstWhere('id', $this->production->id)['unacknowledged'], 'the badge counts them all');
        $this->assertContains('new-'.$shippingWork->id, array_column($summary['newWork'], 'key'));
    }

    public function test_the_page_the_date_and_the_permission(): void
    {
        $this->dayOfWork();
        $admin = $this->user('admin');

        $this->actingAs($admin)->get(route('reports.today'))->assertOk()
            ->assertSee('สรุปงาน')->assertSee('วันพุธที่ 21 ต.ค. 2569');
        $this->get(route('dashboard'))->assertSee(route('reports.today'), false);   // in the menu

        $requestedRange = fn (array $query) => array_intersect_key($this->getJson(route('reports.today.data', $query))->assertOk()->json('range'), array_flip(['from', 'to']));
        $this->assertSame(['from' => '2026-10-20', 'to' => '2026-10-20'], $requestedRange(['date' => '2026-10-20']));
        $this->assertSame(['from' => '2026-10-21', 'to' => '2026-10-21'], $requestedRange(['date' => '2026-11-30']));   // not ahead of today
        $this->assertSame(['from' => '2026-10-21', 'to' => '2026-10-21'], $requestedRange(['date' => '2026-02-31']));
        $this->assertSame(['from' => '2026-10-21', 'to' => '2026-10-21'], $requestedRange(['date' => 'x']));
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-21'], $requestedRange(['from' => '2026-10-01', 'to' => '2026-12-01']));
        $this->assertSame(['from' => '2026-10-01', 'to' => '2026-10-05'], $requestedRange(['from' => '2026-10-05', 'to' => '2026-10-01']), 'backwards is turned round');
        $this->assertSame(['from' => '2026-10-05', 'to' => '2026-10-05'], $requestedRange(['from' => '2026-10-05', 'to' => 'nope']));
        $this->assertSame(['from' => '2025-10-21', 'to' => '2026-10-21'], $requestedRange(['from' => '2020-01-01', 'to' => '2026-10-21']), 'at most a year');
        $this->actingAs($admin)->get(route('reports.today', ['from' => '2026-10-15', 'to' => '2026-10-21']))->assertOk()->assertSee('15 – 21 ต.ค. 2569');

        AppSetting::put('department_permissions', [(string) $this->production->id => ['page.today' => false]]);
        $member = $this->user('member', $this->production);
        $this->actingAs($member)->get(route('reports.today'))->assertForbidden();
        $this->getJson(route('reports.today.data'))->assertForbidden();
        $this->get(route('notifications.index'))->assertDontSee(route('reports.today'), false);

        auth()->logout();
        $this->getJson(route('reports.today.data'))->assertUnauthorized();   // the page then says "หมดเวลาเข้าสู่ระบบ"
    }
}
