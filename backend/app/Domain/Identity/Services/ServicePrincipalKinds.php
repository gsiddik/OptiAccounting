<?php

namespace App\Domain\Identity\Services;

use App\Domain\Shared\DomainException;

/**
 * The kinds of machine identity OptiEntry knows. A kind names who the principal acts for (its source system), the first part of its
 * reserved address and its display name. Adding a kind is a code change reviewed with the adapter that needs it; the table only checks the
 * shape of the name.
 */
final class ServicePrincipalKinds
{
    public const OPTIFLEET_INTEGRATION = 'OPTIFLEET_INTEGRATION';

    public const OPTIDELI_INTEGRATION = 'OPTIDELI_INTEGRATION';

    private const DEFINITIONS = [
        self::OPTIFLEET_INTEGRATION => ['source_system' => 'optifleet', 'local_part' => 'optifleet.integration', 'name' => 'Integrasi OptiFleet'],
        self::OPTIDELI_INTEGRATION => ['source_system' => 'optideli', 'local_part' => 'optideli.integration', 'name' => 'Integrasi OptiDeli'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::DEFINITIONS);
    }

    public static function has(string $kind): bool
    {
        return isset(self::DEFINITIONS[$kind]);
    }

    /** @return array{source_system:string,local_part:string,name:string} */
    public static function definition(string $kind): array
    {
        return self::DEFINITIONS[$kind] ?? throw new DomainException('Unknown service principal kind. Known kinds: '.implode(', ', self::all()).'.', 'SERVICE_PRINCIPAL_KIND_UNKNOWN', 422, ['field' => 'kind']);
    }

    /** The first principal of a tenant uses the plain address; a replacement for a revoked one gets a number, so the old user stays what it was. */
    public static function address(string $kind, string $tenantCode, int $ordinal = 1): string
    {
        $local = self::definition($kind)['local_part'].($ordinal > 1 ? ".{$ordinal}" : '');

        return "{$local}@{$tenantCode}.".ReservedAddress::DOMAIN;
    }
}
