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

    /** Like parse, but a leading minus is allowed (a statement balance can be an overdraft, a statement line a withdrawal). */
    public static function parseSigned(mixed $value, int $scale, string $field = 'amount', ?int $line = null): BigDecimal
    {
        if (is_string($value) && str_starts_with($value, '-')) {
            return self::parse(substr($value, 1), $scale, $field, $line)->negated()->toScale(4);
        }

        return self::parse($value, $scale, $field, $line);
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

    /**
     * Split a signed amount over weights so the parts add up to it exactly (largest remainder at the currency scale;
     * ties go to the earlier part). Used to spread a document's header discount / other charges over its lines.
     *
     * @param  list<BigDecimal>  $weights  non-negative, at least one positive
     * @return list<BigDecimal> parts at scale 4, summing exactly to $total
     */
    public static function prorate(BigDecimal $total, array $weights, int $scale): array
    {
        $count = count($weights);
        $sum = BigDecimal::zero();
        foreach ($weights as $w) {
            $sum = $sum->plus($w);
        }
        if ($total->isZero() || $count === 0 || $sum->isZero()) {
            return array_fill(0, $count, BigDecimal::zero()->toScale(4));
        }

        $abs = $total->abs();
        $unit = BigDecimal::one()->dividedBy(BigDecimal::of(10)->power($scale), $scale, RoundingMode::Unnecessary);
        $floors = [];
        $fractions = [];
        foreach ($weights as $i => $w) {
            $exact = $abs->multipliedBy($w)->dividedBy($sum, $scale + 12, RoundingMode::Down);
            $floor = $exact->toScale($scale, RoundingMode::Down);
            $floors[$i] = $floor;
            $fractions[$i] = $exact->minus($floor);
        }
        $left = $abs->minus(array_reduce($floors, fn ($c, $f) => $c->plus($f), BigDecimal::zero()))->dividedBy($unit, 0, RoundingMode::Unnecessary)->toInt();
        $order = array_keys($fractions);
        usort($order, fn ($a, $b) => $fractions[$b]->compareTo($fractions[$a]) ?: $a <=> $b);
        foreach (array_slice($order, 0, $left) as $i) {
            $floors[$i] = $floors[$i]->plus($unit);
        }

        return array_map(fn ($part) => ($total->isNegative() ? $part->negated() : $part)->toScale(4, RoundingMode::Unnecessary), $floors);
    }
}
