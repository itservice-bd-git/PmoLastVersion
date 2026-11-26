<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\ProjectFat;
use App\Models\User;

/**
 * Everything the cabinet page (cabinets.show) reads from the database or works out with a service, before the view lays it
 * out (CLAUDE.md "Views do not query"): the checklist chips, the dates, its FAT, and for every Sub Task on the page its
 * checklist, its handoff links (HandoffService), its pairs and its working days. Call it after WorkScope has trimmed the
 * cabinet, so only the work this person sees is worked out.
 */
class CabinetPage
{
    public function __construct(
        private ChecklistEditor $editor,
        private HandoffService $handoff,
        private ChecklistStatuses $statuses,
        private ProjectDates $dates,
        private ProjectPage $projectPage,
        private WorkingDaysCalculator $workingDays,
    ) {}

    public function build(Cabinet $cabinet, User $user): array
    {
        $canPair = $user->canDispatchWork() && ! ProjectLock::isLocked($cabinet->project_id);   // Admin / PM, open project
        $subtasks = $cabinet->tasks->flatMap->subtasks;

        return [
            'canPair' => $canPair,
            'tagCatalog' => $this->statuses->catalog(),   // Settings > สีสถานะ Checklist
            'dates' => $this->dates->summary($cabinet),
            'canDates' => $canPair,
            // its วัน FAT once it is in a round (the latest one; a round of every cabinet counts)
            'cabFat' => ProjectFat::latestFor($cabinet->project->fats()->get(), $cabinet->id),
            'checklistVersion' => $this->editor->cabinetVersion($cabinet),
            // per Sub Task, by id: what its checklist editor, pair picker and dates window show
            'subtaskView' => $subtasks->mapWithKeys(fn ($s) => [$s->id => [
                'items' => $this->editor->items('subtask', $s),
                'dateLabel' => $this->handoff->dateLabelFor($s),
                'linked' => $this->handoff->linkedForSubtask($s),
                'receivers' => $this->handoff->receiversOf($s),
                'pairChosen' => $s->pairSources->map(fn ($p) => SubtaskPairs::chip($p))->values(),
                'workingDays' => $s->start_date && $s->due_date ? ($this->workingDays->countWorkingDays($s->start_date, $s->due_date) ?: '') : '',
            ]])->all(),
        ] + $this->projectPage->dateWindow($cabinet, $canPair);
    }
}
