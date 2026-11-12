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
 * NotificationService · The daily job (pmo:send-reminders): due soon / overdue, and work still waiting to be accepted.
 * Part of NotificationService (a trait of it): who hears, the channels and the de-duplication are there.
 */
trait Reminders
{
    /**
     * Daily reminders, as ONE notice per department per project (not one per sub task): "งานเกินกำหนด 18 งาน · SO…",
     * with how late the worst one is and how many are due soon. The project's PM / people in charge get one per project
     * when something is overdue. Sent once a day (the key holds the date). Returns how many notices were sent.
     */
    public function sendReminders(int $soonDays = 2): int
    {
        $sent = 0;
        $today = today();

        $rows = CabinetSubtask::query()
            ->whereNotNull('department_id')->whereNotNull('due_date')
            ->whereIn('assignment_status', [CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS])
            ->notDone()   // a complete checklist is finished work even before "เสร็จงาน" is pressed
            ->whereDate('due_date', '<=', $today->copy()->addDays($soonDays))
            ->with('cabinetTask.cabinet.project', 'department')
            ->get()
            // a closed project is read-only and finished - nobody needs chasing about it
            ->filter(fn ($s) => ($p = $s->cabinetTask?->cabinet?->project) && ! $p->isLocked());

        $summary = function (Collection $group) use ($today): array {
            $late = $group->filter(fn ($s) => $s->due_date->lt($today));
            $soon = $group->count() - $late->count();
            $worst = $late->isNotEmpty() ? (int) $late->min(fn ($s) => $today->diffInDays($s->due_date, false)) : 0;

            return [$late->count(), $soon, abs($worst)];
        };

        foreach ($rows->groupBy(fn ($s) => $s->cabinetTask->cabinet->project_id) as $projectRows) {
            $project = $projectRows->first()->cabinetTask->cabinet->project;

            // each department: its own work in this project
            foreach ($projectRows->groupBy('department_id') as $deptRows) {
                [$late, $soon, $worst] = $summary($deptRows);
                $sent += $this->send($this->departmentUsers($deptRows->first()), new PmoNotice(
                    $late ? 'overdue' : 'due_soon',
                    $late ? "งานเกินกำหนด {$late} งาน · {$project->project_no}" : "งานใกล้ครบกำหนด {$soon} งาน · {$project->project_no}",
                    $project->project_name.($late ? " · เกินสุด {$worst} วัน" : '').($late && $soon ? " · ใกล้ครบอีก {$soon} งาน" : ''),
                    route('planning.board', ['project' => $project->id]),
                    'reminder:'.$deptRows->first()->department_id.':'.$project->id.':'.$today->toDateString(),
                    projectId: $project->id,
                    group: "project:{$project->id}",
                    parts: array_filter(['overdue' => $late ? ['n' => $late, 'worst' => $worst] : null, 'due_soon' => $soon ?: null]),
                    projectLabel: "{$project->project_no} · {$project->project_name}",
                ), dedupe: true, departmentId: $deptRows->first()->department_id);
            }

            // the people in charge: one notice per project, only when something is late
            $lateRows = $projectRows->filter(fn ($s) => $s->due_date->lt($today));
            if ($lateRows->isNotEmpty()) {
                [$late, , $worst] = $summary($lateRows);
                $depts = $lateRows->pluck('department.name')->filter()->unique()->implode(', ');
                $sent += $this->send($this->peopleInCharge($project)->reject(fn ($u) => $lateRows->pluck('department_id')->intersect($u->departmentIds())->isNotEmpty()), new PmoNotice(
                    'overdue',
                    "งานเกินกำหนด {$late} งาน · {$project->project_no}",
                    "{$project->project_name} · {$depts} · เกินสุด {$worst} วัน",
                    route('planning.board', ['project' => $project->id]),
                    'reminder-lead:'.$project->id.':'.$today->toDateString(),
                    projectId: $project->id,
                    group: "project:{$project->id}",
                    parts: ['overdue' => ['n' => $late, 'worst' => $worst]],
                    projectLabel: "{$project->project_no} · {$project->project_name}",
                ), dedupe: true);
            }
        }

        // work handed out with no due date: the people who set dates (the project's PM / people in charge, else every Admin / PM)
        $undated = CabinetSubtask::query()
            ->whereNotNull('department_id')->whereNull('due_date')
            ->whereIn('assignment_status', [CabinetSubtask::ASSIGNMENT_ASSIGNED, CabinetSubtask::ASSIGNMENT_ACCEPTED, CabinetSubtask::ASSIGNMENT_IN_PROGRESS])
            ->with('cabinetTask.cabinet.project', 'department')->get()
            ->filter(fn ($s) => ($p = $s->cabinetTask?->cabinet?->project) && ! $p->isLocked())
            ->groupBy(fn ($s) => $s->cabinetTask->cabinet->project_id);
        foreach ($undated as $projectRows) {
            $project = $projectRows->first()->cabinetTask->cabinet->project;
            $people = $this->peopleInCharge($project);
            $depts = $projectRows->pluck('department.name')->filter()->unique()->implode(', ');
            $sent += $this->send($people->isNotEmpty() ? $people : $this->pmoPeople(), new PmoNotice(
                'undated',
                "งานยังไม่มีวันส่ง {$projectRows->count()} งาน · {$project->project_no}",
                "{$project->project_name} · {$depts}",
                route('planning.board', ['project' => $project->id]),
                'undated:'.$project->id.':'.$today->toDateString(),
                projectId: $project->id,
                group: "project:{$project->id}",
                parts: ['undated' => $projectRows->count()],
                projectLabel: "{$project->project_no} · {$project->project_name}",
            ), dedupe: true);
        }

        $sent += $this->waitingReminders($today);

        // Settings > การส่งต่องาน: what the department passing work on said would arrive today / is late
        $sent += app(HandoffService::class)->reminders($this, $today);
        $sent += app(ChecklistNoticeRules::class)->daily($today);   // Settings > แจ้งเตือน Checklist: "ก่อนถึงวัน" / "เลยวัน"

        return $sent;
    }

