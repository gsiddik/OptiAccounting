<?php

namespace App\Domain\Integration\Services;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\IntegrationConnection as Connection;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use App\Support\IdentityMode;

/**
 * Authoritative tenant pairing of a feed (ecosystem audit A19), consumer side. A credential that is valid is not enough: the source tenant
 * and this organization must be the SAME OptiNexus tenant, and the source says which one it verified (`pairing.nexus_tenant_id` of every
 * page, see OptiFleet docs/integration/ACCOUNTING_FEED.md). OptiEntry compares it with the OptiNexus tenant its own organization is linked
 * to, which comes from OptiNexus (sign-in / provisioning), never from the page, the connection form or any other request data.
 *
 *   required   `OPTIENTRY_FEED_PAIRING=required`, or `auto` (the default) while OptiNexus is the identity authority: an organization that is
 *              not linked to OptiNexus, a page without `pairing`, another OptiNexus tenant or another producer / consumer application is
 *              refused (FeedException REFUSED) and nothing from that page is stored.
 *   off        `OPTIENTRY_FEED_PAIRING=off`, or `auto` in a standalone installation (no OptiNexus to pair with).
 */
final class FeedPairing
{
    public static function required(): bool
    {
        return match ((string) config('optientry.integration.feed_pairing', 'auto')) {
            'required' => true,
            'off' => false,
            default => IdentityMode::current() === IdentityMode::OPTINEXUS,
        };
    }

    /** The OptiNexus tenant of the connection's organization, or a refusal when it has none. */
    public static function expectedTenant(Connection $connection): string
    {
        $linked = Tenant::query()->whereKey($connection->tenant_id)->value('optinexus_tenant_id');
        if (! is_string($linked) || $linked === '') {
            throw FeedException::refused('This organization is not linked to OptiNexus, so the feed cannot be paired with it.', 'FEED_PAIRING_NOT_LINKED');
        }

        return $linked;
    }

    /**
     * Checks the `pairing` block of a page; returns the OptiNexus tenant it was verified for, or null when pairing is not required.
     *
     * @param  array<string,mixed>  $page
     */
    public static function verify(Connection $connection, array $page): ?string
    {
        if (! self::required()) {
            return null;
        }
        $expected = self::expectedTenant($connection);

        $pairing = $page['pairing'] ?? null;
        $stated = is_array($pairing) && is_string($pairing['nexus_tenant_id'] ?? null) ? strtolower($pairing['nexus_tenant_id']) : null;
        if ($stated === null) {
            throw FeedException::refused('The source did not state which OptiNexus tenant it verified, so the feed cannot be trusted for this organization.', 'FEED_PAIRING_MISSING');
        }
        if (! hash_equals(strtolower($expected), $stated)) {
            throw FeedException::refused('The source served another OptiNexus tenant than the one this organization belongs to.', 'FEED_PAIRING_MISMATCH');
        }
        $producer = $pairing['producer_application'] ?? null;
        $consumer = $pairing['consumer_application'] ?? null;
        if (($producer !== null && $producer !== $connection->source_system) || ($consumer !== null && $consumer !== OptinexusSettings::applicationCode())) {
            throw FeedException::refused('The feed was verified for another pair of applications than this connection.', 'FEED_PAIRING_MISMATCH');
        }

        return $expected;
    }
}
