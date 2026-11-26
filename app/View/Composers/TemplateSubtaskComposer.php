<?php

namespace App\View\Composers;

use App\Services\ChecklistEditor;
use App\Services\SubtaskPairs;
use Illuminate\View\View;

/** cabinet-templates.partials._subtask (the template page, and the reply that redraws one Sub Task): its checklist and its pairs. */
class TemplateSubtaskComposer
{
    public function __construct(private ChecklistEditor $editor) {}

    public function compose(View $view): void
    {
        $subtaskTemplate = $view->getData()['subtaskTemplate'];
        $view->with([
            'checklistItems' => $this->editor->items('template', $subtaskTemplate),
            'pairChosen' => $subtaskTemplate->pairSources->map(fn ($s) => SubtaskPairs::chip($s))->values(),
        ]);
    }
}
