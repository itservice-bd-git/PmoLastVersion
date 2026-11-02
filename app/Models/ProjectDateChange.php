<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One change of a project's (cabinet_id null) or a cabinet's start / due date (App\Services\ProjectDates): a round of postponing, or a corrected date. */
class ProjectDateChange extends Model
{
    public const POSTPONE = 'postpone';

    public const CORRECT = 'correct';

    protected $fillable = ['project_id', 'cabinet_id', 'kind', 'old_start_date', 'new_start_date', 'old_due_date', 'new_due_date', 'reason_id', 'reason_name', 'note', 'changed_by'];

    protected function casts(): array
    {
        return ['old_start_date' => 'date', 'new_start_date' => 'date', 'old_due_date' => 'date', 'new_due_date' => 'date'];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function reason()
    {
        return $this->belongsTo(ProjectDateReason::class, 'reason_id');
    }

    public function changedBy()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /** Days the due date moved (+ later, - earlier); null when this change left the due date alone. */
    public function dueDays(): ?int
    {
        return $this->old_due_date && $this->new_due_date && ! $this->old_due_date->eq($this->new_due_date)
            ? (int) $this->old_due_date->diffInDays($this->new_due_date, false) : null;
    }
}
