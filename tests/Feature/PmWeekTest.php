<?php

namespace Tests\Feature;

use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\Department;
use App\Models\JobType;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\ProjectFat;
use App\Models\Team;
use App\Models\User;
use App\Services\ExecutiveReport;
use App\Services\PmWeek;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The dashboard's "สัปดาห์นี้" view (App\Services\PmWeek): this week, behind plan, stuck - where, who, by when - and next week. */
class PmWeekTest extends TestCase
{
    use RefreshDatabase;

    private Department $busbar;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 18)->setTime(9, 0));   // a Wednesday: this week 16-22 Nov, next 23-29 Nov
        $this->busbar = Department::create(['name' => 'Busbar', 'code' => 'BB', 'is_active' => true]);
        $this->pm = User::forceCreate(['name' => 'Somchai', 'email' => 'pm-week', 'password' => 'secret-pass', 'role' => 'project_manager', 'is_active' => true]);
    }

    private function project(string $no, string $due, ?User $pm = null): Project
    {
        return Project::forceCreate(['project_no' => $no, 'project_name' => 'P '.$no, 'customer_name' => 'C', 'status' => 'in_progress', 'priority' => 'normal',
            'start_date' => '2026-10-01', 'due_date' => $due, 'project_manager_id' => ($pm ?? $this->pm)->id]);
    }

    private function cabinet(Project $p, string $mo, array $subtask = [], string $status = 'in_progress'): Cabinet
    {
        $c = Cabinet::forceCreate(['project_id' => $p->id, 'mo_no' => $mo, 'cabinet_name' => $mo, 'quantity' => 1, 'status' => $status, 'progress' => $status === 'completed' ? 100 : 20, 'due_date' => $p->due_date]);
        $t = CabinetTask::forceCreate(['cabinet_id' => $c->id, 'name' => '1. ประกอบ', 'status' => 'in_progress', 'progress' => 0, 'sequence' => 0]);
        if ($subtask) {
            CabinetSubtask::forceCreate($subtask + ['cabinet_task_id' => $t->id, 'name' => '1.1 Busbar', 'status' => 'in_progress', 'progress' => 0]);
        }

        return $c;
    }

    public function test_it_answers_this_week_stuck_who_and_by_when_and_next_week(): void
    {
        $operator = User::forceCreate(['name' => 'Wichai', 'email' => 'op', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $this->busbar->id, 'is_active' => true]);
        $p = $this->project('A', '2026-11-27');
        // late Busbar work, accepted by Wichai; the cabinet's FAT is on Friday, so that is when it must be done by
        $c = $this->cabinet($p, 'MO-1', ['department_id' => $this->busbar->id, 'assignment_status' => 'ACCEPTED', 'accepted_by' => $operator->id, 'due_date' => '2026-11-13']);
        ProjectFat::forceCreate(['project_id' => $p->id, 'round' => 1, 'fat_date' => '2026-11-20', 'status' => ProjectFat::SCHEDULED, 'cabinet_ids' => [$c->id]]);
        ProjectDelivery::forceCreate(['project_id' => $p->id, 'round' => 1, 'delivery_date' => '2026-11-26', 'status' => ProjectDelivery::DRAFT, 'cabinet_ids' => [$c->id]]);
        // handed to Busbar four days ago and nobody accepted it
        $b = $this->project('B', '2026-12-20');
        $this->cabinet($b, 'MO-2', ['department_id' => $this->busbar->id, 'assignment_status' => 'ASSIGNED', 'assigned_at' => now()->subDays(4), 'due_date' => '2026-11-25']);

        // every cabinet delivered last week: not among the other projects being made
        $sent = $this->project('C', '2026-12-30');
        $cc = $this->cabinet($sent, 'MO-3', ['department_id' => $this->busbar->id, 'due_date' => '2026-12-01']);
        ProjectDelivery::forceCreate(['project_id' => $sent->id, 'round' => 1, 'delivery_date' => '2026-11-10', 'status' => ProjectDelivery::DELIVERED, 'cabinet_ids' => [$cc->id]]);

        // its delivery items, ticked by งานจัดส่ง: packed on Monday, not billed yet
        $sid = CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $c->id))->value('id');
        CabinetChecklist::forceCreate(['cabinet_subtask_id' => $sid, 'name' => 'ห่อตู้ เสร็จสิ้น', 'is_completed' => true, 'completed_at' => '2026-11-16 15:20', 'sequence' => 1]);
        CabinetChecklist::forceCreate(['cabinet_subtask_id' => $sid, 'name' => 'แจ้งเปิดบิล', 'is_completed' => false, 'sequence' => 2]);

        $r = app(PmWeek::class)->build($this->pm);

        $this->assertSame('16–22 พ.ย. 2569', $r['week']['label']);
        $fat = collect($r['week']['events'])->firstWhere('kind', 'fat');
        $this->assertSame(['FAT รอบ 1', 'ตู้ยังไม่เสร็จ 1/1', 'red'], [$fat['title'], $fat['status'], $fat['tone']], 'two days ahead or less: red');
        $this->assertSame('2026-11-20', $fat['date']->format('Y-m-d'));

        $stuck = collect($r['stuck']);
        $late = $stuck->firstWhere('kind', 'late');
        $this->assertSame(['Busbar', 'Wichai', 'FAT รอบ 1', '20 พ.ย. · อีก 2 วัน'], [$late['place'], $late['who'], $late['deadline']['label'], $late['deadline']['text']]);
        $this->assertStringContainsString('เลยมา 5 วัน', $late['what']);
        $waiting = $stuck->firstWhere('kind', 'waiting');
        $this->assertSame(['Busbar', 'ยังไม่มีคนรับงาน', 'กำหนดงาน'], [$waiting['place'], $waiting['who'], $waiting['deadline']['label']]);
        $this->assertSame([['Busbar', 2]], array_map(fn ($x) => [$x['place'], $x['n']], $r['stuckPlaces']));

        // each project's state comes from ExecutiveReport's rule, made from the projects already loaded: the same lines
        $loaded = Project::with(['projectManager', 'cabinets.tasks.subtasks.department'])->withAvg('cabinets', 'progress')->get();
        $this->assertEquals(collect(app(ExecutiveReport::class)->build($this->pm)['projects'])->keyBy('id')->sortKeys()->all(),
            app(ExecutiveReport::class)->rows($loaded, today())->sortKeys()->all());

        // the Kanban (จ. - ศ.): Friday holds A's FAT; its card says the round's cabinets, its panel where A is stuck and by when
        $friday = $r['board']['days'][4];
        $fat = collect($friday['items'])->firstWhere('kind', 'fat');
        $this->assertSame(['P A', 'ตู้ยังไม่พร้อม', 0, 1, 'ศ. 20 พ.ย.'], [$fat['name'], $fat['ev'], $fat['done'], $fat['total'], $fat['when']]);
        $this->assertSame(['Busbar', 'Wichai', '20 พ.ย. · อีก 2 วัน · FAT รอบ 1'], [$fat['issues'][0]['place'], $fat['issues'][0]['who'], $fat['issues'][0]['by']]);
        // the panel lists the round's cabinets, each with its Tasks
        $this->assertSame(['MO-1', ['1. ประกอบ']], [$fat['cabinetList'][0]['mo'], array_column($fat['cabinetList'][0]['tasks'], 'name')]);
        $this->assertTrue($fat['cabinetList'][0]['tasks'][0]['late'], 'its Busbar work is past due');
        // the panel says the cabinet is packed, and lists its delivery steps as a checklist (with when)
        $this->assertSame(['ห่อแล้ว', true], [$fat['cabinetList'][0]['stage'], $fat['cabinetList'][0]['packed']]);
        $this->assertSame([['label' => 'ห่อ', 'done' => true, 'at' => '16 พ.ย. 15:20'], ['label' => 'แจ้งเปิดบิล', 'done' => false, 'at' => null]], $fat['cabinetList'][0]['steps']);
        $this->assertSame($fat['cabinetList'][0]['steps'], $fat['cabinetList'][0]['tasks'][0]['ship'], 'and under the Task that holds them');
        $this->assertSame(['pack' => ['done' => true, 'at' => '16/11'], 'bill' => ['done' => false, 'at' => null], 'depart' => null, 'deliver' => null],
            $fat['cabinetList'][0]['shipMap'], 'the table: packed 16/11, not billed, no items for the last two');
        $this->assertSame([1, true], [$fat['cabinetList'][0]['round'], $fat['cabinetList'][0]['roundDraft']], 'in round 1, still a draft');
        $this->assertSame(['A', null, null], [$fat['no'], $fat['sales'], $fat['site']], 'the SO number on the card; no sales person or site given');
        $this->assertSame('23–27 พ.ย. 2569', app(PmWeek::class)->build($this->pm, offset: 1)['board']['label'], 'the › button: next week');

        // the panel's overview: both projects run, the Busbar work to chase first (its FAT is Friday)
        $o = $r['overview'];
        $this->assertSame([3, 0], [$o['running'], $o['notStarted']]);
        $this->assertSame([], array_column($o['others'], 'name'), 'A is on the board, B waits in รอแจ้งวันส่ง (no round yet), C is sent');
        $this->assertSame(['ยังไม่นัดส่ง · จบโครงการ อา. 20 ธ.ค.'], array_column(array_filter($r['board']['pending'], fn ($i) => $i['no'] === 'B'), 'when'));
        $this->assertSame(['P A', 'Busbar · Wichai', '20 พ.ย. · อีก 2 วัน'], [$o['urgent'][0]['project'], $o['urgent'][0]['who'], $o['urgent'][0]['by']]);

    }

    public function test_a_project_is_a_work_card_on_its_delivery_day_coloured_by_its_tasks_with_its_job_type(): void
    {
        // today is Wednesday 18; the card goes on the first delivery day (no round yet: the project's due date), its colour
        // from its Tasks' due dates (red past one, orange within two days), green its Checklist done, pink packed or gone
        $due = fn (string $no, string $date, string $status = 'in_progress') => $this->cabinet($this->project($no, $date), 'MO-'.$no, [], $status);
        $round = fn (Cabinet $c, string $day, string $status = ProjectDelivery::SCHEDULED, int $n = 1) => ProjectDelivery::forceCreate(
            ['project_id' => $c->project_id, 'round' => $n, 'delivery_date' => $day, 'status' => $status, 'cabinet_ids' => [$c->id]]);
        $taskDue = fn (Cabinet $c, string $day) => CabinetTask::where('cabinet_id', $c->id)->update(['due_date' => $day]);
        $old = $due('OLD', '2026-11-10');   // delivers last week, not made: on today; a Task past its due date: red
        $taskDue($old, '2026-11-17');
        $round($old, '2026-11-10');
        $today = $due('TODAY', '2026-11-18');   // delivers today, a Task due today: orange
        $taskDue($today, '2026-11-18');
        $round($today, '2026-11-18');
        $soon = $due('SOON', '2026-11-20');               // a Task due in two days: orange - on its first round's day, Thursday
        $taskDue($soon, '2026-11-20');
        // its Checklist done, not packed: green (พร้อมห่อ)
        $ready = $this->cabinet($this->project('READY', '2026-11-30'), 'MO-READY', ['department_id' => $this->busbar->id]);
        CabinetChecklist::forceCreate(['cabinet_subtask_id' => CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $ready->id))->value('id'), 'name' => 'ประกอบ Busbar', 'is_completed' => true, 'sequence' => 0]);
        $round($ready, '2026-11-17');
        $round($soon, '2026-11-25', ProjectDelivery::WAITING, 2);
        $round($soon, '2026-11-19', ProjectDelivery::WAITING);
        $soon->project->forceFill(['job_type_id' => JobType::firstOrCreate(['name' => 'ตู้'])->id])->save();
        $later = $due('LATER', '2026-11-21');   // delivers Saturday (Friday's column), on plan: grey
        $later->project->forceFill(['start_date' => '2026-11-17'])->save();
        $round($later, '2026-11-21');
        // no delivery date: no round yet, and a round with no date - both wait in รอแจ้งวันส่ง
        $due('NOROUND', '2026-11-19');
        ProjectDelivery::forceCreate(['project_id' => $due('NODATE', '2026-11-19')->project_id, 'round' => 1, 'delivery_date' => '2026-11-19', 'date_pending_at' => '2026-11-15 10:00', 'status' => ProjectDelivery::WAITING, 'cabinet_ids' => []]);
        $round($due('BEHIND', '2026-11-28'), '2026-11-20');   // delivers Friday, behind its time but no Task due: grey
        $round($due('MADE', '2026-11-24', 'completed'), '2026-11-17');   // all done (built and packed): pink
        $round($due('OUT', '2026-11-16', 'completed'), '2026-11-17', ProjectDelivery::DEPARTED);   // gone out of the factory: pink
        $round($due('GONE', '2026-11-12'), '2026-11-12', ProjectDelivery::DEPARTED);   // went out last week, its cabinets not ticked done: not carried to today
        $round($due('NEXT', '2026-11-19'), '2026-11-26');  // delivers next week: not on this week's board, though it ends this week
        $put = $due('PUT', '2026-11-12');                  // put off, no new dates yet (รอฝ่ายขายแจ้งกำหนดส่ง): on no day, listed apart
        $round($put, '2026-11-12', ProjectDelivery::DRAFT);
        $put->project->forceFill(['due_pending_at' => '2026-11-12 10:00'])->save();
        $fp = $this->project('F', '2026-12-31');
        $c = $this->cabinet($fp, 'MO-F');
        ProjectFat::forceCreate(['project_id' => $fp->id, 'round' => 1, 'fat_date' => '2026-11-23', 'status' => ProjectFat::SCHEDULED, 'cabinet_ids' => [$c->id]]);

        $board = app(PmWeek::class)->build($this->pm)['board'];
        // a card: its colour, its chip - where the work is - and beside it its colour's words
        $cards = fn (int $day) => array_map(fn ($i) => [$i['no'], $i['tag'], $i['kindName'], $i['ev']], $board['days'][$day]['items']);

        $this->assertSame([], $cards(0));
        $this->assertSame([['READY', 'green', 'พร้อมห่อ', ''], ['OUT', 'shipped', 'ออกจากโรงงานแล้ว', ''], ['MADE', 'shipped', 'พร้อมส่ง', '']], $cards(1));
        $this->assertSame([['OLD', 'red', 'กำลังผลิต', 'เกินกำหนด'], ['TODAY', 'orange', 'กำลังผลิต', 'ใกล้ครบกำหนด']], $cards(2));
        $this->assertSame([['SOON', 'orange', 'กำลังผลิต', 'ใกล้ครบกำหนด']], $cards(3), 'the first of its two rounds');
        $this->assertEqualsCanonicalizing([['BEHIND', 'gray', 'กำลังผลิต', ''], ['LATER', 'gray', 'กำลังผลิต', '']], $cards(4));
        $this->assertSame([null, null], array_column($board['days'][4]['items'], 'why'), 'behind the time used is not said: the card shows the production %');
        $this->assertSame([20, 20], array_column($board['days'][4]['items'], 'made'));
        $this->assertEquals(['red' => 1, 'orange' => 2, 'fat' => 0, 'gray' => 2, 'green' => 1, 'shipped' => 2], $board['tags']);

        $s = $board['days'][3]['items'][0];
        // each cabinet says which round it is in (its first) and where that round stands for it: waiting, nothing done yet
        $this->assertSame([1, false], [$s['cabinetList'][0]['round'], $s['cabinetList'][0]['roundDraft']]);
        $this->assertSame([['ห่อ', false], ['เปิดบิล', false], ['ออกรถ', false], ['ส่งถึง', false]],
            array_map(fn ($x) => [$x['label'], $x['done']], $s['cabinetList'][0]['roundShip']));
        // a round gone out of the factory: its cabinet shows ออกรถ done, not yet ส่งถึง
        $out = collect($board['days'][1]['items'])->firstWhere('no', 'OUT');
        $this->assertSame([false, false, true, false], array_column($out['cabinetList'][0]['roundShip'], 'done'));
        $this->assertSame(['ตู้', 'ส่ง พฤ. 19 พ.ย. · จบโครงการ ศ. 20 พ.ย.', 'รอจัดรถ · พฤ. 19 พ.ย.'], [$s['jobType'], $s['when'], $s['ship']]);
        $this->assertSame([null, 'ส่ง อ. 10 พ.ย. · จบโครงการ อ. 10 พ.ย.'], [$board['days'][2]['items'][0]['jobType'], $board['days'][2]['items'][0]['when']]);
        $this->assertEqualsCanonicalizing([
            ['PUT', 'pending', 'รอเซลแจ้งวันส่ง', 'เลื่อนเมื่อ 12 พ.ย. · ยังไม่มีวันส่งใหม่'],
            ['NOROUND', 'pending', 'ยังไม่นัดส่ง', 'ยังไม่นัดส่ง · จบโครงการ พฤ. 19 พ.ย.'],
            ['NODATE', 'pending', 'รอบ 1 ยังไม่ลงวันส่ง', 'รอบ 1 ยังไม่ลงวันส่ง · จบโครงการ พฤ. 19 พ.ย.'],
            ['F', 'pending', 'ยังไม่นัดส่ง', 'ยังไม่นัดส่ง · จบโครงการ พฤ. 31 ธ.ค.'],   // a FAT only: its work has no delivery date yet
        ], array_map(fn ($i) => [$i['no'], $i['tag'], $i['ev'], $i['when']], $board['pending']), 'no delivery date (put off, no round, a round with no date): apart, on no day');
        $put = collect($board['pending'])->firstWhere('no', 'PUT');
        $this->assertSame(['pending', 'รอเซลแจ้งวันส่ง'], [$put['state'], $put['stateText']], 'its state: waiting on sales, not late');
        $this->assertSame([], app(PmWeek::class)->build($this->pm, offset: -1)['board']['pending'], 'only the week of today lists it');
        $this->assertSame(['OLD'], array_column(app(PmWeek::class)->build($this->pm, offset: -1)['board']['days'][1]['items'], 'no'), 'last week shown: on its own day');
        $next = app(PmWeek::class)->build($this->pm, offset: 1)['board']['days'];
        $this->assertSame([['F', 'fat'], ['NEXT', 'gray']], [[$next[0]['items'][0]['no'], $next[0]['items'][0]['tag']], [$next[3]['items'][0]['no'], $next[3]['items'][0]['tag']]],
            'FAT keeps its card; NEXT is on its delivery day, grey: the project ends tomorrow but no Task is due');
    }

    public function test_a_slipped_fat_and_a_sent_back_delivery_wait_on_the_pm(): void
    {
        $p = $this->project('C', '2026-12-10');
        $this->cabinet($p, 'MO-3');
        ProjectFat::forceCreate(['project_id' => $p->id, 'round' => 1, 'fat_date' => '2026-11-10', 'status' => ProjectFat::SCHEDULED]);
        ProjectDelivery::forceCreate(['project_id' => $p->id, 'round' => 1, 'delivery_date' => '2026-11-21', 'status' => ProjectDelivery::REJECTED, 'rejected_reason' => 'รถไม่ว่าง', 'cabinet_ids' => []]);

        $stuck = collect(app(PmWeek::class)->build($this->pm)['stuck']);

        $this->assertSame(['FAT รอบ 1 (10 พ.ย.) ผ่านไปแล้ว ยังไม่บันทึกผล', 'PM Somchai', 'วันนี้'], [$stuck[0]['what'], $stuck[0]['who'], $stuck[0]['deadline']['text']]);
        $this->assertSame('ฝ่ายจัดส่งตีกลับ ส่งมอบรอบ 1 · รถไม่ว่าง', $stuck[1]['what']);
    }

    public function test_two_delivery_rounds_are_two_cards_each_of_its_own_cabinets(): void
    {
        $p = $this->project('TWO', '2026-11-30');
        $a = $this->cabinet($p, 'MO-A');
        $b = $this->cabinet($p, 'MO-B', [], 'completed');
        $this->cabinet($p, 'MO-C');   // in no round: waits in รอแจ้งวันส่ง
        ProjectDelivery::forceCreate(['project_id' => $p->id, 'round' => 1, 'delivery_date' => '2026-11-17', 'status' => ProjectDelivery::WAITING, 'cabinet_ids' => [$a->id]]);
        ProjectDelivery::forceCreate(['project_id' => $p->id, 'round' => 2, 'delivery_date' => '2026-11-20', 'status' => ProjectDelivery::SCHEDULED, 'cabinet_ids' => [$b->id]]);

        $board = app(PmWeek::class)->build($this->pm)['board'];
        $card = fn (int $day) => collect($board['days'][$day]['items'])->firstWhere('no', 'TWO');

        $this->assertSame([1, ['MO-A'], 1, 0, 20, 'gray'], [$card(1)['round'], $card(1)['roundCabinets'], $card(1)['total'], $card(1)['done'], $card(1)['made'], $card(1)['tag']], 'round 1 on Tuesday: MO-A only');
        $this->assertSame([2, ['MO-B'], 1, 1, 100, 'shipped'], [$card(4)['round'], $card(4)['roundCabinets'], $card(4)['total'], $card(4)['done'], $card(4)['made'], $card(4)['tag']], 'round 2 on Friday: MO-B only, done');
        $this->assertSame(['1 ตู้ยังไม่อยู่ในรอบส่ง · จบโครงการ จ. 30 พ.ย.', 1], [collect($board['pending'])->firstWhere('no', 'TWO')['when'], collect($board['pending'])->firstWhere('no', 'TWO')['total']]);
    }

    public function test_a_card_says_the_team_of_its_sales_person(): void
    {
        $salesDept = Department::create(['name' => 'ขาย', 'code' => 'SL', 'is_active' => true]);
        $team = Team::forceCreate(['department_id' => $salesDept->id, 'name' => 'ทีม A']);
        $sales = User::forceCreate(['name' => 'Maethika', 'email' => 'sales-a', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $salesDept->id, 'team_id' => $team->id, 'is_active' => true]);
        $t1 = $this->cabinet($this->project('T1', '2026-11-20'), 'MO-T1');
        $t1->project->forceFill(['sales_person_id' => $sales->id])->save();
        $t2 = $this->cabinet($this->project('T2', '2026-11-20'), 'MO-T2');   // no sales person: no team
        foreach ([$t1, $t2] as $c) {   // both delivered on Friday
            ProjectDelivery::forceCreate(['project_id' => $c->project_id, 'round' => 1, 'delivery_date' => '2026-11-20', 'status' => ProjectDelivery::WAITING, 'cabinet_ids' => [$c->id]]);
        }

        $items = collect(app(PmWeek::class)->build($this->pm)['board']['days'][4]['items'])->keyBy('no');

        $this->assertSame(['Maethika', 'ทีม A'], [$items['T1']['sales'], $items['T1']['team']]);
        $this->assertNull($items['T2']['team']);
        $this->actingAs($this->pm)->get(route('dashboard', ['view' => 'pm']))->assertOk()->assertSee('ทีม A');
    }

    public function test_mine_keeps_only_my_projects_and_the_view_is_for_pmo_only(): void
    {
        $other = User::forceCreate(['name' => 'Other', 'email' => 'pm-2', 'password' => 'secret-pass', 'role' => 'project_manager', 'is_active' => true]);
        $mineProject = $this->project('MINE', '2026-11-20');
        ProjectFat::forceCreate(['project_id' => $mineProject->id, 'round' => 1, 'fat_date' => '2026-11-20', 'status' => ProjectFat::SCHEDULED, 'cabinet_ids' => []]);
        $this->project('THEIRS', '2026-11-21', $other);

        $all = app(PmWeek::class)->build($this->pm);
        $mine = app(PmWeek::class)->build($this->pm, mine: true);
        $this->assertSame([2, 1, 1], [$all['projectCount'], $mine['projectCount'], $mine['myProjects']]);

        $this->actingAs($this->pm)->get(route('dashboard'))->assertOk()->assertViewHas('view', 'pm')
            ->assertSeeInOrder(['16–20 พ.ย. 2569', 'เกินกำหนด', 'ใกล้ครบกำหนด', 'FAT', 'ตามแผน', 'พร้อมห่อ', 'ห่อตู้แล้ว', 'จ.', 'ศ.', 'P MINE'])
            // the parts the week buttons swap in (resources/js/pm-week.js), and the links they take over
            ->assertSee(['id="pm-board"', 'id="pm-bar"', 'data-pm-nav="prev"', 'data-pm-nav="next"', 'data-pm-nav="mine"'], false);
        $this->actingAs($this->pm)->get(route('dashboard', ['view' => 'pm', 'mine' => 1]))->assertOk()->assertDontSee('P THEIRS');

        $member = User::forceCreate(['name' => 'M', 'email' => 'm', 'password' => 'secret-pass', 'role' => 'member', 'department_id' => $this->busbar->id, 'is_active' => true]);
        $this->actingAs($member)->get(route('dashboard', ['view' => 'pm']))->assertOk()->assertViewHas('view', 'dept')->assertViewHas('pm', null)->assertDontSee('id="pm-board"', false);
    }
}
