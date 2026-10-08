<?php

namespace App\Services;

use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\ProjectRequest;
use App\Models\User;
use App\Notifications\PmoNotice;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * Who hears about what:
 *  - work handed to a department      -> that department's active users
 *  - department accepts/starts/finishes -> the project's Project Manager
 *  - due soon / overdue (daily job)   -> the department (+ PM when overdue)
 * The person who did the action is never notified about it.
 */
class NotificationService
{
    public function assigned(CabinetSubtask $subtask, User $actor, bool $moved): void
    {
        $subtask->loadMissing('cabinetTask.cabinet.project', 'department');

        $this->send(
            $this->departmentUsers($subtask, $actor),
            new PmoNotice(
                'assigned',
                $moved ? 'งานถูกย้ายมาที่แผนกของคุณ' : 'มีงานใหม่ถูกมอบหมายให้แผนกของคุณ',
                $this->describe($subtask).$this->dueText($subtask),
                route('planning.board'),
            )
        );
    }

    /**
     * "ส่งเป็นการแจ้งเตือนไปแผนก" on a project comment: every active user of that department (not the sender)
     * gets an urgent notice that pops up on their next page load (kind 'alert' is in StatusReport::URGENT_KINDS).
     *
     * @return int how many people were notified
     */
    public function projectAlert(Project $project, Department $department, User $actor, string $note): int
    {
        $users = User::where('department_id', $department->id)->where('is_active', true)->where('id', '!=', $actor->id)->get();

        return $this->send($users, new PmoNotice(
            'alert',
            "{$actor->name}: {$project->project_no} {$project->project_name}",
            mb_strimwidth($note, 0, 160, '…'),
            route('planning.board', ['project' => $project->id]),
            projectId: $project->id,
        ));
    }

    /**
     * "@name" in a project comment: that person gets an urgent notice (bell + popup) that opens the project on the Planning
     * page. The sender is never notified about tagging themselves.
     */
    public function mentioned(Project $project, User $actor, User $target, string $note): int
    {
        if ($target->id === $actor->id || ! $target->is_active) {
            return 0;
        }

        return $this->send(collect([$target]), new PmoNotice(
            'mention',
            "{$actor->name} แท็กคุณในโครงการ {$project->project_no}",
            mb_strimwidth($note, 0, 160, '…'),
            route('planning.board', ['project' => $project->id]),
            projectId: $project->id,
        ));
    }

    /** A new request arrived on the public form -> every active admin / Project Manager (the people who can import it). */
    public function requestReceived(ProjectRequest $request): void
    {
        $this->send(
            User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_PROJECT_MANAGER])->where('is_active', true)->get(),
            new PmoNotice(
                'request',
                'มีคำขอโครงการใหม่จากหน้าเว็บ',
                $request->title.' · '.$request->customer_name,
                route('project-requests.index'),
            )
        );
    }

    public function progressed(CabinetSubtask $subtask, User $actor, string $action): void
    {
        $subtask->loadMissing('cabinetTask.cabinet.project', 'department');

        $verb = ['accepted' => 'รับงาน', 'started' => 'เริ่มงาน', 'completed' => 'ทำงานเสร็จแล้ว'][$action] ?? $action;

        $this->send(
            $this->projectManager($subtask, $actor),
            new PmoNotice(
                $action,
                "แผนก {$subtask->department?->name} {$verb}",
                $this->describe($subtask),
                route('cabinets.show', $subtask->cabinetTask->cabinet),
            ),
            departmentId: $subtask->department_id,
        );
    }

    /**
     * Daily reminders. Returns how many notices were sent. A due-soon reminder goes
     * out once per Sub Task; an overdue one once per day until the work is done.
     */
    public function sendReminders(int $soonDays = 2): int
    {
        $sent = 0;

        CabinetSubtask::query()
            ->whereNotNull('department_id')->whereNotNull('due_date')
            ->whereIn('assignment_status', [CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS])
            ->whereDate('due_date', '<=', today()->addDays($soonDays))
            ->with('cabinetTask.cabinet.project', 'department')
            ->each(function (CabinetSubtask $subtask) use (&$sent) {
                $project = $subtask->cabinetTask?->cabinet?->project;

                // A closed project is read-only and finished - nobody needs chasing about it.
                if (! $project || $project->isLocked()) {
                    return;
                }

                $days = (int) today()->diffInDays($subtask->due_date, false);
                $overdue = $days < 0;

                $notice = new PmoNotice(
                    $overdue ? 'overdue' : 'due_soon',
                    $overdue ? 'งานเกินกำหนดแล้ว '.abs($days).' วัน' : ($days === 0 ? 'งานครบกำหนดวันนี้' : "งานครบกำหนดในอีก {$days} วัน"),
                    $this->describe($subtask),
                    route('planning.board'),
                    $overdue ? "overdue:{$subtask->id}:".today()->toDateString() : "due_soon:{$subtask->id}",
                );

                $recipients = $this->departmentUsers($subtask)
                    ->when($overdue, fn ($c) => $c->merge($this->projectManager($subtask))->unique('id'));

                $sent += $this->send($recipients, $notice, dedupe: true, departmentId: $subtask->department_id);
            });

        return $sent;
    }

    /** Which notice kinds each personal switch (Settings > การแจ้งเตือน) controls. Everything else (assigned, alert, mention, request) always arrives. */
    private const PREFERENCE_FOR_KIND = [
        'accepted' => 'progress', 'started' => 'progress', 'completed' => 'completed',
        'due_soon' => 'reminders', 'overdue' => 'reminders',
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

        return ! ($pref->only_my_department && $departmentId && $user->department_id && $user->department_id !== $departmentId
            && isset(self::PREFERENCE_FOR_KIND[$notice->kind]));
    }

    private function send(Collection $users, PmoNotice $notice, bool $dedupe = false, ?int $departmentId = null): int
    {
        $count = 0;
        $prefs = NotificationPreference::whereIn('user_id', $users->pluck('id'))->get()->keyBy('user_id');

        foreach ($users as $user) {
            if (! $this->wants($user, $prefs->get($user->id), $notice, $departmentId)) {
                continue;
            }

            if ($dedupe && $notice->key && $this->alreadySent($user, $notice->key)) {
                continue;
            }

            $user->notify($notice);
            $count++;
        }

        return $count;
    }

    private function alreadySent(User $user, string $key): bool
    {
        return DatabaseNotification::where('notifiable_type', User::class)
            ->where('notifiable_id', $user->id)
            ->where('type', PmoNotice::class)
            ->where('data', 'like', '%"key":"'.$key.'"%')
            ->exists();
    }

    private function departmentUsers(CabinetSubtask $subtask, ?User $except = null): Collection
    {
        if (! $subtask->department_id) {
            return collect();
        }

        return User::where('department_id', $subtask->department_id)->where('is_active', true)
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
        return $subtask->due_date ? ' · กำหนดส่ง '.$subtask->due_date->format('d/m/Y') : '';
    }
}
