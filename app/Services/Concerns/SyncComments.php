<?php

namespace App\Services\Concerns;

use App\Exceptions\AssignmentException;
use App\Exceptions\PlanningRejected;
use App\Exceptions\ProjectLockedException;
use App\Models\ActivityLog;
use App\Models\AppSetting;
use App\Models\Board;
use App\Models\BoardLabel;
use App\Models\BoardStatus;
use App\Models\Cabinet;
use App\Models\CabinetChecklist;
use App\Models\CabinetSubtask;
use App\Models\CabinetTask;
use App\Models\CabinetTemplate;
use App\Models\DateLimitRule;
use App\Models\Department;
use App\Models\DepartmentField;
use App\Models\Project;
use App\Models\ProjectDepartmentStatus;
use App\Models\ProjectFieldValue;
use App\Models\User;
use App\Services\CabinetCopyService;
use App\Services\HandoffService;
use App\Services\ProjectLock;
use App\Services\SharedChecklist;
use App\Services\WorkScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PlanningSync · Comments: post (with @mentions), edit / delete your own, acknowledge an alert.
 * Part of PlanningSync (a trait of it), which owns the dispatch (applyOne) and the helpers: id(), projectFor(), requirePmo(),
 * date(), deptSubtasks(), open().
 */
trait SyncComments
{
    /**
     * Who the sender may tag in a comment on this project: the person must be active, must be able to SEE the project
     * (the notice opens it), and - unless the sender is PMO - must be in the sender's own department or a PMO role
     * (the same "no cross-department tagging" rule as the original page).
     *
     * @param  list<mixed>  $ids
     * @return array{0: \Illuminate\Support\Collection<int, User>, 1: list<string>} [allowed targets, names that were skipped]
     */
    public function mentionTargets(User $sender, Project $project, array $ids): array
    {
        $ids = collect($ids)->filter(fn ($v) => is_int($v) || (is_string($v) && ctype_digit($v)))->map(fn ($v) => (int) $v)->unique()->take(20);
        $allowed = collect();
        $skipped = [];

        foreach (User::whereIn('id', $ids)->where('is_active', true)->get() as $target) {
            $sameCircle = AppSetting::get('cross_mention') || $sender->canViewOtherDepartments() || $target->canViewOtherDepartments()
                || array_intersect($sender->departmentIds(), $target->departmentIds()) !== [];

            if ($target->id !== $sender->id && $sameCircle && WorkScope::canSeeProject($project, $target)) {
                $allowed->push($target);
            } elseif ($target->id !== $sender->id) {
                $skipped[] = $target->name;
            }
        }

        return [$allowed, $skipped];
    }

    /** The note the last comment operation wrote (so the caller can attach the files that came with it). */
    private ?ActivityLog $lastNote = null;

    public function lastNote(): ?ActivityLog
    {
        return $this->lastNote;
    }

    private function comment(User $user, array $op): ?string
    {
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $text = trim((string) ($op['text'] ?? ''));
        if ($text === '') {
            throw new PlanningRejected('แนบรูป/ไฟล์ในหน้านี้ยังไม่รองรับ — เขียนข้อความ หรือแนบไฟล์ที่หน้าโครงการ');
        }
        $text = mb_substr($text, 0, 2000);

        // an alert to a department keeps who acknowledged it; a reply points at the comment it answers
        $changes = [];
        if (! empty($op['alertDept']) && $user->canDispatchWork() && ($alertDept = Department::find($this->id($op['alertDept'], 'd_')))) {
            $changes += ['alert_department_id' => $alertDept->id, 'acks' => []];
        }
        if (! empty($op['replyTo']) && is_string($op['replyTo']) && preg_match('/^a(\d+)$/', $op['replyTo'], $m)
            && ActivityLog::where('project_id', $project->id)->where('action', 'note')->whereKey((int) $m[1])->exists()) {
            $changes['reply_to'] = (int) $m[1];
        }
        $this->lastNote = ActivityLog::record($project->id, $user->id, $project, 'note', $text, $changes);

        $notes = [];

        if (! empty($op['alertDept'])) {
            if (! $user->canDispatchWork()) {
                $notes[] = 'ส่งแจ้งเตือนแผนกได้เฉพาะ Admin / Project Manager';
            } else {
                $dept = Department::find($this->id($op['alertDept'], 'd_')) ?? throw new PlanningRejected('ไม่พบแผนกที่จะแจ้งเตือน');
                $n = $this->notifications->projectAlert($project, $dept, $user, $text, $this->lastNote);
                $notes[] = $n ? "ส่งแจ้งเตือนถึงแผนก {$dept->name} {$n} คนแล้ว" : "⚠️ แผนก {$dept->name} ยังไม่มีผู้ใช้ในระบบ — ไม่มีใครได้รับแจ้งเตือนนี้";
            }
        }

        if (! empty($op['mentions']) && is_array($op['mentions'])) {
            [$targets, $skipped] = $this->mentionTargets($user, $project, $op['mentions']);
            $sent = $targets->sum(fn (User $t) => $this->notifications->mentioned($project, $user, $t, $text, $this->lastNote));
            $told = $targets->pluck('id')->all();
            if ($sent) {
                $notes[] = "แจ้งเตือนผู้ถูกแท็ก {$sent} คน";
            }
            if ($skipped) {
                $notes[] = 'ไม่ได้แจ้ง '.implode(', ', array_slice($skipped, 0, 3)).' (ไม่มีสิทธิ์ในโครงการนี้ หรือคนละแผนก)';
            }
        }

        if (! empty($op['hadAttachment'])) {
            $notes[] = 'รูป/ไฟล์แนบยังไม่รองรับในหน้านี้';
        }

        // everyone else following the project hears about the new comment / reply (not those already tagged / alerted)
        $skip = array_merge($told ?? [], isset($dept) ? User::ofDepartment($dept->id)->pluck('id')->all() : []);
        $this->notifications->commented($project, $user, $this->lastNote, $skip);

        return $notes ? 'บันทึกคอมเมนต์แล้ว · '.implode(' · ', $notes) : null;
    }

