<?php

namespace App\Services;

use App\Models\Cabinet;
use App\Models\CabinetSubtask;
use App\Models\CabinetSubtaskTemplate;
use App\Models\CabinetTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which Sub Task compares its checklist with which (e.g. Production "ตรวจรับอุปกรณ์เข้า" ← จัดซื้อ "สั่งซื้ออุปกรณ์"): set on
 * the cabinet template, copied to each cabinet made or copied from it, and sent to the cabinets made earlier on request.
 * HandoffService reads the cabinets' pairs (the list next to it, the dates, the notices).
 */
class SubtaskPairs
{
    /**
     * Set what a template Sub Task compares with: other Sub Tasks of the same template. Those in $viewIds it only follows
     * ("ดูอย่างเดียว": no copies, nothing to tick, e.g. ขาย watching จัดซื้อ → คลัง); those in $ownIds it uses the list of
     * ("ใช้รายการเดียวกัน": copies it ticks for its own work, reaching nobody else, e.g. Production assembling them).
     *
     * @param  list<int>  $sourceIds
     * @param  list<int>  $viewIds
     * @param  list<int>  $ownIds
     */
    public function setTemplatePairs(CabinetSubtaskTemplate $sub, array $sourceIds, array $viewIds = [], array $ownIds = []): void
    {
        $sourceIds = collect($sourceIds)->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => $id === $sub->id)->values();
        $templateId = $sub->taskTemplate->cabinet_template_id;
        $valid = CabinetSubtaskTemplate::whereIn('id', $sourceIds)->whereHas('taskTemplate', fn ($q) => $q->where('cabinet_template_id', $templateId))->pluck('id');
        if ($valid->count() !== $sourceIds->count()) {
            throw ValidationException::withMessages(['sources' => 'เลือกได้เฉพาะ Sub Task ในเทมเพลตเดียวกัน']);
        }
        $sub->pairSources()->sync(self::withMode($valid, $viewIds, $ownIds));
        app(HandoffService::class)->forgetPairs();
    }

    /**
     * Change one cabinet's pairs (the cabinet page, Admin / PM): what this Sub Task compares with, other Sub Tasks of the same
     * cabinet. Only this cabinet; the template keeps its own. @param list<int> $sourceIds @param list<int> $viewIds only followed
     * @param list<int> $ownIds their list used, its own ticks
     */
    public function setCabinetPairs(CabinetSubtask $sub, array $sourceIds, ?\App\Models\User $actor = null, array $viewIds = [], array $ownIds = []): void
    {
        $sub->loadMissing('cabinetTask.cabinet');
        $cabinet = $sub->cabinetTask->cabinet;
        ProjectLock::assertOpen($cabinet->project_id);   // the pivot write fires no model event
        $sourceIds = collect($sourceIds)->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => $id === $sub->id)->values();
        $valid = CabinetSubtask::whereIn('id', $sourceIds)->whereHas('cabinetTask', fn ($q) => $q->where('cabinet_id', $cabinet->id))->get(['id', 'name']);
        if ($valid->count() !== $sourceIds->count()) {
            throw ValidationException::withMessages(['sources' => 'เลือกได้เฉพาะ Sub Task ในตู้เดียวกัน']);
        }
        $mode = self::withMode($valid->pluck('id'), $viewIds, $ownIds);
        $before = $sub->pairSources()->get()->mapWithKeys(fn ($s) => [$s->id => ['view_only' => (bool) $s->pivot->view_only, 'own_ticks' => (bool) $s->pivot->own_ticks]])->sortKeys()->all();
        $sub->pairSources()->sync($mode);
        app(HandoffService::class)->forgetPairs();
        // this one first (a dropped pair frees its copies), then those comparing with it: an item that was a copy here can now be theirs
        $receivers = DB::table('cabinet_subtask_pairs')->where('source_subtask_id', $sub->id)->pluck('cabinet_subtask_id');
        $this->share(function (SharedChecklist $shared) use ($sub, $receivers) {
            $shared->syncReceivers([$sub->id]);
            $shared->syncReceivers($receivers);
        });

        ksort($mode);
        if ($before !== $mode) {
            \App\Models\ActivityLog::record($cabinet->project_id, $actor?->id, $sub, 'updated', $valid->isEmpty()
                ? "เลิกเทียบ Checklist ของ {$sub->name} ({$cabinet->mo_no})"
                : "ตั้งคู่เทียบ {$sub->name} ({$cabinet->mo_no}) กับ ".$valid->map(fn ($s) => $s->name.($mode[$s->id]['view_only'] ? ' (ดูอย่างเดียว)' : ($mode[$s->id]['own_ticks'] ? ' (ใช้รายการเดียวกัน)' : '')))->implode(', '));
        }
    }

    /** A cabinet just made from a template: its Sub Tasks take the template's pairs. @param array<int, int> $made template sub task id => cabinet sub task id */
    public function copyFromTemplate(array $made): void
    {
        if (! $made) {
            return;
        }
        $rows = DB::table('cabinet_subtask_template_pairs')->whereIn('cabinet_subtask_template_id', array_keys($made))->get()
            ->filter(fn ($p) => isset($made[$p->source_subtask_template_id]))
            ->map(fn ($p) => $this->row($made[$p->cabinet_subtask_template_id], $made[$p->source_subtask_template_id], (bool) $p->view_only, (bool) $p->own_ticks));
        DB::table('cabinet_subtask_pairs')->insertOrIgnore($rows->values()->all());
        app(HandoffService::class)->forgetPairs();
        $this->share(fn (SharedChecklist $shared) => $shared->syncReceivers($rows->pluck('cabinet_subtask_id')));
    }

    /** A cabinet copied from another: the copy keeps its pairs. @param array<int, int> $made old sub task id => new one */
    public function copyFromCabinet(array $made): void
    {
        if (! $made) {
            return;
        }
        $rows = DB::table('cabinet_subtask_pairs')->whereIn('cabinet_subtask_id', array_keys($made))->get()
            ->filter(fn ($p) => isset($made[$p->source_subtask_id]))
            ->map(fn ($p) => $this->row($made[$p->cabinet_subtask_id], $made[$p->source_subtask_id], (bool) $p->view_only, (bool) $p->own_ticks));
        DB::table('cabinet_subtask_pairs')->insertOrIgnore($rows->values()->all());
        app(HandoffService::class)->forgetPairs();
        $this->share(fn (SharedChecklist $shared) => $shared->syncReceivers($rows->pluck('cabinet_subtask_id')));
    }

    /** A template duplicated: the copy keeps its pairs. @param array<int, int> $made old template sub task id => new one */
    public function copyTemplatePairs(array $made): void
    {
        if (! $made) {
            return;
        }
        $now = now();
        $rows = DB::table('cabinet_subtask_template_pairs')->whereIn('cabinet_subtask_template_id', array_keys($made))->get()
            ->filter(fn ($p) => isset($made[$p->source_subtask_template_id]))
            ->map(fn ($p) => ['cabinet_subtask_template_id' => $made[$p->cabinet_subtask_template_id], 'source_subtask_template_id' => $made[$p->source_subtask_template_id], 'view_only' => (bool) $p->view_only, 'own_ticks' => (bool) $p->own_ticks, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('cabinet_subtask_template_pairs')->insertOrIgnore($rows->values()->all());
        app(HandoffService::class)->forgetPairs();
    }

    /** The cabinets made from this template whose pairs can still change (projects not closed). */
    public function cabinetsOf(CabinetTemplate $template): Collection
    {
        return Cabinet::where('cabinet_template_id', $template->id)
            ->whereHas('project', fn ($q) => $q->whereNotIn('status', [\App\Models\Project::STATUS_COMPLETED, \App\Models\Project::STATUS_CANCELLED]))
            ->get();
    }

    /**
     * Send the template's pairs to the cabinets made from it earlier: each cabinet's pairs become the template's. A cabinet
     * Sub Task matches a template one by the one it was copied from, else by name. Returns how many cabinets got pairs.
     */
    public function applyToCabinets(CabinetTemplate $template): int
    {
        $template->loadMissing('taskTemplates.subtaskTemplates');
        $templateSubs = $template->taskTemplates->flatMap->subtaskTemplates;
        $byName = $templateSubs->groupBy(fn ($s) => self::norm($s->name))->map(fn ($g) => $g->first()->id);
        $pairs = DB::table('cabinet_subtask_template_pairs')->whereIn('cabinet_subtask_template_id', $templateSubs->pluck('id'))->get();
        $cabinets = $this->cabinetsOf($template)->pluck('id');
        if ($cabinets->isEmpty()) {
            return 0;
        }

        $subtasks = CabinetSubtask::whereHas('cabinetTask', fn ($q) => $q->whereIn('cabinet_id', $cabinets))->with('cabinetTask:id,cabinet_id')
            ->get(['id', 'cabinet_task_id', 'cabinet_subtask_template_id', 'name']);
        $got = 0;
        DB::transaction(function () use ($subtasks, $pairs, $byName, &$got) {
            DB::table('cabinet_subtask_pairs')->whereIn('cabinet_subtask_id', $subtasks->pluck('id'))->delete();
            foreach ($subtasks->groupBy(fn ($s) => $s->cabinetTask->cabinet_id) as $cabinetSubs) {
                // template sub task id => this cabinet's sub tasks made from it
                $made = $cabinetSubs->groupBy(fn ($s) => $s->cabinet_subtask_template_id ?: ($byName[self::norm($s->name)] ?? 0));
                $rows = $pairs->flatMap(fn ($p) => collect($made->get($p->cabinet_subtask_template_id, []))
                    ->crossJoin(collect($made->get($p->source_subtask_template_id, [])))
                    ->map(fn ($two) => $this->row($two[0]->id, $two[1]->id, (bool) $p->view_only, (bool) $p->own_ticks)));
                if ($rows->isNotEmpty()) {
                    DB::table('cabinet_subtask_pairs')->insertOrIgnore($rows->all());
                    $got++;
                }
            }
        });
        app(HandoffService::class)->forgetPairs();
        $this->share(fn (SharedChecklist $shared) => $shared->syncCabinets($cabinets));

        return $got;
    }

    /** The lists of the Sub Tasks comparing follow their pairs (SharedChecklist); the progress of those that changed follows. */
    private function share(\Closure $sync): void
    {
        $shared = app(SharedChecklist::class);
        $sync($shared);
        $progress = app(ProgressService::class);
        CabinetSubtask::whereKey($shared->takeTouched())->get()->each(fn ($s) => $progress->recalculateSubtask($s));
        PlanningBoard::touch();
    }

    private function row(int $to, int $from, bool $viewOnly = false, bool $ownTicks = false): array
    {
        return ['cabinet_subtask_id' => $to, 'source_subtask_id' => $from, 'view_only' => $viewOnly, 'own_ticks' => $ownTicks && ! $viewOnly, 'created_at' => now(), 'updated_at' => now()];
    }

    /** sync() rows: id => ['view_only' => …, 'own_ticks' => …] (view only wins). @param Collection<int, int> $ids @param list<int> $viewIds @param list<int> $ownIds */
    private static function withMode(Collection $ids, array $viewIds, array $ownIds = []): array
    {
        $view = array_map('intval', $viewIds);
        $own = array_map('intval', $ownIds);

        return $ids->mapWithKeys(fn ($id) => [(int) $id => ['view_only' => $v = in_array((int) $id, $view, true), 'own_ticks' => ! $v && in_array((int) $id, $own, true)]])->all();
    }

    public static function norm(string $s): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s)));
    }
}
