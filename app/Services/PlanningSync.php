<?php

namespace App\Services;

use App\Exceptions\AssignmentException;
use App\Exceptions\PlanningRejected;
use App\Exceptions\ProjectLockedException;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Board;
use App\Models\BoardLabel;
use App\Models\BoardStatus;
use App\Models\Cabinet;
use App\Models\CabinetTemplate;
use App\Models\CabinetTask;
use App\Models\DepartmentField;
use App\Models\ProjectFieldValue;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\DateLimitRule;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies the edits made on the /planning page (the Avatar Planning UI running on PMO data) to PMO's real data.
 *
 * The page never writes data itself: it sends a list of operations, and every one goes through the SAME services and
 * rules the rest of PMO uses (checklist toggling + automation, the accept/start/complete workflow, ProjectEditor, date
 * limit rules, closed-project locks, activity log, notifications) and is checked against WorkScope / the user's role.
 * Each operation is its own transaction: a refused one changes nothing and comes back with a Thai reason; the page then
 * repaints from the authoritative board, so a refused edit simply "bounces back".
 *
 * What is NOT possible here (so it is refused, never faked): creating projects, cabinets or stages, renaming cabinets or
 * checklist items, free-form statuses, labels - PMO's structure comes from templates and its status from the workflow.
 * (Adding / removing a checklist item IS possible, under the same rule as the Checklist screens: PMO roles, or the department
 * that has accepted the work.)
 *
 * The operations live in traits by area (app/Services/Concerns): SyncProjects, SyncStages, SyncCabinets, SyncChecklist,
 * SyncComments. This class keeps apply() / applyOne() (the dispatch: one transaction per operation) and the shared helpers.
 */
class PlanningSync
{
    use Concerns\SyncChecklist, Concerns\SyncComments, Concerns\SyncProjects, Concerns\SyncStages, Concerns\SyncCabinets;


    /** @var list<string> warnings collected while one operation runs, added to its answer */
    private array $notes = [];

    /** Project id the last create / copy / import / restore produced (the page opens it). */
    private ?int $created = null;

    public function created(): ?int
    {
        return $this->created;
    }

    private const STEP_FROM = [
        'accept' => CabinetSubtask::ASSIGNMENT_ASSIGNED,
        'start' => CabinetSubtask::ASSIGNMENT_ACCEPTED,
        'complete' => CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
        'reject' => CabinetSubtask::ASSIGNMENT_ASSIGNED,   // "ไม่รับงาน" + reason: back to PMO
    ];

    private const PRIORITY = ['none' => 'normal', 'medium' => 'normal', 'low' => 'low', 'high' => 'high', 'urgent' => 'urgent'];

    public function __construct(
        private SubtaskAssignmentService $assignments,
        private AutomationService $automation,
        private ProgressService $progress,
        private NotificationService $notifications,
        private ProjectEditor $editor,
    ) {}

    /**
     * @param  list<mixed>  $ops
     * @return list<array{ok: bool, type: string, message: ?string}>
     */
    public function apply(User $user, array $ops): array
    {
        return array_map(fn ($op) => $this->applyOne($user, is_array($op) ? $op : []), array_values(array_slice($ops, 0, 200)));
    }

