<?php

namespace App\Services\Concerns;

use App\Models\ActivityLog;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\User;
use App\Notifications\PmoNotice;
use App\Services\ChecklistNoticeRules;
use App\Services\DeliveryFlow;
use App\Services\HandoffService;
use App\Services\PlanningBoard;
use App\Services\PlanningSync;
use App\Services\ProjectFollowers;
use App\Services\StatusReport;
use App\Services\WebPushService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * NotificationService · About a project as a whole: an alert to a department, an @mention, a comment, new followers, a new date (เลื่อน) and dates still to come from sales.
 * Part of NotificationService (a trait of it): who hears, the channels and the de-duplication are there.
 */
trait ProjectNotices
{
    /**
     * "ส่งเป็นการแจ้งเตือนไปแผนก" on a project comment: every active user of that department (not the sender)
     * gets an urgent notice that pops up on their next page load (kind 'alert' is in StatusReport::URGENT_KINDS).
     *
     * @return int how many people were notified
     */
    public function projectAlert(Project $project, Department $department, User $actor, string $note, ?ActivityLog $log = null): int
    {
        $users = User::ofDepartment($department->id)->where('is_active', true)->where('id', '!=', $actor->id)->get();

        $sent = $this->send($users, new PmoNotice(
            'alert',
            "{$actor->name}: {$project->project_no} {$project->project_name}",
            mb_strimwidth($note, 0, 160, '…'),
            route('planning.board', ['project' => $project->id]),
            projectId: $project->id,
            noteId: $log?->id,
        ));
        $this->addAckTargets($log);

        return $sent;
    }

    /**
     * "@name" in a project comment: that person gets an urgent notice (bell + popup) that opens the project on the Planning
     * page. The sender is never notified about tagging themselves.
     */
    public function mentioned(Project $project, User $actor, User $target, string $note, ?ActivityLog $log = null): int
    {
        if ($target->id === $actor->id || ! $target->is_active) {
            return 0;
        }

        $sent = $this->send(collect([$target]), new PmoNotice(
            'mention',
            "{$actor->name} แท็กคุณในโครงการ {$project->project_no}",
            mb_strimwidth($note, 0, 160, '…'),
            route('planning.board', ['project' => $project->id]),
            projectId: $project->id,
            noteId: $log?->id,
        ));
        $this->addAckTargets($log);

        return $sent;
    }

    /** Ticked as a project's พนักงานขาย (ProjectFollowers::setSales): they follow it now - its news comes to them. */
    public function followerAdded(Project $project, User $actor, Collection $people): int
    {
        $people = $people->reject(fn (User $u) => $u->id === $actor->id)->filter(fn (User $u) => \App\Services\WorkScope::canSeeProject($project, $u));

        return $this->send($people, new PmoNotice(
            'follow',
            "{$actor->name} ให้คุณติดตามโครงการ {$project->project_no}",
            $project->project_name.($project->customer_name ? ' · '.$project->customer_name : '').' — แจ้งเตือนความเคลื่อนไหวของโครงการนี้จะมาที่คุณ',
            route('planning.board', ['project' => $project->id]),
            projectId: $project->id,
            group: "project:{$project->id}",
            parts: ['follow' => 1],
            projectLabel: "{$project->project_no} · {$project->project_name}",
        ));
    }

    /**
     * A new comment / reply on a project: its PM, its people in charge, everyone who commented there before and the author of
     * the comment being answered - if they can open the project. $skip = people already told (tagged / alerted).
     *
     * @param  list<int>  $skip
     */
    public function commented(Project $project, User $actor, \App\Models\ActivityLog $note, array $skip = []): int
    {
        $ids = \App\Models\ActivityLog::where('project_id', $project->id)->where('action', 'note')->where('id', '!=', $note->id)->pluck('user_id')
            ->merge($this->peopleInCharge($project)->pluck('id'))
            ->merge($project->followers()->pluck('users.id'))
            ->filter()->unique()->reject(fn ($id) => $id === $actor->id || in_array($id, $skip, true));
        // the author of the comment being answered hears it too (even if they never wrote here otherwise - they did, by definition)

        $people = User::whereIn('id', $ids)->where('is_active', true)->get()->filter(fn (User $u) => \App\Services\WorkScope::canSeeProject($project, $u));

        return $this->send($people, new PmoNotice(
            'comment',
            ! empty($note->changes['reply_to']) ? "{$actor->name} ตอบกลับใน {$project->project_no}" : "{$actor->name} คอมเมนต์ใน {$project->project_no}",
            mb_strimwidth($note->description, 0, 160, '…'),
            route('planning.board', ['project' => $project->id]),
            projectId: $project->id,
            group: "project:{$project->id}",
            parts: ['comment' => 1],
            projectLabel: "{$project->project_no} · {$project->project_name}",
        ));
    }

