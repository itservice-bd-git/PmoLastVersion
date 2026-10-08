<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\BoardLabel;
use App\Models\BoardStatus;
use App\Models\Cabinet;
use App\Models\DepartmentField;
use App\Models\ProjectFieldValue;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns PmoLastVersion's own data into the "board" JSON that the Avatar Planning single-page app (resources/planning/
 * index.html, served at /planning) expects, so that page can run on OUR data instead of Supabase.
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
 */
class PlanningBoard
{
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
            'dept_id' => ($user->canSeeAllWork() || ! $user->department_id) ? '' : self::deptKey($user->department_id),
        ];
    }

    /** @param  int|null|string  $board  null = the main board, an id = that extra board, 'any' = no board filter */
    public function build(User $user, ?\Carbon\Carbon $today = null, int|string|null $board = null): array
    {
        $today ??= today();

        $fieldsByDept = DepartmentField::with('options')->orderBy('sort')->orderBy('id')->get()->groupBy('department_id');
        $departments = Department::where('is_active', true)->orderBy('id')->get(['id', 'name', 'color', 'icon', 'sees_all'])
            ->values()
            ->map(fn (Department $d, int $i) => [
                'id' => self::deptKey($d->id),
                'name' => $d->name,
                'color' => $d->color ?: self::PALETTE[$i % count(self::PALETTE)],   // Settings > แผนก, else a stable palette colour
                'icon' => (string) $d->icon,
                'checklist' => [],
                'fields' => ($fieldsByDept->get($d->id) ?? collect())->map(fn (DepartmentField $f) => self::fieldShape($f))->values()->all(),
                'seeAll' => (bool) $d->sees_all,
            ])->all();

        $tasks = $this->tasks($user, null, $today, $board);

        return [
            'activeBoard' => self::BOARD_ID,
            'boardMeta' => [['id' => self::BOARD_ID, 'name' => 'PMO']],
            'boards' => [self::BOARD_ID => [
                'statuses' => self::statuses(),
                'labels' => BoardLabel::orderBy('sort')->orderBy('id')->get()->map(fn (BoardLabel $l) => ['id' => 'l'.$l->id, 'name' => $l->name, 'color' => $l->color])->all(),
                'departments' => $departments,
                'tasks' => $tasks,
                'trash' => [],
                'settings' => [
                    'currentUser' => $user->name,
                    'myDept' => ($user->department_id && ! $user->canSeeAllWork()) ? self::deptKey($user->department_id) : '',
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
            ->with('cabinetTask.cabinet.project.boardStatus', 'cabinetTask.cabinet.project.boardLabels', 'checklists')
            ->orderBy('id')
            ->get();

        $byProject = $subtasks->groupBy(fn ($s) => $s->cabinetTask->cabinet->project_id);
        $builder = $user->canDispatchWork() && $projectIds !== [];

        // PMO: open projects nobody has been given work on yet (they still need to appear so work can be added)
        $empty = ! $builder ? collect() : Project::query()
            ->whereNotIn('status', Project::LOCKED_STATUSES)
            ->whereNotIn('id', $byProject->keys())
            ->when($projectIds !== null, fn ($q) => $q->whereIn('id', $projectIds))
            ->tap($onBoard)
            ->with('boardStatus', 'boardLabels')->orderBy('id')->get();

        $ids = $byProject->keys()->merge($empty->pluck('id'));
        $comments = $this->comments($ids, $user);
        $this->fieldValues = $this->loadFieldValues($ids);
        $cabinets = $builder ? Cabinet::whereIn('project_id', $ids)->orderBy('sequence')->orderBy('id')->get()->groupBy('project_id') : null;

        $tasks = $byProject->map(fn (Collection $rows) => $this->task($rows->first()->cabinetTask->cabinet->project, $rows, $comments, $today, $user, $cabinets))
            ->concat($empty->map(fn (Project $p) => $this->task($p, collect(), $comments, $today, $user, $cabinets)));

        return $tasks->sortBy(fn (array $t) => (int) substr($t['id'], 1))->values()->all();
    }

    /** Every status the page offers, in the order set in Settings > สถานะงาน (the five built-in ones plus any added by hand). */
    public static function statuses(): array
    {
        return BoardStatus::orderBy('sort')->orderBy('id')->get()
            ->map(fn (BoardStatus $s) => ['id' => $s->pageId(), 'name' => $s->name, 'color' => $s->color, 'isDone' => $s->is_done])->all();
    }

    /** A department field in the shape the page reads: select fields carry their options grouped (a blank group name = no heading). */
    private static function fieldShape(DepartmentField $f): array
    {
        $shape = ['id' => 'f'.$f->id, 'name' => $f->name];
        if ($f->type !== 'select') {
            return $shape + ['type' => $f->type, 'groups' => []];
        }

        return $shape + ['groups' => $f->options->groupBy(fn ($o) => $o->group_name ?? '')->map(fn (Collection $os, string $g) => [
            'id' => 'g'.md5($f->id.$g), 'name' => $g,
            'options' => $os->map(fn ($o) => ['id' => 'o'.$o->id, 'label' => $o->label])->values()->all(),
        ])->values()->all()];
    }

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
        $ids = Project::where('project_manager_id', $user->id)->pluck('id');

        if ($user->department_id) {
            $ids = $ids->merge(CabinetSubtask::where('department_id', $user->department_id)
                ->whereIn('assignment_status', [CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS])
                ->with('cabinetTask.cabinet:id,project_id')->get()->map(fn ($s) => $s->cabinetTask?->cabinet?->project_id));
        }

        $tagged = $user->notifications()->where('created_at', '>=', now()->subDays(60))->get()
            ->filter(fn ($n) => in_array($n->data['kind'] ?? null, ['mention', 'alert'], true))
            ->map(fn ($n) => $n->data['project_id'] ?? (preg_match('/[?&]project=(\d+)/', (string) ($n->data['url'] ?? ''), $m) ? (int) $m[1] : null));

        return $ids->merge($tagged)->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();
    }

    public static function deptKey(int $id): string
    {
        return 'd_'.$id;
    }

    private function task(Project $project, Collection $rows, Collection $comments, \Carbon\Carbon $today, User $user, ?Collection $allCabinets): array
    {
        $dues = $rows->pluck('due_date')->filter();
        $starts = $rows->pluck('start_date')->filter();
        $date = ($project->due_date ?? $dues->max() ?? $project->created_at ?? $today)->format('Y-m-d');
        $start = ($project->start_date ?? $starts->min())?->format('Y-m-d');

        // PMO roles get every cabinet of the project (even one nothing was dispatched on yet); everyone else only those with their work
        $byCabinet = $rows->groupBy(fn ($s) => $s->cabinetTask->cabinet_id);
        $cabinets = $allCabinets
            ? ($allCabinets->get($project->id) ?? collect())->map(fn (Cabinet $c) => $this->cabinet($c, $byCabinet->get($c->id, collect()), $today))
            : $byCabinet->map(fn (Collection $cab) => $this->cabinet($cab->first()->cabinetTask->cabinet, $cab, $today));
        $stages = $rows->groupBy('department_id');

        return [
            'id' => 'p'.$project->id,
            'title' => $project->project_name,
            'so' => $project->project_no,
            'description' => (string) $project->description,
            'startDate' => ($start && $start <= $date) ? $start : null,
            'date' => $date,
            // a status picked by hand wins; otherwise it is worked out from the departments' progress
            'statusId' => $project->boardStatus?->pageId() ?? $this->statusFor($rows, $today),
            'manualStatus' => $project->boardStatus !== null,
            'boardNo' => $project->board_id,
            'priority' => ['low' => 'low', 'high' => 'high', 'urgent' => 'urgent'][$project->priority] ?? 'none',
            'labels' => $project->boardLabels->map(fn ($l) => 'l'.$l->id)->values()->all(),
            // the people in charge are the departments that hold work on the project
            'assignees' => $stages->keys()->map(fn ($id) => self::deptKey((int) $id))->values()->all(),
            'subtasks' => $cabinets->values()->all(),
            'deptStages' => $stages->map(fn (Collection $d, $deptId) => [
                'deptId' => self::deptKey((int) $deptId),
                'due' => ($d->pluck('due_date')->filter()->max() ?? $project->due_date ?? $today)->format('Y-m-d'),
                'done' => $d->every(fn ($s) => $s->assignment_status === CabinetSubtask::ASSIGNMENT_COMPLETED),
                'fields' => (object) ($this->fieldValues->get($project->id)?->get((int) $deptId) ?? []),
                'pmo' => $this->workflow($d, (int) $deptId, $user),
            ])->values()->all(),
            'comments' => $comments->get($project->id, collect())->values()->all(),
            // the change log ("การดำเนินการ") is the project page's Activity Log tree, fetched on demand; PMO roles only
            'logUrl' => $user->canViewOtherDepartments() ? route('projects.activity-tree', $project) : null,
            'createdAt' => ($project->created_at ?? $today)->getTimestampMs(),
            'updatedAt' => ($project->updated_at ?? $today)->getTimestampMs(),
            'pmoUrl' => route('projects.show', $project),
        ];
    }

    private function cabinet(Cabinet $cabinet, Collection $rows, \Carbon\Carbon $today): array
    {
        // { deptKey: 'YYYY-MM-DD' } - must serialise as a JSON object even when empty
        $deptDates = $rows->filter(fn ($s) => $s->due_date)->groupBy('department_id')
            ->mapWithKeys(fn ($d, $deptId) => [self::deptKey((int) $deptId) => $d->pluck('due_date')->max()->format('Y-m-d')])
            ->all();

        return [
            'id' => 'c'.$cabinet->id,
            'text' => $cabinet->cabinet_name,
            'mo' => $cabinet->mo_no,
            'statusId' => $this->statusFor($rows, $today),
            'date' => $rows->pluck('due_date')->filter()->max()?->format('Y-m-d'),
            'deptDates' => $deptDates ?: new \stdClass,
            'checklist' => $rows->flatMap(fn ($s) => $s->checklists->map(fn ($c) => [
                'id' => 'k'.$c->id,
                'text' => $c->name,
                'done' => (bool) $c->is_completed,
                'deptId' => self::deptKey((int) $s->department_id),
            ]))->values()->all(),
        ];
    }

    /** Where one department's Sub Tasks of this project are in the accept / start / complete workflow, and what this user may do now. */
    private function workflow(Collection $rows, int $deptId, User $user): array
    {
        $n = fn (string $status) => $rows->where('assignment_status', $status)->count();
        $mine = $user->department_id === $deptId;   // the workflow actions belong to the department's own members

        return [
            'waiting' => $n(CabinetSubtask::ASSIGNMENT_ASSIGNED),
            'accepted' => $n(CabinetSubtask::ASSIGNMENT_ACCEPTED),
            'working' => $n(CabinetSubtask::ASSIGNMENT_IN_PROGRESS),
            'done' => $n(CabinetSubtask::ASSIGNMENT_COMPLETED),
            'canAccept' => $mine && $n(CabinetSubtask::ASSIGNMENT_ASSIGNED) > 0,
            'canStart' => $mine && $n(CabinetSubtask::ASSIGNMENT_ACCEPTED) > 0,
            'canComplete' => $mine && $n(CabinetSubtask::ASSIGNMENT_IN_PROGRESS) > 0,
        ];
    }

    /** Worst-first: overdue, then all done, then in progress, then accepted, else waiting. Returns the page id of a built-in status. */
    private function statusFor(Collection $rows, \Carbon\Carbon $today): string
    {
        $done = fn ($s) => $s->assignment_status === CabinetSubtask::ASSIGNMENT_COMPLETED;
        $ids = BoardStatus::PAGE_IDS;

        return match (true) {
            $rows->isEmpty() => $ids['todo'],
            $rows->contains(fn ($s) => ! $done($s) && $s->due_date && $s->due_date->lt($today)) => $ids['late'],
            $rows->every($done) => $ids['done'],
            $rows->contains(fn ($s) => $s->assignment_status === CabinetSubtask::ASSIGNMENT_IN_PROGRESS) => $ids['doing'],
            $rows->contains(fn ($s) => $s->assignment_status === CabinetSubtask::ASSIGNMENT_ACCEPTED) => $ids['accepted'],
            default => $ids['todo'],
        };
    }

    /**
     * The Activity box per project id: the discussion (notes) only - updates people post for everyone involved. Newest 30,
     * oldest first (the order the page expects). PMO's change log is not mixed in (see `logUrl`).
     */
    private function comments(Collection $projectIds, User $user): Collection
    {
        if ($projectIds->isEmpty()) {
            return collect();
        }

        return ActivityLog::query()->whereIn('project_id', $projectIds)->where('action', 'note')->with('user', 'attachments')->orderBy('id')->get()
            ->groupBy('project_id')->map(
                fn (Collection $logs) => $logs->take(-30)->values()->map(fn (ActivityLog $l) => [
                    'id' => 'a'.$l->id,
                    'author' => $l->user?->name ?? 'ระบบ',
                    'text' => $l->description,
                    'at' => $l->created_at->getTimestampMs(),
                    'files' => $l->attachments->map(fn ($a) => ['name' => $a->original_name, 'url' => $a->url, 'size' => $a->size_for_humans, 'image' => str_starts_with((string) $a->mime_type, 'image/')])->values()->all(),
                ])
            );
    }
}
