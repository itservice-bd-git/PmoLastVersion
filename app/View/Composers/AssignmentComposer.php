<?php

namespace App\View\Composers;

use App\Services\PlanningBoard;
use App\Services\SubtaskAssignmentService;
use Illuminate\View\View;

/**
 * cabinets.partials._assignment (the cabinet page, จ่ายงาน, and the JSON replies that redraw one row): what this person
 * may do with the Sub Task now, and whether it has a checklist (then the checklist, not a button, finishes it).
 */
class AssignmentComposer
{
    public function __construct(private SubtaskAssignmentService $assignments) {}

    public function compose(View $view): void
    {
        $subtask = $view->getData()['subtask'];
        $view->with([
            'actions' => $this->assignments->actionsFor($subtask, auth()->user()),
            'hasList' => match (true) {
                $subtask->relationLoaded('checklists') => $subtask->checklists->isNotEmpty(),
                $subtask->getAttribute('checklists_exists') !== null => (bool) $subtask->checklists_exists,   // withExists (จ่ายงาน)
                default => $subtask->checklists()->exists(),
            },
            'departmentColors' => PlanningBoard::departmentColors(),   // once per request
        ]);
    }
}
