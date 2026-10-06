<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Soft-deleting a Project/Cabinet/Task/Sub Task takes everything beneath it into
 * the trash too, and restoring it brings back exactly that set - nothing more.
 *
 * Children are stamped with the SAME deleted_at as the parent, which is how a
 * restore tells "went away with this parent" from "was deleted on its own
 * earlier" (that one keeps its own, different timestamp and stays in the trash).
 * Done as mass updates on purpose: per-row model events would write one
 * "deleted" Activity Log entry for every Task/Sub Task/Checklist in the tree.
 *
 * Hard (force) deletes are unchanged: the database's ON DELETE CASCADE handles those.
 */
trait CascadesSoftDeletes
{
    /** Parent-before-child: table => column that points at the previous level. */
    private static array $cascadeLevels = [
        'projects' => null,
        'cabinets' => 'project_id',
        'cabinet_tasks' => 'cabinet_id',
        'cabinet_subtasks' => 'cabinet_task_id',
        'cabinet_checklists' => 'cabinet_subtask_id',
    ];

    protected ?string $cascadeStamp = null;

    protected static function bootCascadesSoftDeletes(): void
    {
        static::deleted(function ($model) {
            if (! $model->isForceDeleting()) {
                $model->cascadeSoftDelete((string) $model->getRawOriginal($model->getDeletedAtColumn()));
            }
        });

        static::restoring(function ($model) {
            $model->cascadeStamp = $model->getRawOriginal($model->getDeletedAtColumn());
        });

        static::restored(function ($model) {
            if ($model->cascadeStamp) {
                $model->cascadeRestore($model->cascadeStamp);
            }
        });
    }

    private function cascadeSoftDelete(string $stamp): void
    {
        $tables = array_keys(self::$cascadeLevels);
        $start = array_search($this->getTable(), $tables, true);

        for ($i = $start + 1; $i < count($tables); $i++) {
            DB::table($tables[$i])
                ->whereNull('deleted_at')
                ->whereIn(self::$cascadeLevels[$tables[$i]], $this->cascadeParents($tables, $i, $start, $stamp))
                ->update(['deleted_at' => $stamp]);
        }
    }

    /** Deepest level first, so each level can still find its parents by their shared stamp. */
    private function cascadeRestore(string $stamp): void
    {
        $tables = array_keys(self::$cascadeLevels);
        $start = array_search($this->getTable(), $tables, true);

        for ($i = count($tables) - 1; $i > $start; $i--) {
            DB::table($tables[$i])
                ->where('deleted_at', $stamp)
                ->whereIn(self::$cascadeLevels[$tables[$i]], $this->cascadeParents($tables, $i, $start, $stamp))
                ->update(['deleted_at' => null]);
        }
    }

    /** The ids one level up: this record itself, or the rows already stamped at that level. */
    private function cascadeParents(array $tables, int $level, int $start, string $stamp): array|\Closure
    {
        if ($level - 1 === $start) {
            return [$this->getKey()];
        }

        return fn ($q) => $q->select('id')->from($tables[$level - 1])->where('deleted_at', $stamp);
    }
}
