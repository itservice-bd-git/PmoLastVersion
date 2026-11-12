<?php

namespace App\Services\Concerns;

use App\Models\ActivityLog;
use App\Models\BoardLabel;
use App\Models\BoardStatus;
use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\Department;
use App\Models\DepartmentField;
use App\Models\Project;
use App\Models\ProjectDateChange;
use App\Models\ProjectDelivery;
use App\Models\ProjectDepartmentStatus;
use App\Models\ProjectFieldValue;
use App\Models\User;
use App\Services\PlanningBoard;
use App\Services\ProjectDates;
use Illuminate\Support\Collection;

/**
 * PlanningBoard · One project as the page reads it: the project ("task"), its cabinets with their work and checklist, its FAT and delivery rounds, its comments.
 * Part of PlanningBoard (a trait of it), which builds the board and owns its constants and caches.
 */
trait BoardProjectShape
{
    private function task(Project $project, Collection $rows, Collection $comments, \Carbon\Carbon $today, User $user, ?Collection $allCabinets): array
    {
        $dues = $rows->map(fn ($s) => self::ymd($s, 'due_date'))->filter();
        $starts = $rows->map(fn ($s) => self::ymd($s, 'start_date'))->filter();
        $date = $project->due_date?->format('Y-m-d') ?? $dues->max() ?? ($project->created_at ?? $today)->format('Y-m-d');
        $start = $project->start_date?->format('Y-m-d') ?? $starts->min();

        // PMO roles get every cabinet of the project (even one nothing was dispatched on yet); everyone else only those with their work
        $byCabinet = $rows->groupBy(fn ($s) => $s->cabinetTask->cabinet_id);
        $cabinets = $allCabinets
            ? ($allCabinets->get($project->id) ?? collect())->map(fn (Cabinet $c) => $this->cabinet($c, $byCabinet->get($c->id, collect()), $today))
            : $byCabinet->map(fn (Collection $cab) => $this->cabinet($cab->first()->cabinetTask->cabinet, $cab, $today));
        $stages = $rows->groupBy('department_id');

        return [
            'id' => 'p'.$project->id,
            'title' => $project->project_name,
            'so' => $project->project_no,
            'description' => (string) $project->description,
            'startDate' => ($start && $start <= $date) ? $start : null,
            'date' => $date,
            // the PROJECT's status: the one Admin / PM picked (Settings > สถานะงาน), else PMO's own project status - never the departments' work
            'statusId' => $project->boardStatus?->pageId() ?? 'pmo_'.$project->status,
            'manualStatus' => $project->boardStatus !== null,
            'boardNo' => $project->board_id,
            'priority' => ['low' => 'low', 'high' => 'high', 'urgent' => 'urgent'][$project->priority] ?? 'none',
            'labels' => $project->boardLabels->map(fn ($l) => 'l'.$l->id)->values()->all(),
            // the people in charge are the departments that hold work on the project
            'assignees' => $stages->keys()->map(fn ($id) => self::deptKey((int) $id))->values()->all(),
            'owners' => $project->assignees->pluck('id')->values()->all(),
            'followers' => $project->followers->pluck('id')->values()->all(),   // "ติดตามโครงการ" (ProjectFollowers)
            'fat' => self::fat($project),
            'deliveries' => ($this->deliveries->get($project->id) ?? collect())->map(fn (ProjectDelivery $d) => self::delivery($d))->values()->all(),
            'reschedule' => ProjectDates::brief($this->dateChanges->get('p'.$project->id, collect()), $project->due_date, route('projects.show', $project)),   // "เลื่อน n" (null = never postponed)   // วัน FAT: the latest round (ProjectFatController), on the calendar and the timeline
            // the project's own record (shown / edited in "ข้อมูลโครงการ"; editing it is for Admin / PM)
            'info' => [
                // money and the customer's phone only for whom Settings > สิทธิ์การใช้งาน allows - left out of the JSON, not just hidden
                'customer' => (string) $project->customer_name, 'customerPhone' => $user->allows('data.contact') ? (string) $project->customer_phone : '', 'poNo' => (string) $project->po_no, 'soNo' => (string) $project->sales_order_no,
                'contractValue' => $user->allows('data.finance') && $project->contract_value !== null ? (float) $project->contract_value : null,
                'margin' => $user->allows('data.finance') && $project->margin !== null ? (float) $project->margin : null,
                'owner' => (string) $project->project_owner, 'salesPerson' => (string) $project->sales_person,
                'managerId' => $project->project_manager_id, 'paymentTerms' => (string) $project->payment_terms,
                'jobTypeId' => $project->job_type_id, 'jobType' => (string) ($this->jobTypes->get($project->job_type_id) ?? ''), 'pmoStatus' => $project->status,
                'production' => (int) round((float) ($this->production->get($project->id)?->pct ?? 0)), 'cabinetCount' => (int) ($this->production->get($project->id)?->n ?? 0),
            ],
            // รอฝ่ายขายแจ้งกำหนดส่ง (ProjectDates): put off with no new date yet - never late meanwhile
            'pending' => $project->isDuePending() ? ['label' => $project->duePendingLabel()] : null,
            // what is still missing, for "ข้อมูลที่ยังขาด" (and the warning dot on the bar)
            'warnings' => array_filter([
                'undated' => $rows->filter(fn ($s) => ! self::ymd($s, 'due_date') && ! $s->isDone())->count(),
                'free' => $allCabinets ? ($allCabinets->get($project->id) ?? collect())->sum(fn ($c) => ($this->free->get($c->id) ?? collect())->count()) : 0,
                'rejected' => $allCabinets ? ($allCabinets->get($project->id) ?? collect())->sum(fn ($c) => ($this->free->get($c->id) ?? collect())->whereNotNull('rejected_at')->count()) : 0,
                'cabNoDue' => $allCabinets ? ($allCabinets->get($project->id) ?? collect())->whereNull('due_date')->count() : 0,
                'noManager' => $project->project_manager_id ? 0 : 1,
                'noDue' => $project->due_date ? 0 : 1,
            ]),   // people in charge (Planning: ผู้รับผิดชอบรายคน)
            'alertPending' => $comments->get($project->id, collect())->contains(fn ($c) => (($c['alert']['forMe'] ?? false) && ! $c['alert']['acked']) || ($c['receipt']['askMe'] ?? false)),
            'subtasks' => $cabinets->values()->all(),
            'deptStages' => $stages->map(fn (Collection $d, $deptId) => [
                'deptId' => self::deptKey((int) $deptId),
                'due' => $d->map(fn ($s) => self::ymd($s, 'due_date'))->filter()->max() ?? ($project->due_date ?? $today)->format('Y-m-d'),
                'done' => $d->every->isDone(),
                // the DEPARTMENT's status in this project: Admin / PM's pick, else worked out from its work (waiting / accepted / doing / done / late)
                'statusId' => ($own = $this->deptStatuses->get($project->id)?->get((int) $deptId)) ?? $this->statusFor($d, $today),
                'manualStatus' => $own !== null,
                // the department's own range in this project, from its work (null = nothing dated yet) - what the calendar draws
                'start' => $d->map(fn ($s) => self::ymd($s, 'start_date'))->filter()->min(),
                'end' => $d->map(fn ($s) => self::ymd($s, 'due_date'))->filter()->max(),
                'undated' => $d->filter(fn ($s) => ! self::ymd($s, 'due_date') && ! $s->isDone())->count(),
                'fields' => (object) ($user->allows('data.other_fields') || $user->inDepartment((int) $deptId) ? ($this->fieldValues->get($project->id)?->get((int) $deptId) ?? []) : []),
                'pmo' => $this->workflow($d, (int) $deptId, $user),
            ])->values()->all(),
            'comments' => $comments->get($project->id, collect())->values()->all(),
            // the change log ("การดำเนินการ") is the project page's Activity Log tree, fetched on demand; PMO roles only
            'logUrl' => $user->canViewOtherDepartments() && $user->allows('data.comments') ? route('projects.activity-tree', $project) : null,
            'createdAt' => ($project->created_at ?? $today)->getTimestampMs(),
            'updatedAt' => ($project->updated_at ?? $today)->getTimestampMs(),
            'pmoUrl' => route('projects.show', $project),
        ];
    }

