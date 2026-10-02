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
        'is_active',
    ];

    const ROLE_ADMIN = 'admin';

    const ROLE_PROJECT_MANAGER = 'project_manager';

    const ROLE_SALES = 'sales';

    const ROLE_PRODUCTION = 'production';

    const ROLE_MEMBER = 'member';

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    /**
     * Admins and project managers may look at other departments' work
     * (e.g. My Department calendar); everyone else is limited to their own.
     */
    public function canViewOtherDepartments(): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_PROJECT_MANAGER], true);
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
            // Several permission checks compare this against CabinetSubtask::department_id
            // with strict `===` (e.g. isChecklistEditableBy(), isInAssignedDepartment()) -
            // both sides must be the same PHP type. See the cast comment on
            // CabinetSubtask::department_id for the full "why" (2026-09-30).
            'department_id' => 'integer',
        ];
    }
}
