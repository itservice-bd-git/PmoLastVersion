<?php

namespace App\Models;

use App\Models\Concerns\HasAuditFields;
use App\Models\Concerns\GuardsClosedProject;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectTask extends Model
{
    use HasFactory, HasAuditFields, LogsActivity, GuardsClosedProject;

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

    public static array $priorities = [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    protected $fillable = [
        'project_id',
        'name',
        'description',
        'owner_id',
        'start_date',
        'due_date',
        'status',
        'priority',
        'progress',
        'remark',
        'sequence',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->due_date
            && $this->due_date->isPast()
            && $this->status !== self::STATUS_COMPLETED;
    }

    protected function activityProjectId(): ?int
    {
        return $this->project_id;
    }

    protected function activityLabel(): string
    {
        return "งานโครงการ \"{$this->name}\"";
    }

    protected function activityLoggableFields(): array
    {
        return [
            'name' => 'ชื่องาน',
            'status' => 'สถานะ',
            'priority' => 'ความสำคัญ',
            'progress' => 'ความคืบหน้า',
            'start_date' => 'วันเริ่ม',
            'due_date' => 'วันครบกำหนด',
            'owner_id' => 'ผู้รับผิดชอบ',
        ];
    }

    protected function activityFormatValue(string $field, $value): string
    {
        if ($field === 'status' && $value) {
            return self::$statuses[$value] ?? $value;
        }

        if ($field === 'priority' && $value) {
            return self::$priorities[$value] ?? $value;
        }

        if ($field === 'progress') {
            return $value.'%';
        }

        if ($field === 'owner_id' && $value) {
            return User::find($value)?->name ?? "#{$value}";
        }

        return $this->activityDefaultFormatValue($value);
    }
}
