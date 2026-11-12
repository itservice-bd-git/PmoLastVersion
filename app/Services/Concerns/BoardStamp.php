<?php

namespace App\Services\Concerns;

use App\Models\User;

/** PlanningBoard: the stamp Planning polls with (ETag), and touch() to move it when something changes. */
trait BoardStamp
{
    /**
     * A cheap fingerprint of everything the board is built from - ONE query of counts / latest updated_at / sums, plus the
     * "board version" that every model save bumps (see touch()). The 15-second poll compares it with the page's copy and
     * answers 304 without building anything when nothing changed; building is ~30 queries and most of the CPU, which a
     * shared host (CloudLinux limits) feels when several tabs poll. Also changes with the day (late work) and every
     * STAMP_MAX_AGE seconds.
     */
    public function stamp(User $user, int|string|null $board, ?\Carbon\Carbon $today = null): string
    {
        $parts = collect(self::STAMP_TABLES)->map(fn (string $sum, string $table) => str_contains($sum, '*')   // the pivots have no timestamps
            ? "SELECT COUNT(*) AS n, NULL AS u, SUM({$sum}) AS s FROM {$table}"
            : "SELECT COUNT(*) AS n, MAX(updated_at) AS u, SUM({$sum}) AS s FROM {$table}");
        $rows = \Illuminate\Support\Facades\DB::select($parts->implode(' UNION ALL '));

        return md5(json_encode([
            $rows, \Illuminate\Support\Facades\Cache::get('planning:version', 0),
            $user->id, $board, ($today ?? today())->toDateString(), intdiv(now()->getTimestamp(), self::STAMP_MAX_AGE),
        ]));
    }

    /** Something was saved / deleted: bump the board version once, when the request ends (cheap; a request saves many models). */
    public static function touch(): void
    {
        $app = app();
        if ($app->bound('planning.touched')) {
            return;
        }
        $app->instance('planning.touched', true);
        $app->terminating(function () use ($app) {
            // terminating callbacks stay registered (they run again on a later terminate() in tests / long-running workers)
            if (! $app->bound('planning.touched')) {
                return;
            }
            $app->forgetInstance('planning.touched');
            \Illuminate\Support\Facades\Cache::forever('planning:version', \Illuminate\Support\Str::random(12));
        });
    }

    /**
     * A date column as 'Y-m-d' straight from the row - the board reads thousands of these, and every read of a cast date
     * makes a new Carbon (most of the build time). Same value as ->format('Y-m-d'): Laravel does not shift dates on read.
     */
    private static function ymd(\Illuminate\Database\Eloquent\Model $m, string $key): ?string
    {
        $v = $m->getAttributes()[$key] ?? null;

        return $v === null || $v === '' ? null : substr((string) $v, 0, 10);
    }

    /** Whether a 'Y-m-d' due date is before $today - exactly as Carbon's lt() (a due date is that day at 00:00). */
    private static function before(string $due, \Carbon\Carbon $today): bool
    {
        return $due.' 00:00:00.000000' < $today->format('Y-m-d H:i:s.u');
    }
}