    /**
     * Work handed out and still "รอรับ" after Settings > กฎการทำงาน's number of days: the department hears it (one notice per
     * project) and so do the people in charge of the project - every Admin / PM when nobody is - one notice per project
     * naming the departments. Once a day.
     */
    private function waitingReminders(\Carbon\Carbon $today): int
    {
        $days = (int) \App\Models\AppSetting::get('waiting_reminder_days');
        if ($days <= 0) {
            return 0;
        }
        $rows = CabinetSubtask::query()
            ->where('assignment_status', CabinetSubtask::ASSIGNMENT_ASSIGNED)->whereNotNull('department_id')
            ->whereNotNull('assigned_at')->where('assigned_at', '<=', now()->subDays($days))
            ->with('cabinetTask.cabinet.project', 'department')->get()
            ->filter(fn ($s) => ($p = $s->cabinetTask?->cabinet?->project) && ! $p->isLocked());

        $sent = 0;
        foreach ($rows->groupBy(fn ($s) => $s->cabinetTask->cabinet->project_id) as $projectRows) {
            $project = $projectRows->first()->cabinetTask->cabinet->project;
            $label = "{$project->project_no} · {$project->project_name}";
            $longest = fn (Collection $g) => (int) $g->max(fn ($s) => (int) $s->assigned_at->diffInDays(now()));

            foreach ($projectRows->groupBy('department_id') as $deptRows) {
                $sent += $this->send($this->departmentUsers($deptRows->first()), new PmoNotice(
                    'waiting',
                    "งานรอรับ {$deptRows->count()} งาน · {$project->project_no}",
                    "{$project->project_name} · รอมาแล้ว {$longest($deptRows)} วัน — กดรับงานในหน้า Planning",
                    route('planning.board', ['project' => $project->id, 'stage' => PlanningBoard::deptKey($deptRows->first()->department_id)]),
                    'waiting:'.$deptRows->first()->department_id.':'.$project->id.':'.$today->toDateString(),
                    projectId: $project->id,
                    group: "project:{$project->id}",
                    parts: ['waiting' => ['n' => $deptRows->count(), 'days' => $longest($deptRows)]],
                    projectLabel: $label,
                ), dedupe: true, departmentId: $deptRows->first()->department_id);
            }

            $depts = $projectRows->pluck('department_id')->unique();
            $people = $this->peopleInCharge($project);
            $sent += $this->send(($people->isNotEmpty() ? $people : $this->pmoPeople())->reject(fn ($u) => $depts->intersect($u->departmentIds())->isNotEmpty()), new PmoNotice(
                'waiting',
                "ยังไม่มีคนรับงาน {$projectRows->count()} งาน · {$project->project_no}",
                "{$project->project_name} · ".$projectRows->pluck('department.name')->filter()->unique()->implode(', ')." · รอมาแล้ว {$longest($projectRows)} วัน",
                route('planning.board', ['project' => $project->id]),
                'waiting-lead:'.$project->id.':'.$today->toDateString(),
                projectId: $project->id,
                group: "project:{$project->id}",
                parts: ['waiting' => ['n' => $projectRows->count(), 'days' => $longest($projectRows)]],
                projectLabel: $label,
            ), dedupe: true);
        }

        return $sent;
    }
}
