<?php

namespace App\Domain\FixedAsset\Services\Depreciation;

use App\Domain\Shared\DomainException;

/** The registry of book depreciation methods. A new method is one class added here. */
final class DepreciationMethods
{
    /** @return array<string,DepreciationMethod> */
    private static function all(): array
    {
        static $all = null;

        return $all ??= collect([new StraightLine, new DecliningBalance, new NoDepreciation])->keyBy(fn (DepreciationMethod $m) => $m->code())->all();
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    public static function for(?string $code): DepreciationMethod
    {
        return self::all()[(string) $code] ?? throw new DomainException('The depreciation method is not supported.', 'DEPRECIATION_METHOD_UNKNOWN', 422, ['method' => $code, 'supported' => self::codes()]);
    }
}
