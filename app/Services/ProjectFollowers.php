<?php

namespace App\Services;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * People following a project for its news (project_followers): its พนักงานขาย, ticked on the project form, and anyone who
 * can see it and pressed "ติดตามโครงการ". They hear what its people in charge hear (NotificationService::watchers): work
 * accepted / started / done / sent back, comments, the whole project done, and the project being made. Following gives no
 * sight of a project: a follower who cannot see it hears nothing of it.
 */
class ProjectFollowers
{
    public const SALES = 'sales';

    public const SELF = 'self';

    public function __construct(private NotificationService $notifications) {}

    /** The people the form offers as พนักงานขาย: role "sales", or in a department named ขาย / Sale. */
    public static function salesPeople(): Collection
    {
        return User::where('is_active', true)
            ->where(fn ($q) => $q->where('role', User::ROLE_SALES)
                ->orWhereHas('department', fn ($d) => $d->where('name', 'like', '%ขาย%')->orWhere('name', 'like', '%sale%')))
            ->orderBy('name')->get(['id', 'name', 'department_id']);
    }

    /** sales_person as saved: the ticked people's names, then any typed for someone without an account. @param list<int> $userIds */
    public static function salesText(array $userIds, ?string $others): ?string
    {
        $names = User::whereIn('id', $userIds)->orderBy('name')->pluck('name')
            ->merge(collect(preg_split('/\s*,\s*/u', (string) $others))->filter())->unique()->implode(', ');

        return $names !== '' ? mb_substr($names, 0, 255) : null;
    }

    /**
     * The project form's พนักงานขาย: the ticked people follow it (the ones unticked, if they followed only as its sales,
     * stop). Newly ticked people hear it.
     *
     * @param  list<int>  $userIds
     */
    public function setSales(Project $project, array $userIds, User $actor): void
    {
        $people = User::whereIn('id', $userIds)->where('is_active', true)->orderBy('name')->get();
        $current = $project->followers()->get();
        $project->followers()->detach($current->where('pivot.source', self::SALES)->pluck('id')->diff($people->pluck('id'))->all());
        $new = $people->reject(fn ($u) => $current->contains('id', $u->id));
        $project->followers()->attach($new->mapWithKeys(fn ($u) => [$u->id => ['source' => self::SALES]])->all());
        // already following by themselves: now also its sales
        $project->followers()->newPivotStatement()->where('project_id', $project->id)->whereIn('user_id', $people->pluck('id'))->update(['source' => self::SALES]);

        $this->notifications->followerAdded($project, $actor, $new);
    }

    /** "ติดตามโครงการ" / "เลิกติดตาม" (anyone who can see the project). @return bool following now */
    public function toggle(Project $project, User $user): bool
    {
        if ($project->followers()->whereKey($user->id)->exists()) {
            $project->followers()->detach($user->id);

            return false;
        }
        $project->followers()->attach($user->id, ['source' => self::SELF]);

        return true;
    }

    /** The names typed in sales_person that are not one of these people (shown back in the form's "ชื่ออื่น"). */
    public static function otherNames(?string $salesPerson, Collection $people): string
    {
        return collect(preg_split('/\s*,\s*/u', (string) $salesPerson))->filter()
            ->reject(fn ($n) => $people->contains('name', $n))->implode(', ');
    }
}
