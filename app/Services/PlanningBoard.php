<?php

namespace App\Services;

use App\Models\BoardLabel;
use App\Models\Cabinet;
use App\Models\DepartmentField;
use App\Models\ProjectDepartmentStatus;
use App\Models\ProjectFieldValue;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\Project;
use App\Models\ProjectDateChange;
use App\Models\ProjectDelivery;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns PmoLastVersion's own data into the "board" JSON the Planning page reads (/planning/board, resources/js/planning/).
 * The shape is the one the original Avatar Planning app used (that single-file app was removed in 2026-11).
 *
 *   Project               -> task      (SO = project_no, dates, priority, comments = Activity notes)
 *   Cabinet               -> subtask   (MO = mo_no; checklist = the checklist items of its dispatched Sub Tasks)
 *   Sub Tasks of one dept -> deptStage (+ per-cabinet deptDates)   -> "ส่งต่อแผนก" chips and the department calendar
 *   workflow status       -> one of 5 fixed statuses (the five colour dots)
 *
 * The page's edits are NOT written by this class or by the page: they come back as operations to PlanningSync, which applies
 * them through PMO's own services and rules. The profile's perm only tells the page which controls to offer:
 * PMO roles 'full', everyone else 'checklist' (tick checklist items + comment) - the server enforces the real rules either way.
 * What a user receives follows the same visibility rule as My Department (DepartmentDashboard::scopeFor): PMO roles
 * get every department, everyone else only their own department's Sub Tasks, checklist items and stages.
 *
 * One project as the page reads it is in Concerns\BoardProjectShape. This class builds the board (build / tasks), its
 * stamp for the page's polling, the lookups (statuses, extra fields, departments), its constants and caches.
 */
class PlanningBoard
{
    use Concerns\BoardProjectShape;
    use Concerns\BoardStamp;
    use Concerns\BoardPalette;

    public const BOARD_ID = 'pmo';

    private const ACTIVE = [
        CabinetSubtask::ASSIGNMENT_ASSIGNED,
        CabinetSubtask::ASSIGNMENT_ACCEPTED,
        CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
        CabinetSubtask::ASSIGNMENT_COMPLETED,
    ];

    private const PALETTE = ['#a855f7', '#3b82f6', '#f59e0b', '#10b981', '#ec4899', '#14b8a6', '#f97316', '#06b6d4', '#84cc16', '#6366f1'];

    /** The profile the page runs as: read-only, and "no department" (= sees every department) for PMO roles. */
    public function profile(User $user): array
    {
        return [
            'id' => 'u'.$user->id,
            'email' => $user->email,
            'display_name' => $user->name,
            'role' => 'member',
            'perm' => $user->canDispatchWork() ? 'full' : 'checklist',
            'approved' => true,
            'blocked' => false,
            'name_set' => true,
            // the page starts on this department (none: every one in view); looking after several, it starts on all of them
            'dept_id' => ($user->canSeeAllWork() || count($user->departmentIds()) !== 1) ? '' : self::deptKey($user->department_id),
        ];
    }