    /**
     * A delivery round as Planning draws it: its days (date..end, PM's; wait = no vehicle on it yet, drawn dashed;
     * why = the delivery department sent it back), its cabinets ('c'.id) and which are packed, where it is in the four
     * steps (step n / text: "ห่อ 4/6", "แจ้งเปิดบิลแล้ว"), on what (car = the short names on the bar's tab, carFull = with
     * the plates, for the project window).
     */
    private static function delivery(ProjectDelivery $d): array
    {
        $car = match ($d->method) {
            ProjectDelivery::EXTERNAL => $d->carrier ?: ProjectDelivery::methodName(ProjectDelivery::EXTERNAL),
            ProjectDelivery::PICKUP => ProjectDelivery::methodName(ProjectDelivery::PICKUP),
            default => $d->vehicles->isNotEmpty() ? $d->vehicles->map(fn ($v) => $v->vehicle?->name ?? $v->name())->implode(' + ') : $d->typesText(),   // none put on yet: the kinds PM asked for
        };
        $step = $d->step();

        return [
            'id' => $d->id, 'round' => $d->round, 'date' => $d->firstDay()->format('Y-m-d'), 'end' => $d->lastDay()->format('Y-m-d'), 'time' => (string) $d->delivery_time, 'status' => $d->status,
            'wait' => $d->isOpen(), 'why' => $d->status === ProjectDelivery::REJECTED ? trim($d->rejected_reason.($d->rejected_note ? ' · '.$d->rejected_note : '')) : '',
            'cabs' => array_map(fn ($id) => 'c'.$id, $d->cabinet_ids ?? []), 'method' => $d->method,
            'packed' => array_values(array_map(fn ($id) => 'c'.$id, array_filter($d->cabinet_ids ?? [], fn ($id) => $d->isPacked((int) $id)))),
            'step' => $step['n'], 'stepText' => $step['text'], 'billed' => (bool) $d->billed_at,
            'car' => $car, 'carFull' => $d->method === ProjectDelivery::COMPANY ? $d->transport() : $car, 'driver' => (string) $d->vehicles->first()?->driver_name,
            'crew' => $d->crew_size ?: count($d->people()), 'people' => $d->peopleText(),
            'dep' => $d->departed_at?->format('H:i'), 'at' => $d->delivered_at?->format('Y-m-d H:i'),
            'pending' => (bool) $d->date_pending_at,   // รอฝ่ายขายแจ้งกำหนดส่ง: the day shown is the old one
        ];
    }

