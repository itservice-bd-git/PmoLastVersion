<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CabinetTask extends Model
{
    use HasFactory, LogsActivity;

    const STATUS_NOT_STARTED = 'not_started';

    const STATUS_IN_PROGRESS = 'in_progress';

    const STATUS_COMPLETED = 'completed';

    const STATUS_ON_HOLD = 'on_hold';

    public static array $statuses = [
        self::STATUS_NOT_STARTED => 'Not Started',
        self::STATUS_IN_PROGRESS => 'In Progress',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_ON_HOLD => 'On Hold',
    ];

    protected $fillable = [
        'cabinet_id',
        'cabinet_task_template_id',
        'name',
        'description',
        'weight',
        'status',
        'progress',
        'start_date',
        'due_date',
        'completed_date',
        'remark',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:2',
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_date' => 'date',
        ];
    }

    public function cabinet()
    {
        return $this->belongsTo(Cabinet::class);
    }

    public function taskTemplate()
    {
        return $this->belongsTo(CabinetTaskTemplate::class, 'cabinet_task_template_id');
    }

    public function subtasks()
    {
        return $this->hasMany(CabinetSubtask::class)->orderBy('sequence');
    }

    /**
     * Total / completed checklist counts across all of this task's sub tasks.
     */
    public function getChecklistCountsAttribute(): array
    {
        $total = 0;
        $completed = 0;

        foreach ($this->subtasks as $subtask) {
            $total += $subtask->checklists->count();
            $completed += $subtask->checklists->where('is_completed', true)->count();
        }

        return ['completed' => $completed, 'total' => $total];
    }

    /**
     * Calendar days left until due_date (negative = overdue by that many days).
     */
    public function getDaysRemainingAttribute(): ?int
    {
        if (! $this->due_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->due_date, false);
    }

    protected function activityProjectId(): ?int
    {
        return $this->cabinet?->project_id;
    }

    protected function activityLabel(): string
    {
        return "งาน \"{$this->name}\" (ตู้ {$this->cabinet?->mo_no})";
    }

    /**
     * progress/status intentionally excluded - see Cabinet::activityLoggableFields().
     */
    protected function activityLoggableFields(): array
    {
        return [
            'name' => 'ชื่องาน',
            'start_date' => 'วันเริ่ม',
            'due_date' => 'วันครบกำหนด',
            'remark' => 'หมายเหตุ',
        ];
    }
}
