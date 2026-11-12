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
 * NotificationService · Work handed to a department and how it moves: assigned / moved, accepted / started / finished, refused, a person put in charge, due dates changed, a nudge, a hand-off between departments.
 * Part of NotificationService (a trait of it): who hears, the channels and the de-duplication are there.
 */
trait WorkNotices
{
    public function assigned(CabinetSubtask $subtask, User $actor, bool $moved): void
    {
        if (self::$batch !== null) {
            $subtask->loadMissing('cabinetTask.cabinet.project', 'department');
            self::$batch[] = [$subtask, $actor, $moved];

            return;
        }
        $subtask->loadMissing('cabinetTask.cabinet.project', 'department');

        $this->send(
            $this->departmentUsers($subtask, $actor),
            new PmoNotice(
                'assigned',
                $moved ? 'งานถูกย้ายมาที่แผนกของคุณ' : 'มีงานใหม่ถูกมอบหมายให้แผนกของคุณ',
                $this->describe($subtask).$this->dueText($subtask),
                // opens the project AND the department's window, where its "รับงาน" button is
                route('planning.board', ['project' => $subtask->cabinetTask->cabinet->project_id, 'stage' => PlanningBoard::deptKey($subtask->department_id)]),
                projectId: $subtask->cabinetTask->cabinet->project_id,
                group: "project:{$subtask->cabinetTask->cabinet->project_id}",
                parts: ['assigned' => 1],
                projectLabel: "{$subtask->cabinetTask->cabinet->project->project_no} · {$subtask->cabinetTask->cabinet->project->project_name}",
            ),
            departmentId: $subtask->department_id,
        );
    }

    /** Made one of the people in charge of a project on the Planning page. */
    public function assignedPerson(Project $project, User $actor, User $target): int
    {
        if ($target->id === $actor->id || ! $target->is_active) {
            return 0;
        }

        return $this->send(collect([$target]), new PmoNotice(
            'assigned',
            "{$actor->name} ให้คุณเป็นผู้รับผิดชอบ {$project->project_no}",
            $project->project_name,
            route('planning.board', ['project' => $project->id]),
            projectId: $project->id,
            group: "project:{$project->id}",
            parts: ['owner' => 1],
            projectLabel: "{$project->project_no} · {$project->project_name}",
        ));
    }

    public function progressed(CabinetSubtask $subtask, User $actor, string $action): void
    {
        $subtask->loadMissing('cabinetTask.cabinet.project', 'department');
        $project = $subtask->cabinetTask->cabinet->project;

        $verb = ['accepted' => 'รับงาน', 'started' => 'เริ่มงาน', 'completed' => 'ทำงานเสร็จแล้ว'][$action] ?? $action;

        $this->send(
            $this->followers($subtask, $actor),
            new PmoNotice(
                $action,
                "แผนก {$subtask->department?->name} {$verb}",
                $this->describe($subtask),
                route('planning.board', ['project' => $project->id]),
                projectId: $project->id,
                group: "project:{$project->id}",
                parts: ["{$action}|{$subtask->department?->name}" => 1],
                projectLabel: "{$project->project_no} · {$project->project_name}",
            ),
            departmentId: $subtask->department_id,
        );

        // the last unfinished piece of work of the whole project: tell the people in charge once
        if ($action === 'completed' && ! CabinetSubtask::query()
            ->whereHas('cabinetTask.cabinet', fn ($q) => $q->where('project_id', $project->id))
            ->whereIn('assignment_status', [CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS])
            ->exists()) {
            $this->send($this->peopleInCharge($project, $actor)->merge($this->projectFollowers($project, $actor))->unique('id'), new PmoNotice(
                'project_done', "งานทุกแผนกของ {$project->project_no} เสร็จแล้ว", $project->project_name.' · ปิดโครงการได้ที่หน้าโครงการ',
                route('projects.show', $project), "project_done:{$project->id}", projectId: $project->id,
                group: "project:{$project->id}", parts: ['project_done' => 1], projectLabel: "{$project->project_no} · {$project->project_name}",
            ), dedupe: true);
        }
    }

    /** "ไม่รับงาน": whoever handed the work out (and the project's people in charge) hear it, with the reason, to hand it out again. */
    public function rejected(CabinetSubtask $subtask, string $department, User $actor, string $reason): void
    {
        $subtask->loadMissing('cabinetTask.cabinet.project');
        $project = $subtask->cabinetTask->cabinet->project;

        $this->send($this->followers($subtask, $actor), new PmoNotice(
            'rejected',
            "แผนก {$department} ไม่รับงาน",
            $this->describe($subtask)." — เหตุผล: {$reason}",
            route('planning.board', ['project' => $project->id]),
            projectId: $project->id,
            group: "project:{$project->id}",
            parts: ["rejected|{$department}" => 1],
            projectLabel: "{$project->project_no} · {$project->project_name}",
        ));
    }

