<?php

namespace App\Domain\Identity\Services;

use App\Domain\Shared\DomainException;
use Closure;

/**
 * The address range `optientry.invalid` (RFC 2606 `.invalid`: it can never receive mail) belongs to service principals, which only the
 * platform operator issues (ServicePrincipalRegistry). A person can never hold such an address: User refuses it on save, the services refuse
 * it before they look an account up by address, and the database refuses it at commit unless a registry row exists (migration
 * 2026_10_19_100001). Mirrors the SQL function optientry_reserved_address().
 */
final class ReservedAddress
{
    public const DOMAIN = 'optientry.invalid';

    private static int $open = 0;

    public static function matches(?string $email): bool
    {
        $address = strtolower(trim((string) $email));
        $at = strrpos($address, '@');
        $domain = rtrim($at === false ? $address : substr($address, $at + 1), '.');

        return $domain === self::DOMAIN || str_ends_with($domain, '.'.self::DOMAIN);
    }

    /** @throws DomainException EMAIL_RESERVED when the address is on the range and the registry is not the caller */
    public static function assertHuman(?string $email, string $field = 'email'): void
    {
        if (self::$open === 0 && self::matches($email)) {
            throw new DomainException('This address is reserved for system service identities and cannot be used by a person.', 'EMAIL_RESERVED', 422, ['field' => $field]);
        }
    }

    /** Only ServicePrincipalRegistry::issue() calls this: the one place that creates a user on the reserved range. */
    public static function whileIssuing(Closure $callback): mixed
    {
        self::$open++;

        try {
            return $callback();
        } finally {
            self::$open--;
        }
    }
}
