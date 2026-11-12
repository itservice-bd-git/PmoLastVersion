<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PmoNotice;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use App\Services\Concerns\WorkNotices;
use App\Services\Concerns\ProjectNotices;
use App\Services\Concerns\NoticeReceipts;
use App\Services\Concerns\DeliveryNotices;
use App\Services\Concerns\Reminders;

/**
 * Who hears about what:
 *  - work handed to a department      -> that department's active users
 *  - department accepts/starts/finishes -> the project's Project Manager
 *  - due soon / overdue (daily job)   -> the department (+ PM when overdue)
 * The person who did the action is never notified about it.
 *
 * The kinds of notice live in traits (app/Services/Concerns): WorkNotices, ProjectNotices, NoticeReceipts, DeliveryNotices,
 * Reminders. This class keeps who hears what (the people helpers), batching, and send(): the preferences, collapsing,
 * de-duplication and web push every notice goes through. Callers use NotificationService only.
 */
class NotificationService
{
    use WorkNotices, ProjectNotices, NoticeReceipts, DeliveryNotices, Reminders;

    /** Work handed out while batch() runs: one notice per department and project at the end, not one per sub task. */
    private static ?array $batch = null;   // static: every NotificationService instance in this request shares it

    /**
     * Runs $fn and sends the "work handed to your department" notices it caused as ONE notice per department / project
     * ("มีงานใหม่ 10 งาน ใน SO…"), instead of one per sub task. Used around a Planning save (PlanningSync::apply).
     */
    public function batch(callable $fn): mixed
    {
        if (self::$batch !== null) {
            return $fn();   // already inside one
        }
        self::$batch = [];
        try {
            $result = $fn();
        } catch (\Throwable $e) {
            self::$batch = null;   // refused / failed: nothing it queued is sent
            throw $e;
        }
        $queued = self::$batch;
        self::$batch = null;
        foreach (collect($queued)->groupBy(fn ($q) => $q[0]->department_id.'|'.$q[0]->cabinetTask->cabinet->project_id.'|'.(int) $q[2]) as $group) {
            if ($group->count() === 1) {
                $this->assigned(...$group->first());
                continue;
            }
            [$first, $actor, $moved] = $group->first();
            $project = $first->cabinetTask->cabinet->project;
            $dues = $group->map(fn ($q) => $q[0]->fresh()?->due_date)->filter();
            $this->send($this->departmentUsers($first, $actor), new PmoNotice(
                'assigned',
                ($moved ? 'งานถูกย้ายมาที่แผนกของคุณ ' : 'มีงานใหม่ให้แผนกของคุณ ').$group->count().' งาน',
                "{$project->project_no} {$project->project_name}".($dues->isNotEmpty() ? ' · กำหนดส่ง '.$dues->min()->thaiDate().($dues->min()->ne($dues->max()) ? ' – '.$dues->max()->thaiDate() : '') : ''),
                route('planning.board', ['project' => $project->id, 'stage' => PlanningBoard::deptKey($first->department_id)]),
                projectId: $project->id,
                group: "project:{$project->id}",
                parts: ['assigned' => $group->count()],
                projectLabel: "{$project->project_no} · {$project->project_name}",
            ), departmentId: $first->department_id);
        }

        return $result;
    }

    /**
     * Who hears that a department accepted / started / finished: the project's PM, its people in charge, and whoever handed
     * this work out. When that is nobody, every active Admin / PM (so the news never goes nowhere). Never the person who did it.
     */
    private function followers(CabinetSubtask $subtask, User $actor): Collection
    {
        $project = $subtask->cabinetTask->cabinet->project;
        $dispatcher = \App\Models\ActivityLog::where('loggable_type', CabinetSubtask::class)->where('loggable_id', $subtask->id)
            ->whereIn('action', ['assigned', 'department_changed'])->latest('id')->value('user_id');
        $people = $this->peopleInCharge($project, $actor)
            ->merge(User::where('id', $dispatcher)->where('is_active', true)->where('id', '!=', $actor->id)->get())->unique('id');

        return ($people->isNotEmpty() ? $people : $this->pmoPeople($actor))->merge($this->projectFollowers($project, $actor))->unique('id')->values();
    }

