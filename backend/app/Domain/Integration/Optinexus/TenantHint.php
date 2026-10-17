<?php

namespace App\Domain\Integration\Optinexus;

use Illuminate\Support\Str;

/**
 * `tenant_hint` arrives on the sign-in start from another product's switcher. It only tells OptiNexus which of a person's
 * tenants to open; it grants nothing, and OptiNexus checks it. It is still forwarded only when it looks like what it must be (a
 * tenant id, or a tenant code), so nothing else a browser can put in a link travels to OptiNexus.
 */
final class TenantHint
{
    public static function sanitize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        if (Str::isUuid($value)) {
            return strtolower($value);
        }

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/', $value) === 1 ? $value : null;
    }
}