    /**
     * Due dates of a department's work were moved by someone else: that department hears it, once per department / project.
     *
     * @param  Collection<int, CabinetSubtask>  $subtasks  the sub tasks whose due date changed (already saved)
     */
    public function dueChanged(Collection $subtasks, User $actor): int
    {
        $sent = 0;
        // a member moving their own department's dates is not news to that department
        foreach ($subtasks->filter(fn ($s) => $s->department_id && ! $actor->inDepartment($s->department_id))->groupBy(fn ($s) => $s->department_id.'|'.$s->cabinetTask->cabinet->project_id) as $group) {
            $first = $group->first()->loadMissing('cabinetTask.cabinet.project');
            $project = $first->cabinetTask->cabinet->project;
            $dues = $group->pluck('due_date')->filter();
            $sent += $this->send($this->departmentUsers($first, $actor), new PmoNotice(
                'due_changed',
                "วันส่งงานของแผนกเปลี่ยน · {$project->project_no}",
                $project->project_name.' · '.$group->count().' งาน'.($dues->isNotEmpty() ? ' · ส่ง '.$dues->min()->thaiDate().($dues->min()->ne($dues->max()) ? ' – '.$dues->max()->thaiDate() : '') : ' · ไม่มีวันส่ง'),
                route('planning.board', ['project' => $project->id]),
                projectId: $project->id,
                group: "project:{$project->id}",
                parts: ['due_changed' => 1],
                projectLabel: "{$project->project_no} · {$project->project_name}",
            ), departmentId: $first->department_id);
        }

        return $sent;
    }

    /**
     * "ทวงถาม": Admin / PM reminds the departments of work still "รอรับ" - the same notice as the morning reminder, once a day
     * per department and project (pressing again the same day sends nothing).
     *
     * @return list<array{project: Project, department: string, count: int, sent: int}> per department and project
     */
    public function nudge(Collection $subtasks, User $actor): array
    {
        $out = [];
        $today = now()->toDateString();
        foreach ($subtasks->filter(fn ($s) => $s->department_id && $s->assignment_status === CabinetSubtask::ASSIGNMENT_ASSIGNED)
            ->groupBy(fn ($s) => $s->department_id.'|'.$s->cabinetTask->cabinet->project_id) as $rows) {
            $first = $rows->first()->loadMissing('cabinetTask.cabinet.project', 'department');
            $project = $first->cabinetTask->cabinet->project;
            $days = (int) $rows->max(fn ($s) => $s->assigned_at ? (int) $s->assigned_at->diffInDays(now()) : 0);
            $out[] = ['project' => $project, 'department' => $first->department?->name ?? '-', 'count' => $rows->count(), 'sent' => $this->send($this->departmentUsers($first, $actor), new PmoNotice(
                'waiting',
                "{$actor->name} ทวงถาม: งานรอรับ {$rows->count()} งาน · {$project->project_no}",
                "{$project->project_name} · รอมาแล้ว {$days} วัน — กดรับงานในหน้า Planning",
                route('planning.board', ['project' => $project->id, 'stage' => PlanningBoard::deptKey($first->department_id)]),
                'nudge:'.$first->department_id.':'.$project->id.':'.$today,
                projectId: $project->id,
                group: "project:{$project->id}",
                parts: ['waiting' => ['n' => $rows->count(), 'days' => $days]],
                projectLabel: "{$project->project_no} · {$project->project_name}",
            ), dedupe: true, departmentId: $first->department_id)];
        }

        return $out;
    }

    /**
     * Settings > การส่งต่องาน: news from the department passing work on (dates set, items ticked, the daily "due today / late"),
     * folded into each receiver's project notice. Not filtered by "only my department" - it is meant for the receiver.
     */
    public function handoff(Collection $people, Project $project, string $title, string $body, array $parts, ?string $key = null, string $kind = 'handoff'): int
    {
        return $this->send($people, new PmoNotice(
            $kind, $title, $body,
            route('planning.board', ['project' => $project->id]),
            $key,
            projectId: $project->id,
            group: "project:{$project->id}",
            parts: $parts,
            projectLabel: "{$project->project_no} · {$project->project_name}",
        ), dedupe: $key !== null);
    }
}
