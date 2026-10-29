<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'department_id',
        'position',
        'avatar_path',
        'is_active',
    ];

    const ROLE_ADMIN = 'admin';

    const ROLE_PROJECT_MANAGER = 'project_manager';

    const ROLE_SALES = 'sales';

    const ROLE_PRODUCTION = 'production';

    const ROLE_MEMBER = 'member';

    /** The profile picture, or null (the page then draws the initial on a colour). */
    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? asset('uploads/avatars/'.$this->avatar_path) : null;
    }

    public function notificationPreference()
    {
        return $this->hasOne(NotificationPreference::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /** The departments this person looks after on top of their own (Settings > ผู้ใช้งาน): they work for them as a member. */
    public function extraDepartments()
    {
        return $this->belongsToMany(Department::class, 'department_user')->withTimestamps();
    }

    /** @var list<int>|null */
    private ?array $departmentIdsCache = null;

    /**
     * Every department this person works for: their own, then the ones they look after too. Use this (or inDepartment())
     * wherever "is this my department's work" is asked - never department_id alone. Permissions (Settings > สิทธิ์การใช้งาน)
     * still come from their own department.
     *
     * @return list<int>
     */
    public function departmentIds(): array
    {
        return $this->departmentIdsCache ??= array_values(array_unique(array_filter(array_merge(
            [$this->department_id], $this->id ? $this->extraDepartments()->pluck('departments.id')->map(fn ($id) => (int) $id)->all() : []))));
    }

    public function inDepartment(?int $departmentId): bool
    {
        return $departmentId !== null && in_array($departmentId, $this->departmentIds(), true);
    }

    /** People who work for a department: its members and the ones looking after it too. */
    public function scopeOfDepartment($query, int|array $departmentIds)
    {
        $ids = (array) $departmentIds;

        return $query->where(fn ($q) => $q->whereIn('department_id', $ids)->orWhereHas('extraDepartments', fn ($d) => $d->whereIn('departments.id', $ids)));
    }

    /** Sub Tasks this user bookmarked ("ติดดาว") - personal, see MyDepartmentController::toggleStar(). */
    public function starredSubtasks()
    {
        return $this->belongsToMany(CabinetSubtask::class, 'subtask_stars')->withTimestamps();
    }

    /**
     * Admins and project managers may look at other departments' work
     * (e.g. My Department calendar); everyone else is limited to their own.
     */
    public function canViewOtherDepartments(): bool
    {
        return $this->allows('edit.manage');   // the PM-level power (Settings > สิทธิ์การใช้งาน); Admin always
    }

    /** One permission of Settings > สิทธิ์การใช้งาน (App\Support\Access) - Admin may everything. */
    public function allows(string $permission): bool
    {
        return \App\Support\Access::allows($this, $permission);
    }

    /** May SEE every project (read only): a PMO role, or a member of a department an admin marked "sees everything". */
    /** "ดูได้อย่างเดียว": the department may not work (Settings > สิทธิ์การใช้งาน, edit.work), e.g. ขาย following projects. */
    public function isViewOnly(): bool
    {
        return ! $this->allows('edit.work');
    }

    public function canSeeAllWork(): bool
    {
        return $this->canViewOtherDepartments() || $this->allows('scope.all_projects') || (bool) $this->department?->sees_all;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Admins and project managers may (re)assign a Sub Task's department -
     * see SubtaskAssignmentService::changeDepartment() and the Dispatch page.
     * Same role set as canViewOtherDepartments(): the PMO-level roles that
     * operate across every department rather than just their own.
     */
    public function canDispatchWork(): bool
    {
        return $this->canViewOtherDepartments();
    }

    /** The trash page, restoring from it (Settings > สิทธิ์การใช้งาน: edit.trash). */
    public function canManageTrash(): bool
    {
        return $this->allows('edit.trash');
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'notifications_seen_at' => 'datetime',   // last opened the bell (NotificationService::seen)
            // Several permission checks compare this against CabinetSubtask::department_id
            // with strict `===` (e.g. isChecklistEditableBy(), isInAssignedDepartment()) -
            // both sides must be the same PHP type. See the cast comment on
            // CabinetSubtask::department_id for the full "why" (2026-09-30).
            'department_id' => 'integer',
        ];
    }
}
