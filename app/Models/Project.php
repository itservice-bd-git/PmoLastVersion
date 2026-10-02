<?php

namespace App\Models;

use App\Models\Concerns\HasAuditFields;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory, HasAuditFields, LogsActivity;

    const STATUS_DRAFT = 'draft';

    const STATUS_PLANNING = 'planning';

    const STATUS_IN_PROGRESS = 'in_progress';

    const STATUS_ON_HOLD = 'on_hold';

    const STATUS_COMPLETED = 'completed';

    const STATUS_CANCELLED = 'cancelled';

    public static array $statuses = [
        self::STATUS_DRAFT => 'Draft',
        self::STATUS_PLANNING => 'Planning',
        self::STATUS_IN_PROGRESS => 'In Progress',
        self::STATUS_ON_HOLD => 'On Hold',
        self::STATUS_COMPLETED => 'Completed',
        self::STATUS_CANCELLED => 'Cancelled',
    ];

    const PRIORITY_LOW = 'low';

    const PRIORITY_NORMAL = 'normal';

    const PRIORITY_HIGH = 'high';

    const PRIORITY_URGENT = 'urgent';

    public static array $priorities = [
        self::PRIORITY_LOW => 'Low',
        self::PRIORITY_NORMAL => 'Normal',
        self::PRIORITY_HIGH => 'High',
        self::PRIORITY_URGENT => 'Urgent',
    ];

    protected $fillable = [
        'project_no',
        'project_name',
        'customer_name',
        'po_no',
        'sales_order_no',
        'owner_id',
        'sales_person_id',
        'sales_person',
        'project_owner',
        'project_manager_id',
        'start_date',
        'due_date',
        'description',
        'remark',
        'payment_terms',
        'job_type_id',
        'status',
        'priority',
        'color',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
        ];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function salesPerson()
    {
        return $this->belongsTo(User::class, 'sales_person_id');
    }

    public function projectManager()
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }

    public function jobType()
    {
        return $this->belongsTo(JobType::class);
    }

    public function projectTasks()
    {
        return $this->hasMany(ProjectTask::class)->orderBy('sequence');
    }

    public function cabinets()
    {
        return $this->hasMany(Cabinet::class)->orderBy('sequence');
    }

    public function attachments()
    {
        return $this->hasMany(ProjectAttachment::class)->latest();
    }

    public function getTaskProgressAttribute(): int
    {
        if (array_key_exists('project_tasks_avg_progress', $this->attributes)) {
            return (int) round($this->attributes['project_tasks_avg_progress'] ?? 0);
        }

        return (int) round($this->projectTasks()->avg('progress') ?? 0);
    }

    public function getProductionProgressAttribute(): int
    {
        if (array_key_exists('cabinets_avg_progress', $this->attributes)) {
            return (int) round($this->attributes['cabinets_avg_progress'] ?? 0);
        }

        return (int) round($this->cabinets()->avg('progress') ?? 0);
    }

    public function getCabinetSummaryAttribute(): array
    {
        $cabinets = $this->relationLoaded('cabinets') ? $this->cabinets : $this->cabinets()->get(['status']);

        return [
            'total' => $cabinets->count(),
            'completed' => $cabinets->where('status', 'completed')->count(),
            'in_progress' => $cabinets->where('status', 'in_progress')->count(),
            'not_started' => $cabinets->where('status', 'not_started')->count(),
            'on_hold' => $cabinets->where('status', 'on_hold')->count(),
        ];
    }

    public function getRiskLevelAttribute(): string
    {
        if ($this->status === self::STATUS_COMPLETED || $this->status === self::STATUS_CANCELLED) {
            return 'none';
        }

        if (! $this->due_date) {
            return 'none';
        }

        $today = now()->startOfDay();

        if ($today->greaterThan($this->due_date) && $this->production_progress < 100) {
            return 'overdue';
        }

        $daysRemaining = $this->days_remaining;

        if ($daysRemaining <= 7 && $this->production_progress < 80) {
            return 'at_risk';
        }

        return 'on_track';
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
        return $this->id;
    }

    protected function activityLabel(): string
    {
        return "โครงการ {$this->project_no}";
    }

    protected function activityLoggableFields(): array
    {
        return [
            'project_name' => 'ชื่อโครงการ',
            'customer_name' => 'ลูกค้า',
            'status' => 'สถานะ',
            'priority' => 'ความสำคัญ',
            'start_date' => 'วันเริ่ม',
            'due_date' => 'วันครบกำหนด',
            'project_owner' => 'เจ้าของโครงการ',
            'sales_person' => 'พนักงานขาย',
            'project_manager_id' => 'ผู้จัดการโครงการ',
            'payment_terms' => 'เงื่อนไขการชำระ',
            'job_type_id' => 'ประเภทงาน',
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

        if ($field === 'project_manager_id' && $value) {
            return User::find($value)?->name ?? "#{$value}";
        }

        if ($field === 'job_type_id' && $value) {
            return JobType::find($value)?->name ?? "#{$value}";
        }

        return $this->activityDefaultFormatValue($value);
    }
}
