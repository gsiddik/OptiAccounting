<?php

namespace App\Domain\Currency\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * The outcome of resolving the rate between a transaction currency and the functional currency for a date: the rate and where it came from.
 * `convert` is the one place an amount in the transaction currency becomes a functional amount (HALF_UP at the functional scale).
 */
final class ResolvedRate
{
    public function __construct(
        public readonly string $currency,
        public readonly string $functionalCurrency,
        public readonly BigDecimal $rate,
        public readonly int $scale,
        public readonly ?string $id = null,
        public readonly ?string $effectiveDate = null,
        public readonly ?string $type = null,
        public readonly ?string $source = null,
    ) {}

    /** The functional currency itself: rate 1, no master rate behind it. */
    public static function identity(string $functionalCurrency, int $scale): self
    {
        return new self($functionalCurrency, $functionalCurrency, BigDecimal::one(), $scale);
    }

    public function foreign(): bool
    {
        return $this->currency !== $this->functionalCurrency;
    }

    /** @return string the rate as stored (ten decimals) */
    public function rateString(): string
    {
        return (string) $this->rate->toScale(10, RoundingMode::HalfUp);
    }

    public function convert(BigDecimal $transactionAmount): BigDecimal
    {
        return $this->foreign()
            ? $transactionAmount->multipliedBy($this->rate)->toScale($this->scale, RoundingMode::HalfUp)->toScale(4)
            : $transactionAmount->toScale(4, RoundingMode::HalfUp);
    }
}