    /** The latest FAT round as the calendar draws it (null = none); cabinets null = every cabinet of the project. */
    private static function fat(Project $project): ?array
    {
        $f = $project->latestFat;

        return $f ? [
            'round' => $f->round, 'date' => $f->fat_date->format('Y-m-d'), 'end' => $f->lastDay()->format('Y-m-d'), 'time' => (string) $f->fat_time, 'status' => $f->status,
            'note' => (string) $f->note, 'cabinets' => $f->cabinet_ids ? array_map(fn ($id) => 'c'.$id, $f->cabinet_ids) : null,
            'pending' => (bool) $f->date_pending_at,   // รอฝ่ายขายแจ้งกำหนดส่ง: the day shown is the old one
        ] : null;
    }

    private function cabinet(Cabinet $cabinet, Collection $rows, \Carbon\Carbon $today): array
    {
        // in the cabinet's own order: Task, then its Sub Tasks (the checklist window shows Task › Sub Task › items)
        $rows = $rows->sortBy(fn ($s) => sprintf('%05d-%05d-%09d', $s->cabinetTask->sequence ?? 0, $s->sequence ?? 0, $s->id))->values();

        // { deptKey: 'YYYY-MM-DD' } - must serialise as a JSON object even when empty
        $deptDates = $rows->filter(fn ($s) => self::ymd($s, 'due_date'))->groupBy('department_id')
            ->mapWithKeys(fn ($d, $deptId) => [self::deptKey((int) $deptId) => $d->map(fn ($s) => self::ymd($s, 'due_date'))->max()])
            ->all();
        // each department's range in this cabinet (the calendar's "แยกรายตู้"); done = all of its work here finished
        $deptRanges = $rows->groupBy('department_id')->mapWithKeys(fn ($d, $deptId) => [self::deptKey((int) $deptId) => [
            'start' => $d->map(fn ($s) => self::ymd($s, 'start_date'))->filter()->min(),
            'end' => $d->map(fn ($s) => self::ymd($s, 'due_date'))->filter()->max(),
            'done' => $d->every->isDone(),
        ]])->all();

        return [
            'id' => 'c'.$cabinet->id,
            'text' => $cabinet->cabinet_name,
            'mo' => $cabinet->mo_no,
            'statusId' => $cabinet->boardStatus?->pageId() ?? $this->statusFor($rows, $today),
            'manualStatus' => $cabinet->boardStatus !== null,
            'date' => $rows->map(fn ($s) => self::ymd($s, 'due_date'))->filter()->max(),
            'deptDates' => $deptDates ?: new \stdClass,
            'deptRanges' => $deptRanges ?: new \stdClass,
            'ownDue' => $cabinet->due_date?->format('Y-m-d'),
            'reschedule' => ProjectDates::brief($this->dateChanges->get('c'.$cabinet->id, collect()), $cabinet->due_date, route('cabinets.show', $cabinet)),
            'remark' => (string) $cabinet->remark,
            // the remarks of its Tasks (the Sub Tasks' and items' ride on them below)
            'taskRemarks' => $rows->map->cabinetTask->unique('id')->filter(fn ($t) => filled($t->remark))
                ->map(fn ($t) => ['id' => 't'.$t->id, 'task' => (string) $t->name, 'text' => (string) $t->remark, 'by' => $this->remarkBy['t'.$t->id]['by'] ?? ''])->values()->all(),
            'linked' => $this->linked->get($cabinet->id, []),   // from the departments passing work to mine (read only)   // the cabinet's own due date (its record), not its work's
            'free' => ($this->free->get($cabinet->id) ?? collect())->map(fn ($s) => ['id' => 's'.$s->id, 'name' => $s->name, 'task' => $s->cabinetTask->name, 'checklists' => $s->checklists_count,
                'usual' => ($u = $this->usual['t'.$s->cabinet_subtask_template_id] ?? $this->usual['n'.$s->name] ?? null) ? self::deptKey($u) : '',
                // sent back ("ไม่รับงาน"): by whom and why, until it is handed out again
                'rejected' => $s->rejected_at ? ($s->rejectedDepartment?->name ?? 'แผนก').' ไม่รับงาน: '.$s->rejected_reason : ''])->values()->all(),
            // the cabinet's dispatched work: Task › Sub Task (department, where it is in the workflow) - even one with no item yet
            'works' => $rows->map(fn ($s) => [
                'id' => 's'.$s->id, 'task' => (string) $s->cabinetTask->name, 'name' => $s->name,
                'deptId' => self::deptKey((int) $s->department_id), 'state' => $s->assignment_status,
                // for "เลือกรับ…" (picking which waiting work to accept): its due date, since when it waits, how many items
                'due' => self::ymd($s, 'due_date'), 'since' => ($a = $s->getAttributes()['assigned_at'] ?? null) ? substr((string) $a, 0, 10) : null,
                'items' => $s->checklists->count(),
            ] + (filled($s->remark) ? ['remark' => (string) $s->remark, 'remarkBy' => $this->remarkBy['s'.$s->id]['by'] ?? ''] : []))->all(),   // its remark, under it in the checklist window
            // every item of the cabinet (the biggest part of the page): read from the rows as they are, no cast per field
            'checklist' => $rows->flatMap(function ($s) {
                [$deptId, $subId] = [self::deptKey((int) $s->department_id), 's'.$s->id];

                return $s->checklists->map(fn ($c) => $c->getAttributes())->sortBy(fn ($a) => sprintf('%05d-%09d', $a['sequence'] ?? 0, $a['id']))->map(fn ($a) => [
                    'id' => 'k'.$a['id'],
                    'text' => $a['name'],
                    'done' => (bool) $a['is_completed'],
                    'remark' => (string) ($a['remark'] ?? ''),
                    'expected' => ($a['expected_date'] ?? '') !== '' ? substr((string) $a['expected_date'], 0, 10) : null,
                    'deptId' => $deptId,
                    'subId' => $subId,
                    'from' => ($a['source_checklist_id'] ?? null) ? 'k'.$a['source_checklist_id'] : null,   // a copy of the item of the Sub Task it compares with
                ] + (($a['remark'] ?? '') !== '' ? ['remarkBy' => $this->remarkBy['k'.$a['id']]['by'] ?? ''] : [])
                  + (isset($this->tags[$a['id']]) ? ['tags' => $this->tags[$a['id']]] : [])   // Settings > สีสถานะ Checklist (status ids)
                  + (isset($this->peerTags[$a['id']]) ? ['peerTags' => $this->peerTags[$a['id']]] : []));
            })->values()->all(),
        ];
    }

