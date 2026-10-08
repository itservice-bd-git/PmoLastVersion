<?php

namespace App\Services;

use App\Models\Project;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The one place a Project's editable header fields are validated and saved - used by the My Department project modal
 * (autosave) and by the /planning page. Callers decide WHO may edit (admin / PM); this decides WHAT is valid.
 */
class ProjectEditor
{
    public const RULES = [
        'project_name' => ['sometimes', 'required', 'string', 'max:255'],
        'status' => ['sometimes', 'required', 'in:draft,planning,in_progress,on_hold,completed,cancelled'],
        'priority' => ['sometimes', 'required', 'in:low,normal,high,urgent'],
        'start_date' => ['sometimes', 'nullable', 'date'],
        'due_date' => ['sometimes', 'nullable', 'date'],
        'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
    ];

    /** @throws ValidationException */
    public function update(Project $project, array $input): Project
    {
        $data = Validator::make(array_intersect_key($input, self::RULES), self::RULES)->validate();

        // the date order is judged on the values the Project will have AFTER this save
        $start = array_key_exists('start_date', $data) ? $data['start_date'] : $project->start_date?->format('Y-m-d');
        $due = array_key_exists('due_date', $data) ? $data['due_date'] : $project->due_date?->format('Y-m-d');
        if ($start && $due && $due < $start) {
            throw ValidationException::withMessages(['due_date' => 'วันครบกำหนดต้องไม่ก่อนวันเริ่ม']);
        }

        $project->update($data);

        return $project;
    }
}
