<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * The installation's identity mode (docs/architecture/SAAS_ARCHITECTURE.md §1).
 * Authorization code must not branch on it; only identity/entitlement sources do.
 */
final class IdentityMode
{
    public const STANDALONE = 'standalone';

    public const OPTINEXUS = 'optinexus';

    public static function current(): string
    {
        return self::assertValid((string) config('optiaccounting.identity_mode'));
    }

    public static function assertValid(string $mode): string
    {
        if (! in_array($mode, [self::STANDALONE, self::OPTINEXUS], true)) {
            throw new InvalidArgumentException(
                "Invalid OPTIACCOUNTING_IDENTITY_MODE [{$mode}]; expected standalone or optinexus."
            );
        }

        return $mode;
    }
}