    private function applyOne(User $user, array $op): array
    {
        $type = (string) ($op['type'] ?? '');

        try {
            $this->notes = [];
            // one notice per department / project for the work this operation hands out (sent only if it succeeds)
            $message = $this->notifications->batch(fn () => DB::transaction(fn () => match ($type) {
                'checklist' => $this->checklist($user, $op),
                'checklist_add' => $this->checklistAdd($user, $op),
                'checklist_delete' => $this->checklistDelete($user, $op),
                'comment' => $this->comment($user, $op),
                'project' => $this->project($user, $op),
                'stage_due' => $this->stageDue($user, $op),
                'stage_dates' => $this->stageDates($user, $op),
                'cabinet_due' => $this->cabinetDue($user, $op, false),
                'cabinet_dept_due' => $this->cabinetDue($user, $op, true),
                'stage_field' => $this->stageField($user, $op),
                'labels_set' => $this->labelsSet($user, $op),
                'label_create' => $this->labelCreate($user, $op),
                'label_delete' => $this->labelDelete($user, $op),
                'cabinet_add' => $this->cabinetAdd($user, $op),
                'cabinet_rename' => $this->cabinetRename($user, $op),
                'cabinet_delete' => $this->cabinetDelete($user, $op),
                'stage_add' => $this->stageAdd($user, $op),
                'subtask_assign' => $this->subtaskAssign($user, $op),
                'cabinet_info' => $this->cabinetInfo($user, $op),
                'subtask_info' => $this->subtaskInfo($user, $op),
                'project_shift' => $this->projectShift($user, $op),
                'stage_remove' => $this->stageRemove($user, $op),
                'board_set' => $this->boardSet($user, $op),
                'project_create' => $this->projectCreate($user, $op),
                'project_duplicate' => $this->projectDuplicate($user, $op),
                'project_restore' => $this->projectRestore($user, $op),
                'assignees_set' => $this->assigneesSet($user, $op),
                'cabinet_status' => $this->cabinetStatus($user, $op),
                'stage_status' => $this->stageStatus($user, $op),
                'checklist_rename' => $this->checklistRename($user, $op),
                'checklist_remark' => $this->checklistRemark($user, $op),
                'checklist_tags' => $this->checklistTags($user, $op),
                'checklist_date' => $this->checklistDate($user, $op),
                'checklist_date_all' => $this->checklistDateAll($user, $op),
                'checklist_move' => $this->checklistMove($user, $op),
                'comment_edit' => $this->commentEdit($user, $op),
                'comment_delete' => $this->commentDelete($user, $op),
                'alert_ack' => $this->alertAck($user, $op),
                'stage_done' => $this->stageDone($user, $op),
                'stage_step' => $this->stageStep($user, $op),
                'subtasks_step' => $this->subtasksStep($user, $op),
                'delete' => $this->delete($user, $op),
                'unsupported' => throw new PlanningRejected((string) ($op['what'] ?? 'การแก้ไขนี้ยังไม่รองรับในหน้านี้')),
                default => throw new PlanningRejected('ไม่รู้จักการแก้ไขนี้'),
            }));

            // things the person should know although it worked (e.g. a department with nobody in it)
            $message = $this->notes ? trim(($message ? $message.' · ' : '').implode(' · ', array_unique($this->notes))) : $message;

            return ['ok' => true, 'type' => $type, 'message' => $message];
        } catch (PlanningRejected|AssignmentException|ProjectLockedException $e) {
            return ['ok' => false, 'type' => $type, 'message' => $e->getMessage()];
        } catch (ValidationException $e) {
            return ['ok' => false, 'type' => $type, 'message' => collect($e->errors())->flatten()->first() ?? 'ข้อมูลไม่ถูกต้อง'];
        }
    }

    // ---- id helpers (the board uses p12 / c34 / k56 / d_7 ids) ----

    private function id(mixed $value, string $prefix): int
    {
        if (! is_string($value) || ! preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/', $value, $m)) {
            throw new PlanningRejected('ข้อมูลที่ส่งมาไม่ถูกต้อง');
        }

        return (int) $m[1];
    }

    private function projectFor(User $user, mixed $taskId): Project
    {
        $project = Project::find($this->id($taskId, 'p')) ?? throw new PlanningRejected('ไม่พบโครงการนี้ (อาจถูกลบไปแล้ว)');

        if (! WorkScope::canSeeProject($project, $user)) {
            throw new PlanningRejected('คุณไม่มีสิทธิ์ในโครงการนี้');
        }

        return $project;
    }

    private function requirePmo(User $user, string $what): void
    {
        if (! $user->canDispatchWork()) {
            throw new PlanningRejected("เฉพาะผู้ดูแลระบบ (Admin) หรือ Project Manager เท่านั้นที่{$what}ได้");
        }
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        // strict YYYY-MM-DD only: anything else (31/10/2026, 2026-02-30, a number, an array...) is refused, never an exception
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new PlanningRejected('วันที่ไม่ถูกต้อง');
        }

        return $value;
    }

    private function deptSubtasks(Project $project, User $user, mixed $deptKey): Collection
    {
        $deptId = $this->id($deptKey, 'd_');

        return WorkScope::projectSubtasks($project, $user)->where('department_id', $deptId)->with('cabinetTask.cabinet')->orderBy('id')->get();
    }

    private function open(Collection $subtasks): Collection
    {
        return $subtasks->reject(fn ($s) => $s->assignment_status === CabinetSubtask::ASSIGNMENT_COMPLETED)->values();
    }
}
