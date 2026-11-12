<?php

namespace App\Services\Concerns;

use App\Models\BoardStatus;
use App\Models\Department;
use App\Models\DepartmentField;
use App\Models\Project;
use Illuminate\Support\Collection;

/** PlanningBoard: department colours and keys, status colours and lists, extra-field shapes. */
trait BoardPalette
{
    /**
     * Each active department's colour as Planning shows it (Settings > แผนก, else a stable palette colour by position),
     * so other pages can use the same dots.
     *
     * @return array<int, string> department id => #hex
     */
    public static function departmentColors(): array
    {
        return once(fn () => Department::where('is_active', true)->orderBy('id')->get(['id', 'color'])->values()   // once per request: rows render it many times
            ->mapWithKeys(fn (Department $d, int $i) => [$d->id => $d->color ?: self::PALETTE[$i % count(self::PALETTE)]])->all());
    }

    public static function deptKey(int $id): string
    {
        return 'd_'.$id;
    }

    /** PMO project status => colour, the same set Planning shows for a project with no status picked by hand. */
    public static function statusColor(?string $status): string
    {
        return collect(self::pmoStatuses())->firstWhere('id', 'pmo_'.$status)['color'] ?? '#94a3b8';
    }

    /** Every status the page offers, in the order set in Settings > สถานะงาน (the five built-in ones plus any added by hand). */
    public static function statuses(): array
    {
        return BoardStatus::orderBy('sort')->orderBy('id')->get()
            ->map(fn (BoardStatus $s) => ['id' => $s->pageId(), 'name' => $s->name, 'color' => $s->color, 'isDone' => $s->is_done])->all();
    }

    /**
     * PMO's own project statuses, in the same shape as statuses(): what a project shows while Admin / PM has not picked a status
     * for it (ids "pmo_<status>"). Completed and cancelled count as finished.
     */
    public static function pmoStatuses(): array
    {
        $colors = [Project::STATUS_DRAFT => '#94a3b8', Project::STATUS_PLANNING => '#8b5cf6', Project::STATUS_IN_PROGRESS => '#3b82f6',
            Project::STATUS_ON_HOLD => '#f59e0b', Project::STATUS_COMPLETED => '#10b981', Project::STATUS_CANCELLED => '#64748b'];

        return collect(Project::$statuses)->map(fn (string $name, string $key) => [
            'id' => 'pmo_'.$key, 'name' => $name, 'color' => $colors[$key] ?? '#94a3b8', 'pmo' => true,
            'isDone' => in_array($key, [Project::STATUS_COMPLETED, Project::STATUS_CANCELLED], true),
        ])->values()->all();
    }

    /** A department field in the shape the page reads: select fields carry their options grouped (a blank group name = no heading). */
    private static function fieldShape(DepartmentField $f): array
    {
        $shape = ['id' => 'f'.$f->id, 'name' => $f->name, 'showOnChip' => (bool) $f->show_on_chip];
        if ($f->type !== 'select') {
            return $shape + ['type' => $f->type, 'groups' => []];
        }

        return $shape + ['groups' => $f->options->groupBy(fn ($o) => $o->group_name ?? '')->map(fn (Collection $os, string $g) => [
            'id' => 'g'.md5($f->id.$g), 'name' => $g,
            'options' => $os->map(fn ($o) => ['id' => 'o'.$o->id, 'label' => $o->label])->values()->all(),
        ])->values()->all()];
    }
}