    /** @param  int|null|string  $board  null = the main board, an id = that extra board, 'any' = no board filter */
    public function build(User $user, ?\Carbon\Carbon $today = null, int|string|null $board = null): array
    {
        $today ??= today();

        $handoff = app(HandoffService::class);
        $fieldsByDept = DepartmentField::with('options')->orderBy('sort')->orderBy('id')->get()->groupBy('department_id');
        $colors = self::departmentColors();
        $departments = Department::where('is_active', true)->orderBy('id')->get(['id', 'name', 'code', 'color', 'icon', 'sees_all'])
            ->values()
            ->map(fn (Department $d) => [
                'id' => self::deptKey($d->id),
                'name' => $d->name,
                'code' => (string) $d->code,
                'color' => $colors[$d->id],   // Settings > แผนก, else a stable palette colour
                'icon' => (string) $d->icon,
                'checklist' => [],
                'fields' => ($fieldsByDept->get($d->id) ?? collect())->map(fn (DepartmentField $f) => self::fieldShape($f))->values()->all(),
                'seeAll' => (bool) $d->sees_all,
                // Settings > แผนก: the date this department gives each checklist item (null = none); template pairs: whose Sub Tasks compare with its
                'dateLabel' => $handoff->dateLabel($d->id),
                'passesTo' => array_map(fn ($id) => self::deptKey($id), $handoff->targetsFor($d->id)),
            ])->all();

        $tasks = $this->tasks($user, null, $today, $board);

        return [
            'activeBoard' => self::BOARD_ID,
            'boardMeta' => [['id' => self::BOARD_ID, 'name' => 'PMO']],
            'boards' => [self::BOARD_ID => [
                'statuses' => self::statuses(),
                'pmoStatusList' => self::pmoStatuses(),
                'labels' => BoardLabel::orderBy('sort')->orderBy('id')->get()->map(fn (BoardLabel $l) => ['id' => 'l'.$l->id, 'name' => $l->name, 'color' => $l->color])->all(),
                'departments' => $departments,
                'checklistStatuses' => app(ChecklistStatuses::class)->catalog(),   // Settings > สีสถานะ Checklist
                'tasks' => $tasks,
                'trash' => [],
                'settings' => [
                    'currentUser' => $user->name,
                    'myDept' => (count($user->departmentIds()) === 1 && ! $user->canSeeAllWork()) ? self::deptKey($user->department_id) : '',
                    'defaultStages' => [],
                    'dateLimit' => ['enabled' => false, 'anchorDept' => null, 'blockedDepts' => []],
                ],
            ]],
        ];
    }

