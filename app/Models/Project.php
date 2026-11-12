<?php

namespace App\Models;

use App\Models\Concerns\CascadesSoftDeletes;
use App\Models\Concerns\HasAuditFields;
use App\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Services\ProjectLock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Project extends Model
{
    use HasFactory, HasAuditFields, LogsActivity, SoftDeletes, CascadesSoftDeletes;
    use Concerns\ProjectMoney;
    use Concerns\ProjectProgress;

    /** Statuses after which the project and everything under it is read-only (see ProjectLock). */
    public const LOCKED_STATUSES = ['completed', 'cancelled'];

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
        'customer_phone',
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
        'contract_value',
        'margin',
        'job_type_id',
        'status',
        'priority',
        'color',
        'board_id',
        'board_status_id',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'due_date' => 'date',
            'due_pending_at' => 'datetime',
            'contract_value' => 'decimal:2',
            'margin' => 'decimal:2',
        ];
    }

    /** Thai VAT on the contract value (มูลค่างาน is before VAT). */
    public const VAT_RATE = 0.07;

    /** Who may be a project's Project Manager: the PMO roles (admin / project manager). */
    public const MANAGER_ROLES = [User::ROLE_ADMIN, User::ROLE_PROJECT_MANAGER];

    public function projectManager()
    {
        return $this->belongsTo(User::class, 'project_manager_id');
    }

    public function board()
    {
        return $this->belongsTo(Board::class);
    }

    public function boardStatus()
    {
        return $this->belongsTo(BoardStatus::class);
    }

    public function boardLabels()
    {
        return $this->belongsToMany(BoardLabel::class);
    }

    /** People in charge (on top of the departments that hold the work) - set on the Planning page. */
    public function assignees()
    {
        return $this->belongsToMany(User::class);
    }

    /** วัน FAT, every round (ProjectFat) */
    public function fats()
    {
        return $this->hasMany(ProjectFat::class)->orderBy('round');
    }

    /** Its own start / due date changes (not its cabinets'), oldest first (App\Services\ProjectDates). */
    public function dateChanges()
    {
        return $this->hasMany(ProjectDateChange::class)->whereNull('cabinet_id')->orderBy('id');
    }

    /** the round that counts now (the calendar shows only this one) */
    public function latestFat()
    {
        return $this->hasOne(ProjectFat::class)->ofMany('round', 'max');
    }

    /** การจัดส่ง: its delivery rounds, oldest first (ProjectDeliveryController). */
    public function deliveries()
    {
        return $this->hasMany(ProjectDelivery::class)->orderBy('round');
    }

    /** People following it for its news (ProjectFollowers): its พนักงานขาย and whoever pressed "ติดตามโครงการ". */
    public function followers()
    {
        return $this->belongsToMany(User::class, 'project_followers')->withPivot('source')->withTimestamps();
    }

    public function jobType()
    {
        return $this->belongsTo(JobType::class);
    }

    public function projectTasks()
    {
        return $this->hasMany(ProjectTask::class)->orderBy('sequence');
    }

    /**
     * In progress and past its due date ($late = false: in progress and not past it, or with no due date). One rule for
     * the dashboard's "ล่าช้า" / "ตามแผน" counts and the project list they link to.
     */
    public function scopeRunningLate($query, bool $late = true)
    {
        $query->where('status', self::STATUS_IN_PROGRESS);

        // รอฝ่ายขายแจ้งกำหนดส่ง (due_pending_at) is never late: it counts as on track until the new date comes
        return $late
            ? $query->whereNotNull('due_date')->whereDate('due_date', '<', today())->whereNull('due_pending_at')
            : $query->where(fn ($q) => $q->whereNull('due_date')->orWhereDate('due_date', '>=', today())->orWhereNotNull('due_pending_at'));
    }

    /** Only the projects this person may see (App\Services\WorkScope - the same rule as opening one). */
    public function scopeVisibleTo($query, User $user)
    {
        return \App\Services\WorkScope::visibleProjects($query, $user);
    }

    public function cabinets()
    {
        return $this->hasMany(Cabinet::class)->orderBy('sequence');
    }

    protected static function booted(): void
    {
        // A closed project can only be edited to re-open it (status change). Colour is a
        // per-user calendar preference, not project data, so it stays changeable.
        static::updating(function (self $project) {
            $changed = array_diff(array_keys($project->getDirty()), ['color', 'updated_by', 'updated_at']);

            if ($changed && in_array($project->getOriginal('status'), self::LOCKED_STATUSES, true) && ! $project->isDirty('status')) {
                ProjectLock::assertOpen($project->getKey());
            }
        });

        // Settings > กฎการทำงาน: "โครงการกดเสร็จไม่ได้ ถ้ายังมีงานที่แผนกยังไม่เสร็จ"
        static::updating(function (self $project) {
            if ($project->isDirty('status') && $project->status === 'completed' && AppSetting::get('block_project_done_needs_work')
                && CabinetSubtask::query()
                    ->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $project->getKey()))
                    ->whereNotNull('department_id')
                    ->whereIn('assignment_status', [CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS])
                    ->exists()) {
                throw ValidationException::withMessages(['status' => 'ปิดโครงการไม่ได้ — ยังมีงานของแผนกที่ไม่เสร็จ (ตั้งค่าไว้ที่ ตั้งค่า › กฎการทำงาน)']);
            }
        });

        foreach (['saved', 'deleted', 'restored'] as $event) {
            static::$event(fn (self $project) => ProjectLock::forget($project->getKey()));
        }
    }

    public function isLocked(): bool
    {
        return in_array($this->status, self::LOCKED_STATUSES, true);
    }

    /** What a project waiting for its new dates is called everywhere (ProjectDates). */
    public const PENDING_LABEL = 'รอฝ่ายขายแจ้งกำหนดส่ง';

    /** Its dates are put off with no new ones yet (รอฝ่ายขายแจ้งกำหนดส่งอีกครั้ง): never late meanwhile (an open project only). */
    public function isDuePending(): bool
    {
        return $this->due_pending_at !== null && ! $this->isLocked();
    }

    public function duePendingLabel(): string
    {
        return self::PENDING_LABEL;
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
            'customer_phone' => 'เบอร์ติดต่อ',
            'contract_value' => 'มูลค่างาน',
            'margin' => 'Margin',
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
