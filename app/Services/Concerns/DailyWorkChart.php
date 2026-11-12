<?php

namespace App\Services\Concerns;

use Illuminate\Support\Carbon;

/** DailyWork: the chart of the days around the one asked, and the Thai day / month labels. */
trait DailyWorkChart
{
    /**
     * The bars of each department's small chart. One day: the week ending on it (that day stands out). A range: the
     * range itself, by day up to a month, by week up to half a year, by month beyond.
     *
     * @return list<array{start: Carbon, end: Carbon, shortLabel: string, label: string, isHighlighted: bool, unit: string}>
     */
    private function chartBars(Carbon $rangeStart, Carbon $rangeEnd, int $dayCount): array
    {
        $dayAndMonth = fn (Carbon $date) => $date->day.' '.self::SHORT_MONTH_NAMES[$date->month - 1];
        if ($dayCount === 1) {
            return array_map(function ($index) use ($rangeStart, $dayAndMonth) {
                $day = $rangeStart->copy()->subDays(self::CHART_DAYS_FOR_ONE_DAY - 1 - $index);

                return [
                    'start' => $day,
                    'end' => $day->copy()->endOfDay(),
                    'shortLabel' => self::SHORT_DAY_NAMES[$day->dayOfWeek],
                    'label' => self::SHORT_DAY_NAMES[$day->dayOfWeek].' '.$dayAndMonth($day),
                    'isHighlighted' => $index === self::CHART_DAYS_FOR_ONE_DAY - 1,
                    'unit' => 'day',
                ];
            }, range(0, self::CHART_DAYS_FOR_ONE_DAY - 1));
        }

        $unit = $dayCount <= 31 ? 'day' : ($dayCount <= 183 ? 'week' : 'month');
        $bars = [];
        for ($barStart = $rangeStart->copy(); $barStart->lte($rangeEnd); $barStart = $barEnd->copy()->addDay()->startOfDay()) {
            $barEnd = match ($unit) {
                'day' => $barStart->copy()->endOfDay(),
                'week' => $barStart->copy()->endOfWeek(Carbon::SUNDAY)->min($rangeEnd),
                'month' => $barStart->copy()->endOfMonth()->min($rangeEnd),
            };
            $bars[] = [
                'start' => $barStart->copy(),
                'end' => $barEnd->copy(),
                'shortLabel' => match ($unit) {
                    'day' => $dayCount <= 7 ? self::SHORT_DAY_NAMES[$barStart->dayOfWeek] : (string) $barStart->day,
                    'week' => $dayAndMonth($barStart),
                    'month' => self::SHORT_MONTH_NAMES[$barStart->month - 1],
                },
                'label' => match ($unit) {
                    'day' => self::SHORT_DAY_NAMES[$barStart->dayOfWeek].' '.$dayAndMonth($barStart),
                    'week' => $dayAndMonth($barStart).' – '.$dayAndMonth($barEnd),
                    'month' => self::SHORT_MONTH_NAMES[$barStart->month - 1].' '.($barStart->year + 543),
                },
                'isHighlighted' => true,
                'unit' => $unit,
            ];
        }

        return $bars;
    }

    /** what the small chart shows, in words: "7 วันล่าสุด", "รายวัน", "รายสัปดาห์", "รายเดือน" */
    private function chartLabel(int $dayCount, string $unit): string
    {
        if ($dayCount === 1) {
            return self::CHART_DAYS_FOR_ONE_DAY.' วันล่าสุด';
        }

        return ['day' => 'รายวัน', 'week' => 'รายสัปดาห์', 'month' => 'รายเดือน'][$unit];
    }

    /** "วันพุธที่ 21 ต.ค. 2569", or "1 – 21 ต.ค. 2569", "28 ก.ย. – 21 ต.ค. 2569", "28 ธ.ค. 2568 – 5 ม.ค. 2569" */
    private function rangeLabel(Carbon $firstDay, Carbon $lastDay): string
    {
        $buddhistYear = fn (Carbon $date) => ' '.($date->year + 543);
        $dayAndMonth = fn (Carbon $date) => $date->day.' '.self::SHORT_MONTH_NAMES[$date->month - 1];
        if ($firstDay->isSameDay($lastDay)) {
            return 'วัน'.self::DAY_NAMES[$firstDay->dayOfWeek].'ที่ '.$dayAndMonth($firstDay).$buddhistYear($firstDay);
        }
        if ($firstDay->year !== $lastDay->year) {
            return $dayAndMonth($firstDay).$buddhistYear($firstDay).' – '.$dayAndMonth($lastDay).$buddhistYear($lastDay);
        }
        $start = $firstDay->month === $lastDay->month ? $firstDay->day : $dayAndMonth($firstDay);

        return $start.' – '.$dayAndMonth($lastDay).$buddhistYear($lastDay);
    }

    private function dayAndMonth(Carbon $date): string
    {
        return $date->day.' '.self::SHORT_MONTH_NAMES[$date->month - 1];
    }
}
