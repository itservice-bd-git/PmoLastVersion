<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * Business-day math (Mon-Fri only, no holiday calendar yet) shared by the
 * weight-based task scheduling in Cabinet/CabinetTask.
 */
class WorkingDaysCalculator
{
    public function countWorkingDays(Carbon $start, Carbon $end): int
    {
        if ($end->lessThan($start)) {
            return 0;
        }

        $days = 0;
        $cursor = $start->copy();

        while ($cursor->lessThanOrEqualTo($end)) {
            if (! $cursor->isWeekend()) {
                $days++;
            }
            $cursor->addDay();
        }

        return $days;
    }

    public function addWorkingDays(Carbon $start, int $days): Carbon
    {
        $cursor = $start->copy();
        $added = 0;

        while ($added < $days) {
            $cursor->addDay();
            if (! $cursor->isWeekend()) {
                $added++;
            }
        }

        return $cursor;
    }
}
