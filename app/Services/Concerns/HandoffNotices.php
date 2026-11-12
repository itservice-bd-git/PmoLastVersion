<?php

namespace App\Services\Concerns;

use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\Project;
use App\Models\User;
use App\Services\ChecklistNoticeRules;
use App\Services\NotificationService;
use App\Services\SharedChecklist;
use App\Services\WorkScope;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** HandoffService: who hears what when a paired checklist moves (dates, received back, ticked) and the daily reminders. */
trait HandoffNotices
{
    /** The members of the departments whose Sub Tasks compare with these, minus the actor. */
    private function people(Collection $receivers, ?User $actor): Collection
    {
        $depts = $receivers->pluck('department_id')->filter()->unique();

        return $depts->isEmpty() ? collect() : User::ofDepartment($depts->all())->where('is_active', true)->when($actor, fn ($q) => $q->where('id', '!=', $actor->id))->get();
    }

    /** A source set / moved the dates of some of its items. @param Collection<int, CabinetChecklist> $items (saved) */
    public function datesChanged(Collection $items, User $actor): int
    {
        $sent = 0;
        $items = (new \Illuminate\Database\Eloquent\Collection($items->all()))->loadMissing('subtask.cabinetTask.cabinet.project', 'subtask.department');
        foreach ($items->groupBy(fn ($i) => $i->subtask->department_id.'|'.$i->subtask->cabinetTask->cabinet->project_id) as $group) {
            $sub = $group->first()->subtask;
            if (app(ChecklistNoticeRules::class)->fire('date', $sub, $group->values(), $actor)) {
                continue;   // Settings > แจ้งเตือน Checklist said it
            }
            $label = $this->dateLabel($sub->department_id);
            $receivers = $group->pluck('subtask')->unique('id')->flatMap(fn ($s) => $this->load($this->receiverIds($s)));
            if (! $label || $receivers->isEmpty()) {
                continue;
            }
            $project = $sub->cabinetTask->cabinet->project;
            $dates = $group->pluck('expected_date')->filter();
            $cabs = $group->map(fn ($i) => $i->subtask->cabinetTask->cabinet->mo_no)->filter()->unique()->implode(', ');
            $sent += app(NotificationService::class)->handoff($this->people($receivers, $actor), $project,
                "{$sub->department->name} ลง{$label} · {$project->project_no}",
                $group->count().' รายการ'.($cabs ? " · {$cabs}" : '').($dates->isNotEmpty() ? ' · '.$dates->min()->thaiDate().($dates->min()->ne($dates->max()) ? ' – '.$dates->max()->thaiDate() : '') : ' · ล้างวันที่'),
                ["handoff_date|{$sub->department->name}|{$label}" => $group->count()]);
        }

        return $sent;
    }

    /**
     * A receiver ticked its copy and the source's item was ticked with it (SharedChecklist::copyTicked): the source's
     * department hears it, and so do the ones only following that list ("ดูอย่างเดียว", e.g. Production: คลังรับของแล้ว).
     */
    public function receivedBack(CabinetChecklist $copy, CabinetChecklist $source, ?User $actor): int
    {
        $copy->loadMissing('subtask.department');
        $cabinet = $source->subtask->cabinetTask->cabinet;
        $dept = $copy->subtask->department?->name ?? $copy->subtask->name;
        $title = "{$dept} รับ \"{$source->name}\" แล้ว · {$cabinet->project->project_no}";
        $owners = $this->people(collect([$source->subtask]), $actor);
        $watchers = $this->people($this->load($this->receiverIds($source->subtask, 'follow')), $actor)->reject(fn ($u) => $owners->contains('id', $u->id));

        return app(NotificationService::class)->handoff($owners, $cabinet->project, $title,
            "{$cabinet->mo_no} · ติ๊กใน \"{$source->subtask->name}\" ให้แล้ว", ["handoff_recv|{$dept}" => 1])
            + ($watchers->isEmpty() ? 0 : app(NotificationService::class)->handoff($watchers, $cabinet->project, $title,
                "{$cabinet->mo_no} · รายการของ{$source->subtask->department?->name}", ["handoff_recv|{$dept}" => 1]));
    }

    /** A source ticked an item: the departments comparing hear it, and once more when the source has finished its list. */
    public function ticked(CabinetChecklist $item, ?User $actor): int
    {
        $item->loadMissing('subtask.cabinetTask.cabinet.project', 'subtask.department');
        $sub = $item->subtask;
        $receivers = $this->load($this->receiverIds($sub));
        if (! $sub->department_id || $receivers->isEmpty()) {
            return 0;
        }
        $cabinet = $sub->cabinetTask->cabinet;
        $all = $sub->checklists()->get(['id', 'is_completed']);
        $done = $all->isNotEmpty() && $all->every('is_completed');
        $dept = $sub->department->name;

        return app(NotificationService::class)->handoff($this->people($receivers, $actor), $cabinet->project,
            $done ? "{$dept} ครบ \"{$sub->name}\" ตู้ {$cabinet->mo_no} · {$cabinet->project->project_no}" : "{$dept} เสร็จ \"{$item->name}\" · {$cabinet->project->project_no}",
            $done ? "{$cabinet->cabinet_name} · ".$all->count().' รายการครบแล้ว' : "{$cabinet->mo_no} · ".$all->where('is_completed', true)->count().'/'.$all->count(),
            array_filter(["handoff_done|{$dept}" => 1, "handoff_cab|{$dept}" => $done ? 1 : null]));
    }