    /**
     * The board's tasks for this user - all of them, or only the given project ids (what a sync answer needs: just the
     * projects that were edited). A requested project the user cannot see (or that no longer exists) is simply absent.
     * PMO roles also get open projects that have no work dispatched yet, and every cabinet of a project (even an empty one),
     * so they can build a project up from this page; everyone else sees only their department's dispatched work.
     *
     * @param  list<int>|null  $projectIds
     * @param  int|null|string  $board  null = the main board, an id = that extra board, 'any' = every board
     */
    public function tasks(User $user, ?array $projectIds = null, ?\Carbon\Carbon $today = null, int|string|null $board = 'any'): array
    {
        $today ??= today();
        $scope = DepartmentDashboard::scopeFor($user);   // null = every department, [] = none, [ids] = those
        $onBoard = fn ($q) => $q->when($board !== 'any', fn ($q) => $board === null ? $q->whereNull('board_id') : $q->where('board_id', $board));

        $subtasks = $scope === [] || $projectIds === [] ? collect() : CabinetSubtask::query()
            ->whereNotNull('department_id')
            ->whereIn('assignment_status', self::ACTIVE)
            ->when($scope !== null, fn ($q) => $q->whereIn('department_id', $scope))
            ->whereHas('cabinetTask.cabinet', fn ($c) => $c
                ->when($projectIds !== null, fn ($c) => $c->whereIn('project_id', $projectIds))
                ->whereHas('project', $onBoard))
            ->with('cabinetTask.cabinet.project.boardStatus', 'cabinetTask.cabinet.project.boardLabels', 'cabinetTask.cabinet.project.assignees:id', 'cabinetTask.cabinet.project.followers:id', 'cabinetTask.cabinet.project.latestFat', 'cabinetTask.cabinet.boardStatus', 'checklists')
            ->orderBy('id')
            ->get();

        $byProject = $subtasks->groupBy(fn ($s) => $s->cabinetTask->cabinet->project_id);
        $builder = $user->canDispatchWork() && $projectIds !== [];

        // PMO: open projects nobody has been given work on yet (they still need to appear so work can be added)
        $empty = $projectIds === [] ? collect() : Project::query()
            ->whereNotIn('status', Project::LOCKED_STATUSES)
            ->whereNotIn('id', $byProject->keys())
            // PMO: every open project; everyone else: the ones they were made a person in charge of
            ->when(! $builder, fn ($q) => $q->whereHas('assignees', fn ($a) => $a->whereKey($user->id)))
            ->when($projectIds !== null, fn ($q) => $q->whereIn('id', $projectIds))
            ->tap($onBoard)
            ->with('boardStatus', 'boardLabels', 'assignees:id', 'followers:id', 'latestFat')->orderBy('id')->get();

        // แผนกจัดส่ง: open projects it has no Sub Task in but a delivery round sent to it (not PM's drafts) - its work on
        // งานจัดส่ง - so the calendars show those rounds too, marked as งานขนส่ง ('ship') to tell them from Sub Task work
        $ship = $builder || $projectIds === [] || ! DeliveryFlow::canDeliver($user) ? collect() : Project::query()
            ->whereNotIn('status', Project::LOCKED_STATUSES)
            ->whereNotIn('id', $byProject->keys()->merge($empty->pluck('id')))
            ->whereHas('deliveries', fn ($d) => $d->where('status', '!=', ProjectDelivery::DRAFT))
            ->when($projectIds !== null, fn ($q) => $q->whereIn('id', $projectIds))
            ->tap($onBoard)
            ->with('boardStatus', 'boardLabels', 'assignees:id', 'followers:id', 'latestFat')->orderBy('id')->get();

        $ids = $byProject->keys()->merge($empty->pluck('id'))->merge($ship->pluck('id'));
        $comments = $this->comments($ids, $user);
        $this->fieldValues = $this->loadFieldValues($ids);
        $this->tags = app(ChecklistStatuses::class)->idsFor($subtasks->flatMap->checklists->pluck('id'));
        $this->peerTags = app(ChecklistStatuses::class)->peersFor($subtasks->flatMap->checklists);
        // who last wrote each remark (the "หมายเหตุ" tab of the project window)
        $this->remarkBy = Remarks::authors([
            't' => $subtasks->map->cabinetTask->filter(fn ($t) => filled($t->remark))->pluck('id')->all(),
            's' => $subtasks->filter(fn ($s) => filled($s->remark))->pluck('id')->all(),
            'k' => $subtasks->flatMap->checklists->filter(fn ($c) => filled($c->remark))->pluck('id')->all(),
        ]);
        $this->deptStatuses = $ids->isEmpty() ? collect() : ProjectDepartmentStatus::whereIn('project_id', $ids)->with('boardStatus')->get()
            ->groupBy('project_id')->map(fn (Collection $g) => $g->mapWithKeys(fn ($r) => [$r->department_id => $r->boardStatus?->pageId()])->filter());
        // PMO: sub tasks nobody was given yet (e.g. straight from a template), so they can be handed out from the page
        $this->free = ! $builder ? collect() : CabinetSubtask::query()
            ->where(fn ($q) => $q->whereNull('department_id')->orWhere('assignment_status', CabinetSubtask::ASSIGNMENT_UNASSIGNED))
            ->whereHas('cabinetTask.cabinet', fn ($q) => $q->whereIn('project_id', $ids))
            ->with('cabinetTask:id,cabinet_id,name', 'rejectedDepartment:id,name')->withCount('checklists')->orderBy('id')->get()
            ->groupBy(fn ($s) => $s->cabinetTask->cabinet_id);
        $this->usual = $this->usualDepartments($this->free->flatten());
        // template pairs: the lists this user's department's Sub Tasks compare with, per cabinet (read only)
        $this->linked = app(HandoffService::class)->linkedFor($user, $byProject->flatten(1)->map(fn ($s) => $s->cabinetTask->cabinet_id)->unique()->values());
        // เลื่อน / แก้วัน (ProjectDates): each project's and cabinet's changes, for the "เลื่อน n" chips
        $this->dateChanges = $ids->isEmpty() ? collect() : ProjectDateChange::whereIn('project_id', $ids)->orderBy('id')->get()
            ->groupBy(fn ($c) => $c->cabinet_id ? 'c'.$c->cabinet_id : 'p'.$c->project_id);
        // การจัดส่ง (ProjectDeliveryController): each project's rounds, for the "ส่ง" line on its bar and its project window
        // (PM's drafts only for those who book rounds: nobody else is meant to see them yet)
        $this->deliveries = $ids->isEmpty() ? collect() : ProjectDelivery::with('vehicles.vehicle')->whereIn('project_id', $ids)
            ->when(! DeliveryFlow::canBook($user), fn ($q) => $q->where('status', '!=', ProjectDelivery::DRAFT))->orderBy('round')->get()->groupBy('project_id');
        // the dashboard table, as the projects list: production % (the cabinets' average) and ประเภทงาน
        $this->production = $ids->isEmpty() ? collect() : Cabinet::whereIn('project_id', $ids)->selectRaw('project_id, avg(progress) as pct, count(*) as n')
            ->groupBy('project_id')->get()->keyBy('project_id');
        $this->jobTypes = \App\Models\JobType::pluck('name', 'id');
        $cabinets = $builder ? Cabinet::with('boardStatus')->whereIn('project_id', $ids)->orderBy('sequence')->orderBy('id')->get()->groupBy('project_id') : null;

        $tasks = $byProject->map(fn (Collection $rows) => $this->task($rows->first()->cabinetTask->cabinet->project, $rows, $comments, $today, $user, $cabinets))
            ->concat($empty->map(fn (Project $p) => $this->task($p, collect(), $comments, $today, $user, $cabinets)))
            ->concat($ship->map(fn (Project $p) => ['ship' => true] + $this->task($p, collect(), $comments, $today, $user, $cabinets)));

        return $tasks->sortBy(fn (array $t) => (int) substr($t['id'], 1))->values()->all();
    }

