<?php

namespace App\Services;

use App\Models\CabinetSubtask;
use App\Models\Project;
use App\Models\User;

/**
 * Which dispatched Sub Tasks of a Project a user may see: PMO roles (admin / Project Manager) all of them, everyone
 * else only their own department's. One definition shared by My Department's project modal and the /planning page,
 * so the two can never disagree about who sees what.
 */
class WorkScope
{
    public const ACTIVE_STATUSES = [
        CabinetSubtask::ASSIGNMENT_ASSIGNED,
        CabinetSubtask::ASSIGNMENT_ACCEPTED,
        CabinetSubtask::ASSIGNMENT_IN_PROGRESS,
        CabinetSubtask::ASSIGNMENT_COMPLETED,
    ];

    public static function projectSubtasks(Project $project, User $user)
    {
        return CabinetSubtask::query()
            ->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $project->id))
            ->whereNotNull('department_id')
            ->whereIn('assignment_status', self::ACTIVE_STATUSES)
            ->when(! $user->canSeeAllWork(), fn ($q) => $q->where('department_id', $user->department_id));
    }

    public static function canSeeProject(Project $project, User $user): bool
    {
        return $user->canSeeAllWork() || self::projectSubtasks($project, $user)->exists();
    }
}