    // ---- comments: edit / delete your own, acknowledge an alert ----

    private function noteOf(Project $project, mixed $id): ActivityLog
    {
        return ActivityLog::where('project_id', $project->id)->where('action', 'note')->find($this->id($id, 'a')) ?? throw new PlanningRejected('ไม่พบคอมเมนต์นี้');
    }

    private function commentEdit(User $user, array $op): ?string
    {
        $note = $this->noteOf($this->projectFor($user, $op['taskId'] ?? null), $op['commentId'] ?? null);
        if ($note->user_id !== $user->id) {
            throw new PlanningRejected('แก้ไขได้เฉพาะคอมเมนต์ของตัวเอง');
        }
        $text = trim((string) ($op['text'] ?? ''));
        if ($text === '') {
            throw new PlanningRejected('คอมเมนต์ต้องมีข้อความ');
        }
        $note->forceFill(['description' => mb_substr($text, 0, 2000), 'changes' => ($note->changes ?? []) + ['edited' => true]])->save();

        return null;
    }

    private function commentDelete(User $user, array $op): ?string
    {
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $note = $this->noteOf($project, $op['commentId'] ?? null);
        if ($note->user_id !== $user->id && ! $user->isAdmin()) {
            throw new PlanningRejected('ลบได้เฉพาะคอมเมนต์ของตัวเอง');
        }
        foreach ($note->attachments as $file) {
            @unlink(public_path('uploads/comment-attachments/'.$file->path));
            $file->delete();
        }
        $note->delete();
        ActivityLog::record($project->id, $user->id, $project, 'updated', 'ลบคอมเมนต์');

        return null;
    }

    /**
     * "รับทราบ" on an alert sent to my department / a comment I was tagged in: recorded on it (who, when), its author hears
     * "รับทราบ n/N", and my notice of it is acknowledged too (NotificationService::ackNote).
     */
    private function alertAck(User $user, array $op): ?string
    {
        $project = $this->projectFor($user, $op['taskId'] ?? null);
        $note = $this->noteOf($project, $op['commentId'] ?? null);
        $changes = $note->changes ?? [];
        $asked = in_array($user->id, array_map('intval', $changes['ack_targets'] ?? []), true);
        if (empty($changes['alert_department_id']) && ! $asked) {
            throw new PlanningRejected('คอมเมนต์นี้ไม่ใช่การแจ้งเตือน');
        }
        if (! $asked && ! $user->inDepartment((int) ($changes['alert_department_id'] ?? 0))) {
            throw new PlanningRejected('รับทราบได้เฉพาะแผนกที่ถูกแจ้งเตือน');
        }
        $this->notifications->ackNote($user, $note);
        $user->unreadNotifications()->get()
            ->filter(fn ($n) => (int) ($n->data['note_id'] ?? 0) === $note->id
                || (empty($n->data['note_id']) && ($n->data['kind'] ?? null) === 'alert' && (int) ($n->data['project_id'] ?? 0) === $project->id))
            ->each->markAsRead();

        return null;
    }
}
