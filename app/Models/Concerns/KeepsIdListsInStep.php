<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * A list of ids still kept in a JSON column (the code reads it as before) is also kept, row by row, in its join table
 * (2026_11_17_000003), so the database knows each one as a real reference. The one place that keeps the two in step:
 * after every save of the model (create / update / ->save()); a list item that points nowhere is left out of the join
 * table, and the all_* flag says whether the list is empty (= every cabinet / department).
 *
 * The model says what it keeps with idLists(): [json column, join table, its own key there, the other key, other table, flag].
 * extraPivot() may add columns of a join row (ProjectDelivery: packed_at / packed_by).
 * Writes go through the query builder, never the model, so no event fires again.
 */
trait KeepsIdListsInStep
{
    /** @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}> */
    abstract protected static function idLists(): array;

    protected static function bootKeepsIdListsInStep(): void
    {
        static::saved(fn (self $model) => $model->keepIdListsInStep());
    }

    public function keepIdListsInStep(): void
    {
        foreach (static::idLists() as [$json, $join, $own, $other, $otherTable, $flag]) {
            $wanted = collect((array) ($this->getAttribute($json) ?? []))->map(fn ($id) => (int) $id)->filter()->unique()->values();
            $wanted = $wanted->isEmpty() ? $wanted : DB::table($otherTable)->whereIn('id', $wanted->all())->pluck('id')->map(fn ($id) => (int) $id);
            $rows = DB::table($join)->where($own, $this->getKey())->get()->keyBy(fn ($r) => (int) $r->{$other});

            DB::table($join)->where($own, $this->getKey())->whereNotIn($other, $wanted->all() ?: [0])->delete();
            foreach ($wanted as $id) {
                $values = $this->extraPivot($join, $id, $rows->get($id));
                if ($rows->has($id)) {
                    if ($values) {
                        DB::table($join)->where('id', $rows->get($id)->id)->update($values + ['updated_at' => now()]);
                    }
                } else {
                    DB::table($join)->insert([$own => $this->getKey(), $other => $id] + $values + ['created_at' => now(), 'updated_at' => now()]);
                }
            }

            $all = (array) ($this->getAttribute($json) ?? []) === [];
            // a model just made does not know the column's default (true), so then it is always written
            if (! array_key_exists($flag, $this->getAttributes()) || (bool) $this->getAttribute($flag) !== $all) {
                DB::table($this->getTable())->where($this->getKeyName(), $this->getKey())->update([$flag => $all]);
                $this->setAttribute($flag, $all);
                $this->syncOriginalAttribute($flag);
            }
        }
    }

    /** Columns of the join row besides the two keys (for a row already there: only what changed). */
    protected function extraPivot(string $join, int $otherId, ?object $before): array
    {
        return [];
    }
}