    /** Where one department's Sub Tasks of this project are in the accept / start / complete workflow, and what this user may do now. */
    private function workflow(Collection $rows, int $deptId, User $user): array
    {
        $counts = $rows->countBy('assignment_status');   // once, not a pass per question below
        $n = fn (string $status) => (int) ($counts[$status] ?? 0);
        // the workflow actions belong to the department's own members (or Admin / PM, when Settings > กฎการทำงาน lets them act for it)
        $mine = ! $user->isViewOnly() && ($user->inDepartment($deptId) || ($user->canDispatchWork() && \App\Models\AppSetting::get('pmo_acts_for_departments')));

        return [
            'waiting' => $n(CabinetSubtask::ASSIGNMENT_ASSIGNED),
            'accepted' => $n(CabinetSubtask::ASSIGNMENT_ACCEPTED),
            'working' => $n(CabinetSubtask::ASSIGNMENT_IN_PROGRESS),
            'done' => $n(CabinetSubtask::ASSIGNMENT_COMPLETED),
            'canAccept' => $mine && $n(CabinetSubtask::ASSIGNMENT_ASSIGNED) > 0,
            // the oldest piece of work still waiting to be accepted (the "รอรับงาน" list: how long it has waited)
            'waitingSince' => ($w = $rows->where('assignment_status', CabinetSubtask::ASSIGNMENT_ASSIGNED)->map(fn ($s) => $s->getAttributes()['assigned_at'] ?? null)->filter()->min()) !== null ? substr((string) $w, 0, 10) : null,
            'canStart' => $mine && $n(CabinetSubtask::ASSIGNMENT_ACCEPTED) > 0,
            'canComplete' => $mine && $n(CabinetSubtask::ASSIGNMENT_IN_PROGRESS) > 0,
        ];
    }

