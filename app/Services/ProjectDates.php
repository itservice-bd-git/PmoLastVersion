<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\EventDateChange;
use App\Models\Project;
use App\Models\ProjectDelivery;
use App\Models\ProjectFat;
use App\Models\ProjectDateChange;
use App\Models\ProjectDateReason;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A project's or a cabinet's start / due date once it has them: moved only here, with a reason (Admin / PM: หน้าโครงการ /
 * หน้าตู้ › "เลื่อน / แก้วัน"). "postpone" moves the due date and counts as a round (เลื่อนครั้งที่ n); "correct" fixes a date
 * typed wrong and does not count. Both are kept (project_date_changes) and logged; a round tells the project's people in
 * charge and its followers.
 *
 * A project may also wait for its new due date ("pending", รอวันใหม่: the date is to move, the new one not known yet - from
 * sales, the customer…): it is not late anywhere meanwhile, the person who owes the answer and the PM are reminded on the
 * follow-up day (NotificationService::sendReminders), and the postponement that brings the date ends the wait and counts once.
 * A project's postponement or wait can take its FAT and delivery rounds still to come along (events: keep / shift by the same
 * days / a date of their own / wait too); each move is kept in that round's own history (EventDates).
 */
class ProjectDates
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * @param  Project|Cabinet  $subject
     * @param  array{kind: string, due_date: ?string, start_date?: ?string, reason_id?: ?int, note?: ?string, notify?: bool}  $data  validated by the caller
     */
    public function change(Project|Cabinet $subject, User $actor, array $data): ProjectDateChange
    {
        $project = $this->projectOf($subject);
        ProjectLock::assertOpen($project->id);
        if ($data['kind'] === ProjectDateChange::PENDING) {
            return $this->pending($project, $actor, $data);
        }
        $postpone = $data['kind'] === ProjectDateChange::POSTPONE;
        $oldStart = $subject->start_date?->copy();
        $oldDue = $subject->due_date?->copy();
        $newStart = array_key_exists('start_date', $data) ? ($data['start_date'] ? Carbon::parse($data['start_date']) : null) : $oldStart;
        $newDue = $data['due_date'] ? Carbon::parse($data['due_date']) : null;

        $same = fn (?Carbon $a, ?Carbon $b) => $a?->format('Y-m-d') === $b?->format('Y-m-d');
        if ($postpone && ($newDue === null || $same($oldDue, $newDue))) {
            throw ValidationException::withMessages(['due_date' => 'เลื่อนวัน: ใส่วันครบกำหนดใหม่ที่ไม่ใช่วันเดิม']);
        }
        if ($same($oldStart, $newStart) && $same($oldDue, $newDue)) {
            throw ValidationException::withMessages(['due_date' => 'วันที่ไม่ได้เปลี่ยน']);
        }
        if ($newStart && $newDue && $newDue->lt($newStart)) {
            throw ValidationException::withMessages(['due_date' => 'วันครบกำหนดต้องไม่ก่อนวันเริ่ม']);
        }
        if ($subject instanceof Cabinet && $newDue && $subject->expected_completion_date?->gt($newDue)) {
            throw ValidationException::withMessages(['due_date' => 'วันคาดว่าจะเสร็จของตู้ ('.$subject->expected_completion_date->thaiDate().') เกินกำหนดเสร็จใหม่ — แก้วันคาดว่าจะเสร็จก่อน']);
        }
        $reason = $postpone ? ProjectDateReason::where('is_active', true)->find($data['reason_id'] ?? null) : null;
        if ($postpone && ! $reason) {
            throw ValidationException::withMessages(['reason_id' => 'เลือกสาเหตุการเลื่อน']);
        }

        // the wait this postponement ends (a project's own due date only)
        $waited = $postpone && $subject instanceof Project && $subject->due_pending_at ? $subject->duePendingDays() : null;
        $note = trim((string) ($data['note'] ?? '')) ?: null;
        $moves = [];
        $change = DB::transaction(function () use ($subject, $project, $actor, $data, $postpone, $reason, $oldStart, $oldDue, $newStart, $newDue, $waited, $note, &$moves) {
            // the model's own auto log would say the same again without the reason: this change logs it once, below
            $subject::withoutEvents(fn () => $subject->forceFill(['start_date' => $newStart?->format('Y-m-d'), 'due_date' => $newDue?->format('Y-m-d')]
                + ($subject instanceof Project ? ['updated_by' => $actor->id] : [])
                + ($waited !== null ? self::NOT_PENDING : []))->save());

            $change = ProjectDateChange::create([
                'project_id' => $project->id, 'cabinet_id' => $subject instanceof Cabinet ? $subject->id : null,
                'kind' => $postpone ? ProjectDateChange::POSTPONE : ProjectDateChange::CORRECT,
                'old_start_date' => $oldStart, 'new_start_date' => $newStart, 'old_due_date' => $oldDue, 'new_due_date' => $newDue,
                'reason_id' => $reason?->id, 'reason_name' => $reason?->name,
                'note' => $waited !== null ? trim(($note ? $note.' · ' : '').'รอวันใหม่มา '.$waited.' วัน') : $note,
                'changed_by' => $actor->id,
            ]);
            if ($postpone && $subject instanceof Project) {
                $moves = $this->moveEvents($subject, $actor, $data['events'] ?? [], $oldDue && $newDue ? (int) $oldDue->diffInDays($newDue, false) : 0, $reason, $note);
            }

            return $change;
        });

        $rounds = $this->summary($subject)['rounds'];
        ActivityLog::record($project->id, $actor->id, $subject, 'rescheduled', $this->describe($change, $subject, $rounds).$this->movesText($moves), $this->diff($change));
        PlanningBoard::touch();
        if ($postpone && ($data['notify'] ?? true)) {
            $this->notifications->projectRescheduled($project, $actor, $change, $rounds, $subject instanceof Cabinet ? $subject : null);
        }
        $this->tellDelivery($moves, $actor);

        return $change;
    }

    /** What clears a project's wait. */
    public const NOT_PENDING = ['due_pending_at' => null, 'due_pending_from' => null, 'due_pending_user_id' => null, 'due_pending_follow_up' => null];

    /**
     * "รอวันใหม่": the due date stays as it is, the project waits for the new one (from whom, who owes it, when to ask
     * again, why). Not a round: the postponement that brings the date is. Its rounds still to come may wait too.
     *
     * @param  array{from: string, user_id?: ?int, follow_up: string, reason_id: ?int, note: ?string, events?: array, notify?: bool}  $data
     */
    private function pending(Project $project, User $actor, array $data): ProjectDateChange
    {
        if (! $project->due_date) {
            throw ValidationException::withMessages(['kind' => 'โครงการยังไม่มีวันครบกำหนด · ใส่วันก่อน']);
        }
        if (! array_key_exists($data['from'] ?? '', Project::PENDING_FROM)) {
            throw ValidationException::withMessages(['from' => 'เลือกว่ารอวันจากใคร']);
        }
        $followUp = Carbon::parse($data['follow_up']);
        if ($followUp->lt(today())) {
            throw ValidationException::withMessages(['follow_up' => 'วันที่จะตามอีกครั้งต้องไม่ก่อนวันนี้']);
        }
        $reason = ProjectDateReason::where('is_active', true)->find($data['reason_id'] ?? null);
        if (! $reason) {
            throw ValidationException::withMessages(['reason_id' => 'เลือกสาเหตุ']);
        }
        $who = ! empty($data['user_id']) ? User::where('is_active', true)->find($data['user_id']) : null;
        $note = trim((string) ($data['note'] ?? '')) ?: null;

        $moves = [];
        $change = DB::transaction(function () use ($project, $actor, $data, $followUp, $reason, $who, $note, &$moves) {
            Project::withoutEvents(fn () => $project->forceFill([
                'due_pending_at' => $project->due_pending_at ?? now(),   // asked again: still waiting since the first time
                'due_pending_from' => $data['from'], 'due_pending_user_id' => $who?->id,
                'due_pending_follow_up' => $followUp->format('Y-m-d'), 'updated_by' => $actor->id,
            ])->save());
            $moves = $this->moveEvents($project, $actor, $data['events'] ?? [], 0, $reason, $note, waitOnly: true);

            return ProjectDateChange::create([
                'project_id' => $project->id, 'kind' => ProjectDateChange::PENDING,
                'old_start_date' => $project->start_date, 'new_start_date' => $project->start_date,
                'old_due_date' => $project->due_date, 'new_due_date' => null,
                'reason_id' => $reason->id, 'reason_name' => $reason->name, 'note' => $note, 'changed_by' => $actor->id,
            ]);
        });

        $who = $project->duePendingUser;
        $text = $project->duePendingLabel().($who ? ' ('.$who->name.')' : '').' · ตามอีกครั้ง '.$followUp->thaiDate()
            .' · สาเหตุ: '.$reason->name.($note ? ' — '.$note : '');
        ActivityLog::record($project->id, $actor->id, $project, 'rescheduled', $text.$this->movesText($moves));
        PlanningBoard::touch();
        if ($data['notify'] ?? true) {
            $this->notifications->projectDatePending($project, $actor, $text);
        }
        $this->tellDelivery($moves, $actor);

        return $change;
    }

    /** The wait is over without a new date (the old one stands after all): logged, not a round. */
    public function cancelPending(Project $project, User $actor): void
    {
        ProjectLock::assertOpen($project->id);
        if (! $project->due_pending_at) {
            return;
        }
        $days = $project->duePendingDays();
        Project::withoutEvents(fn () => $project->forceFill(self::NOT_PENDING + ['updated_by' => $actor->id])->save());
        ActivityLog::record($project->id, $actor->id, $project, 'rescheduled', 'ยกเลิกการรอวันใหม่ (รอมา '.$days.' วัน) · วันครบกำหนดคง '.$project->due_date?->thaiDate());
        PlanningBoard::touch();
    }

    /** Not answered yet: ask again in a few days (the follow-up notice's "ตามอีก 3 วัน"). */
    public function followUpLater(Project $project, User $actor, int $days = 3): void
    {
        ProjectLock::assertOpen($project->id);
        if (! $project->due_pending_at) {
            return;
        }
        $next = today()->addDays($days);
        Project::withoutEvents(fn () => $project->forceFill(['due_pending_follow_up' => $next->format('Y-m-d')])->save());
        ActivityLog::record($project->id, $actor->id, $project, 'rescheduled', 'ตามแล้ว ยังไม่ได้วันใหม่ · ตามอีกครั้ง '.$next->thaiDate());
    }

    /**
     * The FAT and delivery rounds a project's date can take along: a FAT round not held yet, a delivery round not on the
     * road yet, still to come (after today) or waiting for its own new date.
     *
     * @return \Illuminate\Support\Collection<int, array{type: string, id: int, label: string, when: string, state: string, date: string, pending: bool}>
     */
    public static function linkedEvents(Project $project): \Illuminate\Support\Collection
    {
        $today = today();
        $fats = ProjectFat::where('project_id', $project->id)->where('status', ProjectFat::SCHEDULED)->orderBy('round')->get()
            ->filter(fn (ProjectFat $f) => $f->date_pending_at || $f->fat_date->gt($today))
            ->map(fn (ProjectFat $f) => ['type' => EventDates::FAT, 'id' => $f->id, 'label' => 'FAT รอบ '.$f->round, 'when' => $f->when(),
                'state' => $f->date_pending_at ? 'รอวันใหม่' : $f->statusLabel(), 'date' => $f->fat_date->format('Y-m-d'), 'pending' => (bool) $f->date_pending_at]);
        $deliveries = ProjectDelivery::where('project_id', $project->id)
            ->whereIn('status', [ProjectDelivery::DRAFT, ProjectDelivery::WAITING, ProjectDelivery::REJECTED, ProjectDelivery::SCHEDULED])->orderBy('round')->get()
            ->filter(fn (ProjectDelivery $d) => $d->date_pending_at || $d->firstDay()->gt($today))
            ->map(fn (ProjectDelivery $d) => ['type' => EventDates::DELIVERY, 'id' => $d->id, 'label' => 'ส่งมอบ รอบ '.$d->round, 'when' => $d->when(),
                'state' => $d->date_pending_at ? 'รอ PM ยืนยันวัน' : ProjectDelivery::statusName($d->status), 'date' => $d->firstDay()->format('Y-m-d'), 'pending' => (bool) $d->date_pending_at]);

        return $fats->concat($deliveries)->values();
    }

    /**
     * Applies the rounds' choices: keep / shift (the project's days) / date (a day of their own) / pending (wait too). Only
     * a round linkedEvents() offers moves; a moved round no longer waits, and its move is kept in its own history.
     *
     * @param  array<int, array{type?: string, id?: int|string, action?: string, date?: ?string}>  $choices
     * @return array<int, array{event: ProjectFat|ProjectDelivery, text: string, pending: bool, before: string}>
     */
    private function moveEvents(Project $project, User $actor, array $choices, int $shift, ?ProjectDateReason $reason, ?string $note, bool $waitOnly = false): array
    {
        $offered = self::linkedEvents($project)->keyBy(fn ($e) => $e['type'].':'.$e['id']);
        $moves = [];
        foreach ($choices as $c) {
            $key = ($c['type'] ?? '').':'.($c['id'] ?? '');
            $action = $c['action'] ?? 'keep';
            if (! $offered->has($key) || $action === 'keep' || ($waitOnly && $action !== 'pending')) {
                continue;
            }
            $e = $c['type'] === EventDates::FAT ? ProjectFat::find($c['id']) : ProjectDelivery::find($c['id']);
            $before = EventDates::snapshot($e);
            $when = $e->when();
            if ($action === 'pending') {
                if (! $e->date_pending_at) {
                    $e::withoutEvents(fn () => $e->forceFill(['date_pending_at' => now()])->save());
                }
                $moves[] = ['event' => $e, 'text' => $offered[$key]['label'].' รอวันใหม่ด้วย', 'pending' => true, 'before' => $when];

                continue;
            }
            [$startField, $endField] = $e instanceof ProjectFat ? ['fat_date', 'fat_end_date'] : ['delivery_date', 'delivery_end_date'];
            $days = match ($action) {
                'shift' => $shift,
                'date' => ! empty($c['date']) ? (int) $e->{$startField}->diffInDays(Carbon::parse($c['date']), false) : 0,
                default => 0,
            };
            if ($days === 0 && ! $e->date_pending_at) {
                continue;
            }
            $e::withoutEvents(fn () => $e->forceFill([
                $startField => $e->{$startField}->copy()->addDays($days)->format('Y-m-d'),
                $endField => $e->{$endField}?->copy()->addDays($days)->format('Y-m-d'),
                'date_pending_at' => null,
            ])->save());
            app(EventDates::class)->record($e, $before, $actor, ['move_kind' => EventDateChange::POSTPONE, 'move_reason_id' => $reason?->id,
                'move_note' => 'เลื่อนพร้อมวันโครงการ'.($note ? ' — '.$note : '')]);
            $moves[] = ['event' => $e->refresh(), 'text' => $offered[$key]['label'].' '.$when.' → '.$e->when(), 'pending' => false, 'before' => $when];
        }

        return $moves;
    }

    private function movesText(array $moves): string
    {
        return $moves ? ' · '.implode(' · ', array_column($moves, 'text')) : '';
    }

    /** The delivery department hears of the rounds sent to it that moved or now wait (a draft is not theirs yet). */
    private function tellDelivery(array $moves, User $actor): void
    {
        foreach ($moves as $m) {
            $d = $m['event'];
            if (! $d instanceof ProjectDelivery || $d->status === ProjectDelivery::DRAFT) {
                continue;
            }
            $scheduled = $d->status === ProjectDelivery::SCHEDULED;
            $this->notifications->deliveryForDepartment($d, $actor, $m['pending']
                ? 'รอ PM ยืนยันวันส่ง · ยังไม่ต้องจัดรถ'
                : ($scheduled ? 'PM เลื่อนวันส่ง · ตรวจรถ / คนอีกครั้ง' : 'PM เลื่อนวันส่ง'), todo: $scheduled && ! $m['pending']);
        }
    }

    /**
     * What the page shows: rounds, days moved since the first due date, that first due date, and every change (newest
     * first) with its round number.
     *
     * @return array{rounds: int, days: int, firstDue: ?Carbon, changes: \Illuminate\Support\Collection}
     */
    public function summary(Project|Cabinet $subject): array
    {
        $changes = $subject->dateChanges()->with('changedBy:id,name', 'reason:id,color')->get();
        $round = 0;
        $changes->each(function (ProjectDateChange $c) use (&$round) {
            $c->round = $c->kind === ProjectDateChange::POSTPONE ? ++$round : null;
        });
        $firstDue = $changes->first(fn ($c) => $c->old_due_date)?->old_due_date ?? $subject->due_date;

        return [
            'rounds' => $round,
            'days' => $firstDue && $subject->due_date ? (int) $firstDue->diffInDays($subject->due_date, false) : 0,
            'firstDue' => $firstDue,
            'changes' => $changes->reverse()->values(),
        ];
    }

    /**
     * Planning's "เลื่อน n" chip: the same count as summary() from the subject's changes (oldest first), with each round's
     * reason, newest first. Null until it was postponed at least once.
     */
    public static function brief(\Illuminate\Support\Collection $changes, ?Carbon $due, string $url): ?array
    {
        $rounds = $changes->where('kind', ProjectDateChange::POSTPONE)->values();
        if ($rounds->isEmpty()) {
            return null;
        }
        $firstDue = $changes->first(fn ($c) => $c->old_due_date)?->old_due_date ?? $due;

        return [
            'rounds' => $rounds->count(),
            'days' => $firstDue && $due ? (int) $firstDue->diffInDays($due, false) : 0,
            'firstDue' => $firstDue?->format('Y-m-d'),
            'url' => $url,
            'list' => $rounds->map(fn (ProjectDateChange $c, int $i) => [
                'n' => $i + 1, 'reason' => (string) $c->reason_name, 'note' => (string) $c->note,
                'from' => $c->old_due_date?->format('Y-m-d'), 'to' => $c->new_due_date?->format('Y-m-d'), 'days' => $c->dueDays(),
            ])->reverse()->values()->all(),
        ];
    }

    /** Planning's "เลื่อนทั้งโครงการ" (n days, no reason asked there yet): kept as a round of the project / a cabinet too, so the count stays true. */
    public function shifted(Project|Cabinet $subject, User $actor, ?Carbon $oldStart, ?Carbon $oldDue, int $days): void
    {
        if (! $oldDue && ! $oldStart) {
            return;
        }
        ProjectDateChange::create([
            'project_id' => $this->projectOf($subject)->id, 'cabinet_id' => $subject instanceof Cabinet ? $subject->id : null,
            'kind' => ProjectDateChange::POSTPONE,
            'old_start_date' => $oldStart, 'new_start_date' => $oldStart?->copy()->addDays($days),
            'old_due_date' => $oldDue, 'new_due_date' => $oldDue?->copy()->addDays($days),
            'reason_name' => 'ไม่ระบุ (เลื่อนทั้งโครงการจาก Planning)', 'changed_by' => $actor->id,
        ]);
    }

    /**
     * The departments with work in the project, or in the cabinet (a Sub Task sent to them, as WorkScope: work not yet sent
     * is not theirs to see): who hears of a postponement besides the project's own people
     * (NotificationService::projectRescheduled), named on the form's "แจ้ง" box.
     *
     * @return \Illuminate\Support\Collection<int, Department>
     */
    public static function departments(Project|Cabinet $subject): \Illuminate\Support\Collection
    {
        $ids = CabinetSubtask::whereNotNull('department_id')->whereIn('assignment_status', WorkScope::ACTIVE_STATUSES)
            ->whereHas('cabinetTask.cabinet', fn ($q) => $subject instanceof Cabinet ? $q->whereKey($subject->id) : $q->where('project_id', $subject->id))
            ->distinct()->pluck('department_id');

        return Department::whereIn('id', $ids)->orderBy('name')->get(['id', 'name']);
    }

    private function projectOf(Project|Cabinet $subject): Project
    {
        return $subject instanceof Project ? $subject : $subject->project;
    }

    private function describe(ProjectDateChange $c, Project|Cabinet $subject, int $rounds): string
    {
        $due = $subject instanceof Cabinet ? 'กำหนดเสร็จ' : 'วันครบกำหนด';
        $parts = [];
        if ($c->old_due_date?->format('Y-m-d') !== $c->new_due_date?->format('Y-m-d')) {
            $days = $c->dueDays();
            $parts[] = $due.' '.($c->old_due_date?->thaiDate() ?? '-').' → '.($c->new_due_date?->thaiDate() ?? '-').($days ? ' ('.($days > 0 ? '+' : '').$days.' วัน)' : '');
        }
        if ($c->old_start_date?->format('Y-m-d') !== $c->new_start_date?->format('Y-m-d')) {
            $parts[] = 'วันเริ่ม '.($c->old_start_date?->thaiDate() ?? '-').' → '.($c->new_start_date?->thaiDate() ?? '-');
        }
        $what = $subject instanceof Cabinet ? 'เลื่อนวันตู้ '.($subject->mo_no ?: $subject->cabinet_name) : 'เลื่อนวันโครงการ';
        $head = $c->kind === ProjectDateChange::POSTPONE ? $what.' ครั้งที่ '.$rounds
            : ($subject instanceof Cabinet ? 'แก้ไขวันที่ตู้ '.($subject->mo_no ?: $subject->cabinet_name).' ที่ลงผิด' : 'แก้ไขวันที่ลงผิด').' (ไม่นับเป็นการเลื่อน)';

        return $head.': '.implode(' · ', $parts).($c->reason_name ? ' · สาเหตุ: '.$c->reason_name : '').($c->note ? ' — '.$c->note : '');
    }

    private function diff(ProjectDateChange $c): array
    {
        $out = [];
        foreach (['start_date' => 'วันเริ่ม', 'due_date' => 'วันครบกำหนด'] as $f => $label) {
            $old = $c->{'old_'.$f}?->thaiDate();
            $new = $c->{'new_'.$f}?->thaiDate();
            if ($old !== $new) {
                $out[$f] = ['label' => $label, 'old' => $old ?? '-', 'new' => $new ?? '-'];
            }
        }

        return $out;
    }
}
