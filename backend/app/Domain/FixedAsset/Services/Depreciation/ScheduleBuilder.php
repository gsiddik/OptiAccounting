<?php

namespace App\Domain\FixedAsset\Services\Depreciation;

use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * The deterministic depreciation schedule of an asset: the same terms always give the same rows. The calendar is the calendar month; the
 * first month follows the start policy (the month of capitalization, or the next one). Every row is rounded at the currency scale by the
 * method, the running totals are derived from the rows, and the total is exactly the depreciable basis.
 */
final class ScheduleBuilder
{
    /**
     * @param  array<string,mixed>|null  $params
     * @return list<array{sequence_no:int,period_start:string,period_end:string,amount:string,accumulated_after:string,book_value_after:string}>
     */
    public static function build(string $method, string $cost, string $residual, ?int $lifeMonths, string $capitalizationDate, string $startPolicy, int $scale, ?array $params): array
    {
        $strategy = DepreciationMethods::for($method);
        if (! $strategy->needsLife() || $lifeMonths === null) {
            return [];
        }

        $cost = BigDecimal::of($cost);
        $residual = BigDecimal::of($residual);
        $first = Carbon::parse($capitalizationDate)->startOfMonth();
        if ($startPolicy === 'NEXT_MONTH') {
            $first = $first->addMonthNoOverflow();
        }

        $rows = [];
        $accumulated = BigDecimal::zero();
        foreach ($strategy->amounts($cost, $residual, $lifeMonths, $scale, $params) as $offset => $amount) {
            $start = $first->copy()->addMonthsNoOverflow($offset);
            $accumulated = $accumulated->plus($amount);
            $rows[] = [
                'sequence_no' => $offset + 1, 'period_start' => $start->toDateString(), 'period_end' => $start->copy()->endOfMonth()->toDateString(),
                'amount' => $amount->toScale(4)->toString(), 'accumulated_after' => $accumulated->toScale(4)->toString(),
                'book_value_after' => $cost->minus($accumulated)->toScale(4)->toString(),
            ];
        }

        return $rows;
    }
}