    /** What the board is read from: [table, what to add up]. Soft-deleted rows count too (a delete / restore bumps updated_at). */
    private const STAMP_TABLES = [
        'projects' => 'id', 'cabinets' => 'id', 'cabinet_tasks' => 'id', 'cabinet_subtasks' => 'id', 'cabinet_checklists' => 'id',
        'activity_logs' => 'id', 'comment_attachments' => 'id', 'board_labels' => 'id', 'board_statuses' => 'id', 'boards' => 'id',
        'departments' => 'id', 'department_fields' => 'id', 'department_field_options' => 'id', 'project_field_values' => 'id',
        'project_department_statuses' => 'id', 'department_links' => 'id', 'users' => 'id',
        // pivots: no id / timestamps - a swap (detach one, attach another) still changes the sum
        'checklist_statuses' => 'id', 'cabinet_checklist_status' => 'id', 'project_fats' => 'id', 'project_date_changes' => 'id', 'project_deliveries' => 'id', 'project_delivery_vehicles' => 'id', 'vehicles' => 'id',
        'project_user' => 'project_id * 100003 + user_id', 'board_label_project' => 'project_id * 100003 + board_label_id',
    ];

    /** How often a poll rebuilds the board anyway, whatever the stamp says (seconds) - the safety net for a change it cannot see. */
    public const STAMP_MAX_AGE = 120;

    /** @var array<string, int> "t<template id>" / "n<name>" => the department that piece of work usually goes to */
    private array $usual = [];

    /**
     * For each piece of work not handed out yet: the department the same piece of work (same template sub task, else same name)
     * was most often given before - so "+ เพิ่มแผนก" can tick it for that department by itself.
     */
    private function usualDepartments(Collection $free): array
    {
        if ($free->isEmpty()) {
            return [];
        }
        $byTemplate = CabinetSubtask::query()->whereNotNull('department_id')
            ->whereIn('cabinet_subtask_template_id', $free->pluck('cabinet_subtask_template_id')->filter()->unique())
            ->selectRaw('cabinet_subtask_template_id as k, department_id, count(*) as n')->groupBy('cabinet_subtask_template_id', 'department_id')->get()
            ->groupBy('k')->mapWithKeys(fn ($rows, $k) => ['t'.$k => (int) $rows->sortByDesc('n')->first()->department_id]);
        $byName = CabinetSubtask::query()->whereNotNull('department_id')
            ->whereIn('name', $free->pluck('name')->unique())
            ->selectRaw('name as k, department_id, count(*) as n')->groupBy('name', 'department_id')->get()
            ->groupBy('k')->mapWithKeys(fn ($rows, $k) => ['n'.$k => (int) $rows->sortByDesc('n')->first()->department_id]);

        // plain arrays: these are Eloquent collections holding numbers, and Eloquent's merge() expects models ("t.." / "n.." keys never clash)
        return $byTemplate->all() + $byName->all();
    }

