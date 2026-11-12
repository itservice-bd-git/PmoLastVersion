<?php

namespace App\Services;

use App\Exceptions\PlanningRejected;
use App\Models\ActivityLog;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetSubtaskTemplate;
use App\Models\ChecklistTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The one checklist editor behind every checklist list (a cabinet's Sub Task, a template's Sub Task): add many at once
 * (typed or pasted from Excel), edit in place, delete (with undo), reorder, move to another Sub Task, copy to others.
 *
 * Two kinds of list, one set of operations:
 *  - 'subtask'  : a cabinet's CabinetSubtask -> CabinetChecklist (name, remark, date, ticked). Who: isChecklistEditableBy()
 *                 (the department that accepted the work, or Admin / PM); a closed project refuses. Deletes are soft.
 *  - 'template' : a template's CabinetSubtaskTemplate -> ChecklistTemplate (name, description). Admin / PM only. Deletes are
 *                 real (undo re-creates them). Never touches cabinets already made from the template.
 *
 * Bulk changes are written WITHOUT per-row model events (one Activity Log line per operation, not one per item) - so the
 * closed-project guard and the board version are applied here by hand.
 */
class ChecklistEditor
{
    use Concerns\ChecklistEdits;
    use Concerns\ChecklistLive;

    public const MAX_ITEMS = 300;

    /** The list a pair shares (SharedChecklist): set per operation, cabinet lists only. */
    private ?SharedChecklist $shared = null;

    public function __construct(private ProgressService $progress) {}

    // ======================================================================= reading

    /** The list as the editor shows it (the move / copy choices are fetched separately, when the editor needs them). */
    public function state(string $kind, Model $owner, User $user): array
    {
        return [
            'items' => $this->items($kind, $owner),
            'editable' => $this->canEdit($kind, $owner, $user),
            'dateLabel' => $kind === 'subtask' ? app(HandoffService::class)->dateLabelFor($owner) : null,
        ];
    }

    /** Where this list's items may go / come from: move (same cabinet / template), copy to, copy in from. */
    public function targets(string $kind, Model $owner): array
    {
        $move = $this->moveTargets($kind, $owner);
        $copyTo = $this->copyTargets($kind, $owner);

        // copy IN: a cabinet takes another Sub Task of the same cabinet; a template takes any template's Sub Task (a ready-made set)
        return ['move' => $move, 'copyTo' => $copyTo, 'copyFrom' => $kind === 'subtask' ? $move : $copyTo];
    }

    public function items(string $kind, Model $owner): array
    {
        if ($kind === 'template') {
            $list = $owner->relationLoaded('checklistTemplates')
                ? $owner->checklistTemplates->sortBy(fn ($c) => sprintf('%09d-%09d', $c->sequence ?? 0, $c->id))->values()
                : $owner->checklistTemplates()->orderBy('sequence')->orderBy('id')->get();

            return $list
                ->map(fn (ChecklistTemplate $c) => ['id' => $c->id, 'name' => $c->name, 'remark' => (string) $c->description])->all();
        }

        // a page that already loaded the list (with completedBy) is not asked again
        $list = $owner->relationLoaded('checklists')
            ? $owner->checklists->sortBy(fn ($c) => sprintf('%09d-%09d', $c->sequence ?? 0, $c->id))->values()
            : $owner->checklists()->with('completedBy:id,name')->orderBy('sequence')->orderBy('id')->get();

        // the Sub Tasks comparing with this one (template pairs): has each dealt with each item yet
        $receipts = app(HandoffService::class)->receiptsFor($owner->setRelation('checklists', $list));
        $tags = app(ChecklistStatuses::class)->idsFor($list->pluck('id'));   // Settings > สีสถานะ Checklist
        $peers = app(ChecklistStatuses::class)->peersFor($list);   // the other side of a pair: read-only

        return $list
            ->map(fn (CabinetChecklist $c) => [
                'id' => $c->id, 'name' => $c->name, 'remark' => (string) $c->remark, 'date' => $c->expected_date?->format('Y-m-d'),
                'done' => (bool) $c->is_completed, 'doneBy' => $c->completedBy?->name, 'doneAt' => $c->completed_at?->thaiDateTime(),
                'toggleUrl' => route('checklists.toggle', $c), 'handoff' => $receipts[$c->id] ?? [],
                'from' => $c->source_checklist_id ? 'k'.$c->source_checklist_id : null,   // a copy of the item of the Sub Task it compares with
                'tags' => $tags[$c->id] ?? [],
                'peerTags' => $peers[$c->id] ?? [],
            ])->all();
    }

