<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CabinetChecklist extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'cabinet_subtask_id',
        'checklist_template_id',
        'name',
        'description',
        'is_completed',
        'completed_by',
        'completed_at',
        'remark',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function subtask()
    {
        return $this->belongsTo(CabinetSubtask::class, 'cabinet_subtask_id');
    }

    public function checklistTemplate()
    {
        return $this->belongsTo(ChecklistTemplate::class);
    }

    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    protected function activityProjectId(): ?int
    {
        return $this->subtask?->cabinetTask?->cabinet?->project_id;
    }

    protected function activityLabel(): string
    {
        return "Checklist \"{$this->name}\"";
    }

    /**
     * is_completed toggles are logged explicitly by ProgressService::toggleChecklist
     * with a friendlier message, so that field is deliberately left out here
     * to avoid double-logging every check/uncheck.
     */
    protected function activityLoggableFields(): array
    {
        return [
            'remark' => 'หมายเหตุ',
        ];
    }
}