    /** @var Collection<int, Collection> cabinet id => its sub tasks with no department yet (PMO roles only; filled per tasks() call) */
    private Collection $free;

    /** @var Collection<int, array> cabinet id => the sending departments' checklists (filled per tasks() call) */
    private Collection $linked;

    /** @var Collection<string, Collection> 'p<project id>' / 'c<cabinet id>' => its date changes, oldest first (filled per tasks() call) */
    private Collection $dateChanges;

    private Collection $deliveries;

    /** each project's production % (its cabinets' average, as the projects list) and cabinet count, by project id */
    private Collection $production;

    /** ประเภทงาน names by id */
    private Collection $jobTypes;

    /** @var Collection<int, Collection> project id => department id => page id of the status Admin / PM set for it (filled per tasks() call) */
    private Collection $deptStatuses;

    /** 't|s|k' + id => who last wrote the remark and when (Remarks::authors) */
    private array $remarkBy = [];

    /** checklist item id => its status ids (ChecklistStatuses::idsFor) */
    private array $tags = [];

    /** checklist item id => the other side of its pair's statuses (ChecklistStatuses::peersFor) */
    private array $peerTags = [];

    /** @var Collection<int, Collection> project id => department id => [field key => value] (filled per tasks() call) */
    private Collection $fieldValues;

    private function loadFieldValues(Collection $projectIds): Collection
    {
        if ($projectIds->isEmpty()) {
            return collect();
        }
        $fields = DepartmentField::get(['id', 'department_id', 'type'])->keyBy('id');

        return ProjectFieldValue::whereIn('project_id', $projectIds)->get()->groupBy('project_id')->map(
            fn (Collection $vals) => $vals->filter(fn ($v) => $fields->has($v->department_field_id))
                ->groupBy(fn ($v) => $fields[$v->department_field_id]->department_id)
                ->map(fn (Collection $vs) => $vs->mapWithKeys(fn ($v) => ['f'.$v->department_field_id => $fields[$v->department_field_id]->type === 'select' ? 'o'.$v->value : $v->value])->all())
        );
    }

    /**
     * "ของฉัน": the projects that are mine - my department still has unfinished work on them, I am their Project Manager, or someone
     * tagged / alerted me on them. (The people in charge are departments here, so "assigned to me" means my department.)
     *
     * @return list<int>
     */
    public function mineProjectIds(User $user): array
    {
        $ids = Project::where('project_manager_id', $user->id)->pluck('id')
            ->merge(Project::whereHas('assignees', fn ($q) => $q->whereKey($user->id))->pluck('id'))
            ->merge(Project::whereHas('followers', fn ($q) => $q->whereKey($user->id))->pluck('id'));   // ones I follow

        if ($user->departmentIds()) {
            $ids = $ids->merge(CabinetSubtask::whereIn('department_id', $user->departmentIds())
                ->whereIn('assignment_status', [CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS])
                ->with('cabinetTask.cabinet:id,project_id')->get()->map(fn ($s) => $s->cabinetTask?->cabinet?->project_id));
        }

        $tagged = $user->notifications()->where('created_at', '>=', now()->subDays(60))->get()
            ->filter(fn ($n) => in_array($n->data['kind'] ?? null, ['mention', 'alert'], true))
            ->map(fn ($n) => $n->data['project_id'] ?? (preg_match('/[?&]project=(\d+)/', (string) ($n->data['url'] ?? ''), $m) ? (int) $m[1] : null));

        return $ids->merge($tagged)->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
    }

}
