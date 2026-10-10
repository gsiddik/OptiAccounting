<?php

namespace App\Domain\Tax\Services;

use App\Domain\Tax\Models\TaxCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The one place tax is calculated (OA4 §29): AP, expense and AR documents, the API previews and the tests all call this and nobody
 * repeats the formulas. Pure arithmetic on BigDecimal, no database: the same inputs always give the same result.
 *
 *  - EXCLUSIVE: the amount entered is the base; tax = base x rate / 100.
 *  - INCLUSIVE: the amount entered contains the tax; tax = entered x rate / (100 + rate) and base = entered - tax, so base + tax is exactly
 *    what was entered.
 *  - A zero rated or exempt treatment, or a rate of zero, gives no tax and a base equal to the amount entered.
 *
 * Rounding: HALF_UP at the currency scale, once per line; the base absorbs the rounding of an inclusive amount.
 */
final class TaxCalculator
{
    /** @return array{entered:BigDecimal,base:BigDecimal,tax:BigDecimal,rate:BigDecimal} amounts at scale 4 */
    public static function calculate(string $method, string $treatment, BigDecimal $entered, BigDecimal $ratePercent, int $scale): array
    {
        $rate = $treatment === TaxCode::STANDARD ? $ratePercent : BigDecimal::zero();
        if ($rate->isZero()) {
            $tax = BigDecimal::zero();
        } elseif ($method === TaxCode::INCLUSIVE) {
            $tax = $entered->multipliedBy($rate)->dividedBy(BigDecimal::of(100)->plus($rate), $scale, RoundingMode::HalfUp);
        } else {
            $tax = $entered->multipliedBy($rate)->dividedBy(100, $scale, RoundingMode::HalfUp);
        }
        $base = $method === TaxCode::INCLUSIVE ? $entered->minus($tax) : $entered;

        return [
            'entered' => $entered->toScale(4, RoundingMode::Unnecessary), 'base' => $base->toScale(4, RoundingMode::Unnecessary),
            'tax' => $tax->toScale(4, RoundingMode::Unnecessary), 'rate' => $rate->toScale(6, RoundingMode::Unnecessary),
        ];
    }
}
