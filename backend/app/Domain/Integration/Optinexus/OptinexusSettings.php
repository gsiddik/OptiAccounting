<?php

namespace App\Domain\Integration\Optinexus;

use App\Support\IdentityMode;

/** Read-only view of the OptiNexus settings; keeps "is it on and complete" in one place. */
final class OptinexusSettings
{
    public static function active(): bool
    {
        return IdentityMode::current() === IdentityMode::OPTINEXUS;
    }

    public static function baseUrl(): string
    {
        return (string) config('optientry.optinexus.base_url');
    }

    public static function applicationCode(): string
    {
        return (string) config('optientry.optinexus.application_code');
    }

    public static function clientId(): string
    {
        return (string) config('optientry.optinexus.sso.client_id');
    }

    /** Settings still missing for the OIDC sign-in. */
    public static function missingForSso(): array
    {
        return self::missing([
            'OPTINEXUS_BASE_URL' => self::baseUrl(),
            'OPTINEXUS_SSO_CLIENT_ID' => config('optientry.optinexus.sso.client_id'),
            'OPTINEXUS_SSO_CLIENT_SECRET' => config('optientry.optinexus.sso.client_secret'),
        ]);
    }

    /** Settings still missing for the service account (permissions, entitlements, events). */
    public static function missingForService(): array
    {
        return self::missing([
            'OPTINEXUS_BASE_URL' => self::baseUrl(),
            'OPTINEXUS_SERVICE_CLIENT_ID' => config('optientry.optinexus.service.client_id'),
            'OPTINEXUS_SERVICE_CLIENT_SECRET' => config('optientry.optinexus.service.client_secret'),
        ]);
    }

    public static function ssoEnabled(): bool
    {
        return self::active() && self::missingForSso() === [];
    }

    public static function redirectUri(): string
    {
        return (string) (config('optientry.optinexus.sso.redirect_uri') ?: url('/api/v1/auth/sso/callback'));
    }

    /** @param array<string,mixed> $required */
    private static function missing(array $required): array
    {
        return array_keys(array_filter($required, fn ($value) => $value === null || $value === ''));
    }
}
