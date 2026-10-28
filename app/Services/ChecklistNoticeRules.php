<?php

namespace App\Services;

use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\ChecklistNoticeRule;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Settings > แจ้งเตือน Checklist: admin rules for the paired checklists (SubtaskPairs / SharedChecklist) - when a
 * department's list moves (a date set, an item ticked, its whole list done) or a dated item comes close / runs late,
 * who hears it and in what words. A department with a rule for an event replaces the built-in notice of that event
 * (HandoffService); one with none keeps it, so nothing goes quiet when rules are first added.
 *
 * Fill-ins: {รายการ} {ตู้} {วันที่} {n} {โครงการ} {แผนก}. Recipients: "linked" (the departments of the Sub Tasks paired
 * with it, either way), "owner" (whoever is in charge of those), "pm" (the project's manager), "dept:<id>", "user:<id>".
 */
class ChecklistNoticeRules
{
    private ?Collection $active = null;

    /** @return Collection<int, ChecklistNoticeRule> the active rules of this event for a department's Sub Task in a cabinet of this template */
    public function for(string $event, ?int $deptId, ?int $templateId): Collection
    {
        if (! $deptId) {
            return collect();
        }
        $this->active ??= ChecklistNoticeRule::where('is_active', true)->orderBy('id')->get();

        return $this->active->filter(fn ($r) => $r->event === $event && $r->department_id === $deptId
            && ($r->cabinet_template_id === null || $r->cabinet_template_id === $templateId))->values();
    }

    /** Does a rule take over this event of this Sub Task (so the built-in notice stays quiet)? */
    public function covers(string $event, CabinetSubtask $sub): bool
    {
        $sub->loadMissing('cabinetTask.cabinet');

        return $this->for($event, $sub->department_id, $sub->cabinetTask->cabinet->cabinet_template_id)->isNotEmpty();
    }

    /**
     * An event on a Sub Task's list (date / tick / cabinet): each matching rule tells its people. Returns whether any rule
     * matched. @param Collection<int, CabinetChecklist> $items the items it is about
     */
    public function fire(string $event, CabinetSubtask $sub, Collection $items, ?User $actor): bool
    {
        $sub->loadMissing('cabinetTask.cabinet.project', 'department');
        $rules = $this->for($event, $sub->department_id, $sub->cabinetTask->cabinet->cabinet_template_id);
        foreach ($rules as $rule) {
            $this->send($rule, $sub, $items, $actor, $rule->days);
        }

        return $rules->isNotEmpty();
    }

    /**
     * The morning round: dated items of a watched department, N working days before their date ("before") or N or more
     * after it ("late": once on the N-th day, or every day with "repeat"), that the other side has not dealt with yet.
     * One notice per rule per Sub Task per day.
     */
    public function daily(Carbon $today): int
    {
        $rules = ChecklistNoticeRule::where('is_active', true)->whereIn('event', ['before', 'late'])->get();
        if ($rules->isEmpty()) {
            return 0;
        }
        $items = CabinetChecklist::query()->whereNotNull('expected_date')
            ->whereHas('subtask', fn ($q) => $q->whereIn('department_id', $rules->pluck('department_id'))->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES))
            ->with('subtask.cabinetTask.cabinet.project', 'subtask.department')->get()
            ->filter(fn ($i) => ($p = $i->subtask->cabinetTask?->cabinet?->project) && ! $p->isLocked());

        $sent = 0;
        foreach ($items->groupBy('cabinet_subtask_id') as $list) {
            $sub = $list->first()->subtask;
            $pending = $this->pending($sub, $list);
            if ($pending->isEmpty()) {
                continue;
            }
            foreach ($rules as $rule) {
                if ($rule->department_id !== $sub->department_id
                    || ($rule->cabinet_template_id && $rule->cabinet_template_id !== $sub->cabinetTask->cabinet->cabinet_template_id)) {
                    continue;
                }
                $n = max(1, (int) $rule->days);
                $hit = $pending->filter(function ($i) use ($rule, $today, $n) {
                    $due = $i->expected_date->copy()->startOfDay();
                    if ($rule->event === 'before') {
                        return $due->gt($today) && self::workDaysAfter($today, $due) === $n;
                    }
                    $late = $due->lt($today) ? self::workDaysAfter($due, $today) : 0;

                    return $rule->repeat ? $late >= $n : $late === $n;
                });
                if ($hit->isNotEmpty()) {
                    $sent += $this->send($rule, $sub, $hit->values(), null, $n, "rule:{$rule->id}:{$sub->id}:".$today->toDateString());
                }
            }
        }

        return $sent;
    }

    /** The items the Sub Tasks paired with this one have not dealt with yet (none paired: the ones not ticked). */
    private function pending(CabinetSubtask $sub, Collection $items): Collection
    {
        $receivers = CabinetSubtask::whereKey(DB::table('cabinet_subtask_pairs')->where('source_subtask_id', $sub->id)->pluck('cabinet_subtask_id'))
            ->with('checklists')->get();
        if ($receivers->isEmpty()) {
            return $items->reject(fn ($i) => $i->is_completed);
        }

        return $items->filter(fn ($i) => $receivers->contains(fn ($r) => ! HandoffService::received($i, $r->checklists)));
    }

    /** Working days (Mon-Fri) after $from up to and including $to. */
    public static function workDaysAfter(Carbon $from, Carbon $to): int
    {
        $n = 0;
        for ($d = $from->copy()->startOfDay()->addDay(); $d->lte($to); $d->addDay()) {
            $n += $d->isWeekend() ? 0 : 1;
        }

        return $n;
    }

    // ---- one rule's notice ----

    /** @param Collection<int, CabinetChecklist> $items */
    private function send(ChecklistNoticeRule $rule, CabinetSubtask $sub, Collection $items, ?User $actor, ?int $n, ?string $key = null): int
    {
        $people = $this->recipients($rule, $sub, $actor);
        if ($people->isEmpty() || $items->isEmpty()) {
            return 0;
        }
        $cabinet = $sub->cabinetTask->cabinet;
        $project = $cabinet->project;
        $dept = (string) $sub->department?->name;
        $date = $items->pluck('expected_date')->filter()->min();
        $vars = [
            '{รายการ}' => $items->count() === 1 ? '"'.$items->first()->name.'"' : $items->count().' รายการ',
            '{ตู้}' => (string) $cabinet->mo_no,
            '{วันที่}' => $date ? $date->thaiDate() : '',
            '{n}' => (string) ($n ?? $items->count()),
            '{โครงการ}' => (string) $project->project_no,
            '{แผนก}' => $dept,
        ];
        $text = self::fill($rule->message, $vars);
        // the project notice's summary line: the words without what changes per item ("คลัง: ของเข้าแล้ว 3")
        $short = trim(preg_replace('/\s+[-—·]?\s*$/u', '', self::fill($rule->message, ['{รายการ}' => '', '{วันที่}' => ''] + $vars)));

        return app(NotificationService::class)->handoff($people, $project,
            "{$dept}: {$text}", "{$cabinet->mo_no} · {$project->project_no}",
            ["rule|{$dept}: {$short}" => $items->count()], $key, $rule->isDaily() ? 'handoff_due' : 'handoff');
    }

    public static function fill(string $message, array $vars): string
    {
        return trim(preg_replace('/ {2,}/u', ' ', strtr($message, $vars)));
    }

    /** @return Collection<int, User> active people the rule names for this Sub Task, minus whoever did it */
    public function recipients(ChecklistNoticeRule $rule, CabinetSubtask $sub, ?User $actor): Collection
    {
        $pairs = DB::table('cabinet_subtask_pairs')->where('source_subtask_id', $sub->id)->pluck('cabinet_subtask_id')
            ->merge(DB::table('cabinet_subtask_pairs')->where('cabinet_subtask_id', $sub->id)->pluck('source_subtask_id'))->unique();
        $linked = $pairs->isEmpty() ? collect() : CabinetSubtask::whereKey($pairs)->get(['id', 'department_id', 'owner_id']);
        [$depts, $users] = [collect(), collect()];
        foreach ((array) $rule->recipients as $r) {
            match (true) {
                $r === 'linked' => $depts = $depts->merge($linked->pluck('department_id')),
                $r === 'owner' => $users = $users->merge($linked->pluck('owner_id')),
                $r === 'pm' => $users->push($sub->cabinetTask->cabinet->project->project_manager_id),
                str_starts_with((string) $r, 'dept:') => $depts->push((int) substr($r, 5)),
                str_starts_with((string) $r, 'user:') => $users->push((int) substr($r, 5)),
                default => null,
            };
        }
        [$depts, $users] = [$depts->filter()->unique(), $users->filter()->unique()];
        if ($depts->isEmpty() && $users->isEmpty()) {
            return collect();
        }

        return User::where('is_active', true)
            ->where(fn ($q) => $q->whereIn('department_id', $depts->all() ?: [0])->orWhereIn('id', $users->all() ?: [0]))
            ->when($actor, fn ($q) => $q->where('id', '!=', $actor->id))->get();
    }

    /** "ลองส่งหาฉัน": the rule's words with sample fill-ins, to the person trying it. */
    public function test(ChecklistNoticeRule $rule, User $user): void
    {
        $text = self::fill($rule->message, ['{รายการ}' => '"Breaker 100A"', '{ตู้}' => 'WH/MO/00001', '{วันที่}' => now()->thaiDate(),
            '{n}' => (string) ($rule->days ?: 1), '{โครงการ}' => 'SO-ตัวอย่าง', '{แผนก}' => (string) $rule->department?->name]);
        $user->notify(new \App\Notifications\PmoNotice('handoff', 'ทดสอบ · '.$rule->department?->name.': '.$text, 'ตัวอย่างจากกฎแจ้งเตือน Checklist (ไม่ใช่งานจริง)', route('settings.notice-rules.index')));
    }
}
