<?php

namespace App\Domain\Accounting\Support;

use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;

/**
 * Decimal-safe money helpers. Amounts enter as strings (or integers), are held as BigDecimal and leave as fixed
 * four-decimal strings matching NUMERIC(20,4). No float is ever used, and nothing is rounded silently: more decimals
 * than the profile's currency scale is refused.
 */
final class Money
{
    /** NUMERIC(20,4): sixteen integer digits. */
    private const MAX = '9999999999999999.9999';

    public static function parse(mixed $value, int $scale, string $field = 'amount', ?int $line = null): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }
        if (is_float($value) || is_bool($value) || is_array($value) || (is_string($value) && ! preg_match('/^\d+(\.\d+)?$/', $value)) || (is_int($value) && $value < 0)) {
            throw self::invalid("{$field} must be a non-negative decimal string.", $line);
        }

        try {
            $amount = BigDecimal::of((string) $value);
            $amount = $amount->strippedOfTrailingZeros();
            if ($amount->getScale() > $scale) {
                throw self::invalid("{$field} has more than {$scale} decimal places.", $line);
            }
        } catch (MathException) {
            throw self::invalid("{$field} is not a number.", $line);
        }

        if ($amount->isGreaterThan(self::MAX)) {
            throw self::invalid("{$field} is too large.", $line);
        }

        return $amount->toScale(4, RoundingMode::Unnecessary);
    }

    public static function sum(iterable $amounts): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return $total->toScale(4, RoundingMode::Unnecessary);
    }

    public static function str(BigDecimal|string|int $amount): string
    {
        return BigDecimal::of((string) $amount)->toScale(4, RoundingMode::Unnecessary)->toString();
    }

    private static function invalid(string $message, ?int $line): DomainException
    {
        return new DomainException($message, 'AMOUNT_INVALID', 422, $line === null ? [] : ['line' => $line]);
    }
}