    /** The people following the project (ProjectFollowers) who can see it, active, not $except. */
    private function projectFollowers(Project $project, ?User $except = null): Collection
    {
        return $project->followers()->where('users.is_active', true)->when($except, fn ($q) => $q->where('users.id', '!=', $except->id))->get()
            ->filter(fn (User $u) => \App\Services\WorkScope::canSeeProject($project, $u))->values();
    }

    /** The project's PM and its people in charge (Planning), active, not $except. */
    private function peopleInCharge(Project $project, ?User $except = null): Collection
    {
        return User::where('is_active', true)
            ->where(fn ($q) => $q->where('id', $project->project_manager_id)->orWhereIn('id', $project->assignees()->pluck('users.id')))
            ->when($except, fn ($q) => $q->where('id', '!=', $except->id))->get();
    }

    private function pmoPeople(?User $except = null): Collection
    {
        return User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_PROJECT_MANAGER])->where('is_active', true)
            ->when($except, fn ($q) => $q->where('id', '!=', $except->id))->get();
    }

    /** How many active people a department has (0 = nobody would ever see what is sent there). */
    public function headcount(int $departmentId): int
    {
        return User::ofDepartment($departmentId)->where('is_active', true)->count();
    }

    /** Which notice kinds each personal switch (Settings > การแจ้งเตือน) controls. Everything else (assigned, alert, mention, request) always arrives. */
    private const PREFERENCE_FOR_KIND = [
        'accepted' => 'progress', 'started' => 'progress', 'completed' => 'completed',
        'due_soon' => 'reminders', 'overdue' => 'reminders', 'undated' => 'reminders', 'waiting' => 'reminders',
        'comment' => 'comments', 'due_changed' => 'due_changes', 'project_rescheduled' => 'due_changes', 'project_done' => 'completed',
        'handoff' => 'progress', 'handoff_due' => 'reminders', 'delivery' => 'progress',
    ];

    /** @param  int|null  $departmentId  the department the news is about, for "only my department's work" */
    private function wants(User $user, ?NotificationPreference $pref, PmoNotice $notice, ?int $departmentId): bool
    {
        if (! $pref) {
            return true;
        }
        if (! $pref->enabled) {
            return false;
        }
        if (($switch = self::PREFERENCE_FOR_KIND[$notice->kind] ?? null) && ! $pref->{$switch}) {
            return false;
        }

        return ! ($pref->only_my_department && $departmentId && $user->department_id && ! $user->inDepartment($departmentId)
            && isset(self::PREFERENCE_FOR_KIND[$notice->kind]));
    }

    /** @var list<int> who the last send() reached (an alert / a tag records them on its comment, for "รับทราบ n/N") */
    private array $lastDelivered = [];

    private function send(Collection $users, PmoNotice $notice, bool $dedupe = false, ?int $departmentId = null): int
    {
        $count = 0;
        $delivered = collect();
        // "ต้องทำ" and "ความเคลื่อนไหว" of one project are two notices: new work never hides inside "Busbar รับงาน 3"
        if ($notice->group && PmoNotice::isTodo($notice->kind, $notice->parts)) {
            $notice = clone $notice;
            $notice->group .= ':todo';
        }
        $prefs = NotificationPreference::whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');

        foreach ($users as $user) {
            if (! $this->wants($user, $prefs->get($user->id), $notice, $departmentId)) {
                continue;
            }

            if ($dedupe && $notice->key && $this->alreadySent($user, $notice->key)) {
                continue;
            }

            if ($notice->group && $this->collapse($user, $notice)) {
                $delivered->push($user);
                $count++;
                continue;
            }

            $user->notify($notice->parts ? $this->asProjectNotice($notice) : $notice);
            $delivered->push($user);
            $count++;
        }

        // the same notice to their devices when the app is closed (Settings > การแจ้งเตือน › เครื่องนี้)
        app(WebPushService::class)->send($delivered, $notice->title, $notice->body, $notice->url, $notice->group,   // same group = replaces the one already on the device
            in_array($notice->kind, ['assigned', 'waiting'], true) ? ['accept' => true] : []);   // new / waiting work: a "รับงาน" button on the notice
        $this->lastDelivered = $delivered->pluck('id')->all();

        return $count;
    }

    /** How long a still-unread notice keeps absorbing new ones of its group (a project's notice: a week). */
    private const COLLAPSE_HOURS = 24 * 7;

    /** The project's notice: title = the project, body = the summary of everything that happened ("งานใหม่ 3 · …"). */
    private function asProjectNotice(PmoNotice $notice): PmoNotice
    {
        $n = clone $notice;   // kind stays the event's (the latest one), so filters by kind keep working
        $n->title = $notice->projectLabel ?? $notice->title;
        $n->latest = $notice->title.' — '.$notice->body;
        $n->body = PmoNotice::summary($notice->parts, isset($notice->parts['comment']) ? $notice->body : null);

        return $n;
    }

    /**
     * Folds $notice into the person's latest notice of the same group that they have NOT SEEN yet (unread, and newer than
     * the last time they opened the bell): one notice that counts up ("แผนกขาย รับงาน 18 งาน") and moves back to the top.
     * Once they have looked, the next event starts a new notice counting from 1, so a number never mixes old news with new.
     */
    private function collapse(User $user, PmoNotice $notice): bool
    {
        $seen = $user->notifications_seen_at;
        $existing = DatabaseNotification::where('notifiable_type', User::class)->where('notifiable_id', $user->id)
            ->where('type', PmoNotice::class)->whereNull('read_at')->where('created_at', '>=', now()->subHours(self::COLLAPSE_HOURS))
            ->when($seen, fn ($q) => $q->where('created_at', '>', $seen))
            ->where('data', 'like', '%"group":'.json_encode($notice->group, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'%')
            ->latest()->first();
        if (! $existing) {
            return false;
        }

        $data = $existing->data;
        $n = (int) ($data['count'] ?? 1) + $notice->count;
        if ($notice->parts) {   // a project's notice: add this event to its summary
            $parts = PmoNotice::mergeParts($data['parts'] ?? [], $notice->parts);
            $last = isset($notice->parts['comment']) ? $notice->body : ($data['last_comment'] ?? null);
            $data = array_merge($data, ['kind' => $notice->kind, 'parts' => $parts, 'last_comment' => $last, 'urgent' => PmoNotice::urgentParts($parts),
                'title' => $notice->projectLabel ?? $data['title'], 'body' => PmoNotice::summary($parts, $last), 'latest' => $notice->title.' — '.$notice->body,
                'keys' => array_values(array_unique(array_filter(array_merge($data['keys'] ?? [$data['key'] ?? null], [$notice->key])))), 'count' => $n]);
        } else {
            $data = array_merge($data, ['count' => $n, 'title' => str_replace(':n', (string) $n, $notice->groupTitle ?? $notice->title), 'body' => $notice->body]);
        }
        $existing->forceFill(['data' => array_merge($data, ['url' => $notice->url]), 'created_at' => now()])->save();

        return true;
    }

    private function alreadySent(User $user, string $key): bool
    {
        return DatabaseNotification::where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', PmoNotice::class)
            ->where('data', 'like', '%'.json_encode($key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).'%')
            ->exists();
    }

    private function departmentUsers(CabinetSubtask $subtask, ?User $except = null): Collection
    {
        if (! $subtask->department_id) {
            return collect();
        }

        return User::ofDepartment($subtask->department_id)->where('is_active', true)
            ->when($except, fn ($q) => $q->where('id', '!=', $except->id))->get();
    }

    private function projectManager(CabinetSubtask $subtask, ?User $except = null): Collection
    {
        $subtask->loadMissing('cabinetTask.cabinet.project');
        $managerId = $subtask->cabinetTask?->cabinet?->project?->project_manager_id;

        if (! $managerId || $managerId === $except?->id) {
            return collect();
        }

        return User::where('id', $managerId)->where('is_active', true)->get();
    }

    private function describe(CabinetSubtask $subtask): string
    {
        $cabinet = $subtask->cabinetTask->cabinet;

        return "{$subtask->name} ({$cabinet->project->project_no} / {$cabinet->mo_no})";
    }

    private function dueText(CabinetSubtask $subtask): string
    {
        return $subtask->due_date ? ' · กำหนดส่ง '.$subtask->due_date->thaiDate() : '';
    }
}
