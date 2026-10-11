<?php

namespace App\Domain\FixedAsset\Services\Depreciation;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Equal monthly amounts rounded half up at the currency scale; the last month takes whatever rounding left, so the total is exactly the basis. */
final class StraightLine implements DepreciationMethod
{
    public function code(): string
    {
        return 'STRAIGHT_LINE';
    }

    public function needsLife(): bool
    {
        return true;
    }

    public function params(?array $params): ?array
    {
        return null;
    }

    public function amounts(BigDecimal $cost, BigDecimal $residual, int $months, int $scale, ?array $params): array
    {
        $basis = $cost->minus($residual);
        $unit = $basis->dividedBy($months, $scale, RoundingMode::HalfUp);
        $left = $basis;
        $amounts = [];
        for ($k = 0; $k < $months; $k++) {
            $amount = $k === $months - 1 ? $left : ($unit->isGreaterThan($left) ? $left : $unit);
            $left = $left->minus($amount);
            if ($amount->isPositive()) {
                $amounts[$k] = $amount->toScale(4, RoundingMode::Unnecessary);
            }
        }

        return $amounts;
    }
}