    /** Worst-first: overdue, then all done, then in progress, then accepted, else waiting. Returns the page id of a built-in status. */
    private function statusFor(Collection $rows, \Carbon\Carbon $today): string
    {
        $done = fn ($s) => $s->isDone();   // checklist complete or "เสร็จงาน" pressed (CabinetSubtask::isDone)
        $ids = BoardStatus::PAGE_IDS;

        return match (true) {
            $rows->isEmpty() => $ids['todo'],
            $rows->contains(fn ($s) => ! $done($s) && ($due = self::ymd($s, 'due_date')) !== null && self::before($due, $today)) => $ids['late'],
            $rows->every($done) => $ids['done'],
            $rows->contains(fn ($s) => $s->assignment_status === CabinetSubtask::ASSIGNMENT_IN_PROGRESS) => $ids['doing'],
            $rows->contains(fn ($s) => $s->assignment_status === CabinetSubtask::ASSIGNMENT_ACCEPTED) => $ids['accepted'],
            default => $ids['todo'],
        };
    }

    /**
     * The Activity box per project id: the discussion (notes) only - updates people post for everyone involved. Newest 30,
     * oldest first (the order the page expects). PMO's change log is not mixed in (see `logUrl`).
     */
    private function comments(Collection $projectIds, User $user): Collection
    {
        if ($projectIds->isEmpty() || ! $user->allows('data.comments')) {   // Settings > สิทธิ์การใช้งาน
            return collect();
        }

        $notes = ActivityLog::query()->whereIn('project_id', $projectIds)->where('action', 'note')->with('user:id,name,avatar_path', 'attachments')->orderBy('id')->get();
        // an alert / a tag: who it reached and who has acknowledged ("รับทราบ 3/5 · ยังไม่รับทราบ: …"), names read once
        $asked = $notes->flatMap(fn ($l) => $l->changes['ack_targets'] ?? [])->unique();
        $names = $asked->isEmpty() ? collect() : User::whereKey($asked)->pluck('name', 'id');
        $receipt = function (ActivityLog $l) use ($names, $user) {
            $targets = collect($l->changes['ack_targets'] ?? [])->map(fn ($id) => (int) $id)->unique();
            if ($targets->isEmpty()) {
                return null;
            }
            $acked = collect($l->changes['acks'] ?? [])->pluck('user_id')->map(fn ($id) => (int) $id);

            return ['acked' => $targets->intersect($acked)->count(), 'total' => $targets->count(),
                'pending' => $targets->diff($acked)->map(fn ($id) => $names[$id] ?? '')->filter()->sort()->values()->all(),
                'askMe' => $targets->contains($user->id) && ! $acked->contains($user->id)];
        };

        return $notes->groupBy('project_id')->map(
                fn (Collection $logs) => $logs->take(-30)->values()->map(fn (ActivityLog $l) => [
                    'id' => 'a'.$l->id,
                    'author' => $l->user?->name ?? 'ระบบ',
                    'text' => $l->description,
                    'at' => $l->created_at->getTimestampMs(),
                    'userId' => $l->user_id,
                    'avatar' => $l->user?->avatar_url,
                    'mine' => $l->user_id === $user->id,
                    'edited' => (bool) ($l->changes['edited'] ?? false),
                    'replyTo' => isset($l->changes['reply_to']) ? 'a'.$l->changes['reply_to'] : null,
                    'alert' => empty($l->changes['alert_department_id']) ? null : [
                        'deptId' => self::deptKey((int) $l->changes['alert_department_id']),
                        'acks' => collect($l->changes['acks'] ?? [])->map(fn ($a) => ['name' => $a['name'] ?? '', 'at' => $a['at'] ?? null])->values()->all(),
                        'forMe' => $user->inDepartment((int) $l->changes['alert_department_id']),
                        'acked' => collect($l->changes['acks'] ?? [])->contains('user_id', $user->id),
                    ],
                    'receipt' => $receipt($l),
                    'files' => $l->attachments->map(fn ($a) => ['name' => $a->original_name, 'url' => $a->url, 'size' => $a->size_for_humans, 'image' => str_starts_with((string) $a->mime_type, 'image/')])->values()->all(),
                ])
            );
    }
}
