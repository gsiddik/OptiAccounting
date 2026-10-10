<?php

namespace App\Domain\FixedAsset\Services\Depreciation;

use Brick\Math\BigDecimal;

/**
 * A book depreciation method. Methods are strategies selected by code, never conditionals scattered through the services. A method turns
 * the asset's terms into the amount of each month of its life; it knows nothing about calendars, accounts or posting. The amounts it
 * returns add up to the depreciable basis (cost - residual) exactly and never exceed it, whatever the rounding.
 */
interface DepreciationMethod
{
    public function code(): string;

    /** Does the method need a useful life? (A method that never depreciates, such as land, does not.) */
    public function needsLife(): bool;

    /**
     * Normalise and validate the method parameters (what is stored on the asset).
     *
     * @param  array<string,mixed>|null  $params
     * @return array<string,mixed>|null
     */
    public function params(?array $params): ?array;

    /**
     * @param  array<string,mixed>|null  $params  as returned by params()
     * @return array<int,BigDecimal> month offset (0 = the first month of the schedule) => amount at 4 decimals; months with no depreciation are absent
     */
    public function amounts(BigDecimal $cost, BigDecimal $residual, int $months, int $scale, ?array $params): array;
}
