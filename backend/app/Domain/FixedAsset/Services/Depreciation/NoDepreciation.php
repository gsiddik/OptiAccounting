<?php

namespace App\Domain\FixedAsset\Services\Depreciation;

use Brick\Math\BigDecimal;

/** Assets that are not depreciated (land): they are capitalized, kept at cost and disposed of, but have no schedule. */
final class NoDepreciation implements DepreciationMethod
{
    public function code(): string
    {
        return 'NONE';
    }

    public function needsLife(): bool
    {
        return false;
    }

    public function params(?array $params): ?array
    {
        return null;
    }

    public function amounts(BigDecimal $cost, BigDecimal $residual, int $months, int $scale, ?array $params): array
    {
        return [];
    }
}
