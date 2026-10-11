<?php

namespace App\Domain\Reporting\Support;

use Carbon\CarbonImmutable;

/**
 * The comparison dates of a comparative report, by calendar arithmetic only (no fiscal calendar lookup, so a comparison never fails because the
 * earlier year was never set up).
 *
 *   prior year    the same dates one year earlier; a range or date that ends on the last day of a month ends on the last day of that month a year earlier
 *   prior period  the range that ends the day before this one starts and spans the same calendar months when the range is whole months (a month, a
 *                 quarter, a year), otherwise the same number of days; a balance sheet date moves back one month, month end to month end
 */
final class ComparisonPeriod
{
    /** @return array{from:string,to:string} */
    public static function priorYearRange(string $from, string $to): array
    {
        return ['from' => self::yearBack($from), 'to' => self::yearBack($to)];
    }

    public static function priorYearDate(string $date): string
    {
        return self::yearBack($date);
    }

    /** @return array{from:string,to:string} */
    public static function priorPeriodRange(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        if ($start->day === 1 && $end->isSameDay($end->endOfMonth())) {
            $months = (($end->year - $start->year) * 12) + ($end->month - $start->month) + 1;
            $newStart = $start->subMonthsNoOverflow($months);

            return ['from' => $newStart->toDateString(), 'to' => $newStart->addMonthsNoOverflow($months - 1)->endOfMonth()->toDateString()];
        }

        $days = (int) $start->diffInDays($end) + 1;
        $newEnd = $start->subDay();

        return ['from' => $newEnd->subDays($days - 1)->toDateString(), 'to' => $newEnd->toDateString()];
    }

    public static function priorPeriodDate(string $date): string
    {
        $day = CarbonImmutable::parse($date);

        return $day->isSameDay($day->endOfMonth()) ? $day->subMonthNoOverflow()->endOfMonth()->toDateString() : $day->subMonthNoOverflow()->toDateString();
    }

    private static function yearBack(string $date): string
    {
        $day = CarbonImmutable::parse($date);
        $back = $day->subYearNoOverflow();

        return $day->isSameDay($day->endOfMonth()) ? $back->endOfMonth()->toDateString() : $back->toDateString();
    }
}
