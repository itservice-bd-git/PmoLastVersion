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
 * NotificationService · Seen and รับทราบ: the bell opened, notices acknowledged, and the "รับทราบ n/N" receipt a note's sender gets.
 * Part of NotificationService (a trait of it): who hears, the channels and the de-duplication are there.
 */
trait NoticeReceipts
{
    // ======================================================================= seen / รับทราบ

    /**
     * The person opened the bell: everything until now is "เห็นแล้ว" (the red number resets, the next event starts a new
     * notice), and news that only informs ("ความเคลื่อนไหว") is done with once seen. "ต้องทำ" waits for its "รับทราบ".
     */
    public function seen(User $user): void
    {
        $user->forceFill(['notifications_seen_at' => now()])->saveQuietly();
        $done = $user->unreadNotifications()->get()->reject(fn ($n) => PmoNotice::todoOf($n->data));
        if ($done->isNotEmpty()) {
            DatabaseNotification::whereKey($done->pluck('id'))->update(['read_at' => now()]);
        }
    }

    /**
     * "รับทราบ" on these notices (the bell, the small card, the alert window, the notices page): marked read, and an alert /
     * a tag records it on its comment so the sender sees who has. Only ever the person's own notices.
     *
     * @param  Collection<int, DatabaseNotification>  $notices
     */
    public function acknowledge(User $user, Collection $notices): int
    {
        $notices = $notices->filter(fn ($n) => (int) $n->notifiable_id === $user->id && $n->read_at === null)->values();
        if ($notices->isEmpty()) {
            return 0;
        }
        DatabaseNotification::whereKey($notices->pluck('id'))->update(['read_at' => now()]);
        foreach ($notices->map(fn ($n) => $n->data['note_id'] ?? null)->filter()->unique() as $noteId) {
            if ($note = ActivityLog::where('action', 'note')->find($noteId)) {
                $this->ackNote($user, $note);
            }
        }

        return $notices->count();
    }

    /** "รับทราบ" of an alert / a tag, on its comment (who, when - once per person); its author hears "รับทราบ n/N". */
    public function ackNote(User $user, ActivityLog $note): void
    {
        $changes = $note->changes ?? [];
        if (collect($changes['acks'] ?? [])->contains('user_id', $user->id)) {
            return;
        }
        $changes['acks'] = array_values(array_merge($changes['acks'] ?? [], [['user_id' => $user->id, 'name' => $user->name, 'at' => now()->getTimestampMs()]]));
        $note->forceFill(['changes' => $changes])->save();
        $this->receipt($note);
    }

    /**
     * Who an alert / a tag reached and who has acknowledged it ("รับทราบ 3/5 · ยังไม่รับทราบ: เอก, นิด"). total 0 = a comment
     * that asked nobody (or one sent before this was recorded).
     *
     * @return array{acked: int, total: int, done: bool, pending: list<string>}
     */
    public static function receiptOf(ActivityLog $note): array
    {
        $targets = collect($note->changes['ack_targets'] ?? [])->map(fn ($id) => (int) $id)->unique();
        $acked = collect($note->changes['acks'] ?? [])->pluck('user_id')->map(fn ($id) => (int) $id);
        $pending = $targets->diff($acked);

        return [
            'acked' => $targets->intersect($acked)->count(),
            'total' => $targets->count(),
            'done' => $targets->isNotEmpty() && $pending->isEmpty(),
            'pending' => $pending->isEmpty() ? [] : User::whereKey($pending)->orderBy('name')->pluck('name')->all(),
        ];
    }

    /** The comment an alert / a tag came from remembers whom it reached, for "รับทราบ n/N". */
    private function addAckTargets(?ActivityLog $note): void
    {
        if (! $note || ! $this->lastDelivered) {
            return;
        }
        $changes = $note->changes ?? [];
        $changes['ack_targets'] = array_values(array_unique(array_merge($changes['ack_targets'] ?? [], $this->lastDelivered)));
        $note->forceFill(['changes' => $changes])->save();
    }

    /**
     * The author's one notice about it, updated in place as people acknowledge ("รับทราบ 3/5" ... "รับทราบครบ 5/5"): it
     * comes back to the top as new each time, but never piles up one per person.
     */
    private function receipt(ActivityLog $note): void
    {
        $author = $note->user_id ? User::where('id', $note->user_id)->where('is_active', true)->first() : null;
        $project = Project::find($note->project_id);
        $r = self::receiptOf($note);
        if (! $author || ! $project || ! $r['total'] || NotificationPreference::where('user_id', $author->id)->where('enabled', false)->exists()) {
            return;
        }
        $title = ($r['done'] ? 'รับทราบครบ ' : 'รับทราบแล้ว ')."{$r['acked']}/{$r['total']} คน · {$project->project_no}";
        $body = '“'.mb_strimwidth($note->description, 0, 60, '…').'”'.($r['done'] ? '' : ' · ยังไม่รับทราบ: '.implode(', ', $r['pending']));
        $group = "receipt:{$note->id}";
        $existing = DatabaseNotification::where('notifiable_type', User::class)->where('notifiable_id', $author->id)
            ->where('type', PmoNotice::class)->where('data', 'like', '%"group":'.json_encode($group).'%')->latest()->first();
        if ($existing) {
            $existing->forceFill(['data' => array_merge($existing->data, ['title' => $title, 'body' => $body]), 'created_at' => now(), 'read_at' => null])->save();

            return;
        }
        $author->notify(new PmoNotice('receipt', $title, $body, route('planning.board', ['project' => $project->id]), projectId: $project->id, group: $group));
    }
}