    /**
     * The daily round: dated items due today or past that a Sub Task comparing with them has not dealt with yet. Its
     * department gets "today" and "late"; the source department gets "late" (to chase it). An item nobody takes (its
     * department has a date but no Sub Task compares with it) is the department's own: it hears "today" / "late" while the
     * item is not ticked, and the project's PM hears "late". One notice per department per project per day.
     */
    public function reminders(NotificationService $notices, Carbon $today): int
    {
        $dated = $this->labels ??= Department::whereNotNull('handoff_date_label')->where('handoff_date_label', '!=', '')->pluck('handoff_date_label', 'id');
        $items = CabinetChecklist::query()->whereNotNull('expected_date')->whereDate('expected_date', '<=', $today)
            ->where(fn ($q) => $q->whereIn('cabinet_subtask_id', DB::table('cabinet_subtask_pairs')->select('source_subtask_id'))
                ->orWhere(fn ($own) => $own->where('is_completed', false)->whereHas('subtask', fn ($s) => $s->whereIn('department_id', $dated->keys()))))
            ->whereHas('subtask', fn ($q) => $q->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES))
            ->with('subtask.cabinetTask.cabinet.project', 'subtask.department')->get()
            ->filter(fn ($i) => ($p = $i->subtask->cabinetTask?->cabinet?->project) && ! $p->isLocked());
        if ($items->isEmpty()) {
            return 0;
        }
        $this->preload($items->map(fn ($i) => $i->subtask->cabinetTask->cabinet_id)->unique()->values());

        $sent = 0;
        $rules = app(ChecklistNoticeRules::class);
        $items = $items->reject(fn ($i) => $rules->covers('before', $i->subtask) || $rules->covers('late', $i->subtask));   // its own rules say it instead
        foreach ($items->groupBy(fn ($i) => $i->subtask->department_id.'|'.$i->subtask->cabinetTask->cabinet->project_id) as $group) {
            $sub = $group->first()->subtask;
            $project = $sub->cabinetTask->cabinet->project;
            $label = $this->dateLabel($sub->department_id) ?? 'วันที่';
            // each item against the Sub Tasks comparing with its own one, grouped by their department; nobody takes it: its own
            $pending = [];
            $own = collect();
            foreach ($group as $item) {
                $takers = $this->load($this->receiverIds($item->subtask, 'take'));
                if ($takers->isEmpty() && ! $item->is_completed && $this->dateLabel($sub->department_id)) {
                    $own->push($item);
                }
                foreach ($takers as $r) {
                    if ($r->department_id && ! self::received($item, $r->checklists)) {
                        $pending[$r->department_id][$item->id] = $item;
                    }
                }
            }
            $sent += $this->ownReminders($notices, $own, $sub, $project, $label, $today);
            $lateForSender = 0;
            foreach ($pending as $to => $list) {
                $list = collect($list);
                $late = $list->filter(fn ($i) => $i->expected_date->lt($today))->count();
                $now = $list->count() - $late;
                $lateForSender = max($lateForSender, $late);
                $people = User::ofDepartment($to)->where('is_active', true)->get();
                $sent += $notices->handoff($people, $project,
                    $late ? "📦 เลย{$label} {$late} รายการ · {$project->project_no}" : "📦 {$label}วันนี้ {$now} รายการ · {$project->project_no}",
                    "{$project->project_name} · จาก{$sub->department->name}".($late && $now ? " · วันนี้อีก {$now} รายการ" : ''),
                    array_filter(['handoff_today' => $now ? ['n' => $now, 'label' => $label] : null, 'handoff_late' => $late ? ['n' => $late, 'label' => $label] : null]),
                    "handoff:{$to}:{$sub->department_id}:{$project->id}:".$today->toDateString(), 'handoff_due');
            }

            if ($lateForSender) {
                $people = User::ofDepartment($sub->department_id)->where('is_active', true)->get();
                $sent += $notices->handoff($people, $project, "📦 เลย{$label} {$lateForSender} รายการ · {$project->project_no}", "{$project->project_name} · ปลายทางยังไม่ได้รับ",
                    ['handoff_late' => ['n' => $lateForSender, 'label' => $label]], "handoff-sender:{$sub->department_id}:{$project->id}:".$today->toDateString(), 'handoff_due');
            }
        }

        return $sent;
    }

    /** The department's own dated items (nobody compares with them): it hears "today" / "late", the project's PM "late". */
    private function ownReminders(NotificationService $notices, Collection $items, CabinetSubtask $sub, Project $project, string $label, Carbon $today): int
    {
        if ($items->isEmpty()) {
            return 0;
        }
        $late = $items->filter(fn ($i) => $i->expected_date->lt($today))->count();
        $now = $items->count() - $late;
        $dept = $sub->department->name;
        $parts = array_filter(['handoff_today' => $now ? ['n' => $now, 'label' => $label] : null, 'handoff_late' => $late ? ['n' => $late, 'label' => $label] : null]);
        $day = $today->toDateString();
        $sent = $notices->handoff(User::ofDepartment($sub->department_id)->where('is_active', true)->get(), $project,
            $late ? "📦 เลย{$label} {$late} รายการ · {$project->project_no}" : "📦 {$label}วันนี้ {$now} รายการ · {$project->project_no}",
            "{$project->project_name}".($late && $now ? " · วันนี้อีก {$now} รายการ" : '').' · ยังไม่ได้ติ๊ก', $parts, "handoff-own:{$sub->department_id}:{$project->id}:{$day}", 'handoff_due');
        if ($late && $project->project_manager_id) {
            $sent += $notices->handoff(User::whereKey($project->project_manager_id)->where('is_active', true)->get(), $project,
                "📦 {$dept} เลย{$label} {$late} รายการ · {$project->project_no}", "{$project->project_name} · ยังไม่ได้ติ๊ก",
                ['handoff_late' => ['n' => $late, 'label' => $label]], "handoff-pm:{$sub->department_id}:{$project->id}:{$day}", 'handoff_due');
        }

        return $sent;
    }
}
