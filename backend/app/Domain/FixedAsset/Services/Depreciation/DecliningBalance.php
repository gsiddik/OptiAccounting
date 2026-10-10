<?php

namespace App\Domain\FixedAsset\Services\Depreciation;

use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Declining balance: each month depreciates the remaining book value by factor / life months (factor 2 by default, between 1 and 4), never
 * below the residual value; the last month writes the book value down to the residual, so the total is exactly the basis. Book depreciation
 * only; no jurisdiction-specific tax depreciation rule.
 */
final class DecliningBalance implements DepreciationMethod
{
    public function code(): string
    {
        return 'DECLINING_BALANCE';
    }

    public function needsLife(): bool
    {
        return true;
    }

    public function params(?array $params): ?array
    {
        $raw = $params['factor'] ?? '2';
        try {
            $factor = BigDecimal::of(is_scalar($raw) ? (string) $raw : 'x');
        } catch (MathException) {
            throw new DomainException('The declining balance factor must be a number.', 'DEPRECIATION_PARAMS_INVALID', 422);
        }
        if ($factor->isLessThan(1) || $factor->isGreaterThan(4) || $factor->getScale() > 2) {
            throw new DomainException('The declining balance factor must be between 1 and 4 with at most two decimals.', 'DEPRECIATION_PARAMS_INVALID', 422, ['factor' => (string) $raw]);
        }

        return ['factor' => $factor->toScale(2, RoundingMode::Unnecessary)->toString()];
    }

    public function amounts(BigDecimal $cost, BigDecimal $residual, int $months, int $scale, ?array $params): array
    {
        $factor = BigDecimal::of($params['factor'] ?? '2');
        $book = $cost;
        $amounts = [];
        for ($k = 0; $k < $months; $k++) {
            $room = $book->minus($residual);
            $amount = $k === $months - 1 ? $room : $book->multipliedBy($factor)->dividedBy($months, $scale, RoundingMode::HalfUp);
            $amount = $amount->isGreaterThan($room) ? $room : $amount;
            $book = $book->minus($amount);
            if ($amount->isPositive()) {
                $amounts[$k] = $amount->toScale(4, RoundingMode::Unnecessary);
            }
        }

        return $amounts;
    }
}