    // ======================================================================= permissions

    public function canEdit(string $kind, Model $owner, User $user): bool
    {
        return $kind === 'template' ? $user->allows('edit.templates') : $owner->isChecklistEditableBy($user);
    }

    private function authorize(string $kind, Model $owner, User $user): void
    {
        if (! $this->canEdit($kind, $owner, $user)) {
            throw new PlanningRejected($kind === 'template'
                ? 'แก้ Checklist ของเทมเพลตได้เฉพาะ Admin / PM'
                : 'แก้ Checklist ได้เฉพาะแผนกที่รับงานแล้ว (หรือ Admin / PM)');
        }
        if ($kind === 'subtask') {
            ProjectLock::assertOpen($owner->cabinetTask->cabinet->project_id);
        }
    }

    // ======================================================================= writing

    /**
     * One operation. Returns ['message', 'items' (the list after), 'progress' (subtask kind: for the page's bars),
     * 'undo' (what a "ยกเลิก" sends back)].
     *
     * @throws PlanningRejected
     */
    public function apply(string $kind, Model $owner, User $user, array $op): array
    {
        $this->authorize($kind, $owner, $user);
        $this->shared = $kind === 'subtask' ? app(SharedChecklist::class) : null;

        $result = DB::transaction(fn () => Model::withoutEvents(fn () => match ($op['type'] ?? null) {
            'add' => $this->add($kind, $owner, $op['items'] ?? []),
            'update' => $this->update($kind, $owner, $op),
            'delete' => $this->delete($kind, $owner, $op['ids'] ?? []),
            'restore' => $this->restore($kind, $owner, $op),
            'reorder' => $this->reorder($kind, $owner, $op['ids'] ?? []),
            'move' => $this->move($kind, $owner, $user, $op['ids'] ?? [], $op['to'] ?? null),
            'copy' => $this->copy($kind, $owner, $user, $op['ids'] ?? [], $op['to'] ?? []),
            'copyFrom' => $this->copyFrom($kind, $owner, $op['from'] ?? null),
            'tags' => $this->tags($kind, $owner, $user, $op),
            default => throw new PlanningRejected('ไม่รู้จักคำสั่งนี้'),
        }));

        $touched = array_unique(array_merge([$owner->id], $result['others'] ?? [], $this->shared?->takeTouched() ?? []));
        if ($kind === 'subtask') {
            foreach (CabinetSubtask::whereKey($touched)->get() as $s) {
                $this->progress->recalculateSubtask($s);
            }
            if (! empty($result['log'])) {
                ActivityLog::record($owner->cabinetTask->cabinet->project_id, $user->id, $owner, 'checklist_edited', $result['log']);
            }
        }
        PlanningBoard::touch();   // per-row events were off: the Planning board must still notice

        return [
            'message' => $result['message'] ?? null,
            'items' => $this->items($kind, $owner->fresh()),
            'progress' => $kind === 'subtask' ? $this->progressOf($owner->fresh()) : null,
            'undo' => $result['undo'] ?? null,
        ];
    }

    // ======================================================================= helpers

    public function owner(string $kind, int $id): ?Model
    {
        return $kind === 'template' ? CabinetSubtaskTemplate::find($id) : CabinetSubtask::with('cabinetTask.cabinet')->find($id);
    }

    private function query(string $kind, Model $owner)
    {
        return $kind === 'template' ? ChecklistTemplate::where('cabinet_subtask_template_id', $owner->id) : CabinetChecklist::where('cabinet_subtask_id', $owner->id);
    }

    // ======================================================================= the cabinet page, kept current without a reload

}