    /**
     * A project's or a cabinet's due date was moved (ProjectDates, a round): the project's people in charge, its followers and
     * everyone in a department with work in it (in the cabinet, for a cabinet) hear when and why. The departments are told
     * their own dates stayed, and open the project on Planning.
     */
    public function projectRescheduled(Project $project, User $actor, \App\Models\ProjectDateChange $change, int $round, ?\App\Models\Cabinet $cabinet = null): int
    {
        $canSee = fn (Collection $ids) => User::whereIn('id', $ids)->where('is_active', true)->get()->filter(fn (User $u) => \App\Services\WorkScope::canSeeProject($project, $u));
        $people = $canSee($this->peopleInCharge($project, $actor)->pluck('id')->merge($project->followers()->pluck('users.id'))->unique()->reject(fn ($id) => $id === $actor->id));
        $deptIds = \App\Services\ProjectDates::departments($cabinet ?? $project)->pluck('id')->all();
        $deptPeople = $deptIds ? $canSee(User::ofDepartment($deptIds)->where('id', '!=', $actor->id)->pluck('id')->diff($people->pluck('id'))) : collect();

        $days = $change->dueDays();
        $title = $cabinet ? 'เลื่อนวันตู้ '.($cabinet->mo_no ?: $cabinet->cabinet_name)." ({$project->project_no}) ครั้งที่ {$round}" : "เลื่อนวันโครงการ {$project->project_no} ครั้งที่ {$round}";
        $body = ($cabinet ? 'กำหนดเสร็จ ' : 'ครบกำหนด ').($change->old_due_date?->thaiDate() ?? '-').' → '.($change->new_due_date?->thaiDate() ?? '-').($days ? ' ('.($days > 0 ? '+' : '').$days.' วัน)' : '')
            .($change->reason_name ? ' · '.$change->reason_name : '').($change->note ? ' — '.mb_strimwidth($change->note, 0, 120, '…') : '');
        $notice = fn (string $body, string $url) => new PmoNotice('project_rescheduled', $title, $body, $url,
            projectId: $project->id, group: "project:{$project->id}", parts: ['rescheduled' => 1], projectLabel: "{$project->project_no} · {$project->project_name}");

        return $this->send($people, $notice($body, $cabinet ? route('cabinets.show', $cabinet) : route('projects.show', $project)))
            + $this->send($deptPeople, $notice($body.' · วันส่งงานของแผนกยังเป็นวันเดิม', route('planning.board', ['project' => $project->id])));
    }

    /**
     * รอฝ่ายขายแจ้งกำหนดส่งอีกครั้ง begun: the project's people in charge and its followers (not the one who pressed it), as a
     * postponement is told. Not a ต้องทำ: nothing to chase until sales has the dates. $text is the activity log's line.
     */
    public function projectDatePending(Project $project, User $actor, string $text): int
    {
        $people = $this->peopleInCharge($project, $actor)->pluck('id')
            ->merge($project->followers()->pluck('users.id'))->unique()->reject(fn ($id) => $id === $actor->id);

        return $this->send(User::whereIn('id', $people)->where('is_active', true)->get()->filter(fn (User $u) => \App\Services\WorkScope::canSeeProject($project, $u)), new PmoNotice(
            'date_pending', Project::PENDING_LABEL." · {$project->project_no}", $text, route('projects.show', $project),
            projectId: $project->id, group: "project:{$project->id}", parts: ['date_pending' => 1],
            projectLabel: "{$project->project_no} · {$project->project_name}",
        ));
    }
}
