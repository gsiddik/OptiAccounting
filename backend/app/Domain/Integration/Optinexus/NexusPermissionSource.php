<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\AccessControl\Services\PermissionSource;
use Illuminate\Support\Facades\DB;

/**
 * Permissions in `optinexus` mode: OptiNexus decides (role composition and assignment live there). It has no
 * "list effective permissions" call for service accounts, so every tenant permission of the local catalog is asked
 * with POST /authorization/check (key `<application>.<code>`), concurrently, and the allowed set is cached per
 * tenant + member for at most 5 minutes under the tenant access version. A failed call caches nothing and raises
 * IdentityProviderUnavailable: access fails closed, a stale answer is never used (docs/architecture/OPTINEXUS_ADAPTER.md §4).
 */
class NexusPermissionSource implements PermissionSource
{
    public function __construct(
        private readonly NexusApi $api,
        private readonly AccessCache $cache,
    ) {}

    public function tenantPermissions(string $tenantId, string $tenantUserId): array
    {
        return $this->cache->rememberForTenant(
            $tenantId,
            "nexus-perm:{$tenantUserId}",
            function () use ($tenantId, $tenantUserId) {
                $link = DB::table('tenant_users as tu')
                    ->join('users as u', 'u.id', '=', 'tu.user_id')
                    ->join('tenants as t', 't.id', '=', 'tu.tenant_id')
                    ->where('tu.id', $tenantUserId)->where('tu.tenant_id', $tenantId)
                    ->first(['u.optinexus_subject as subject', 't.optinexus_tenant_id as nexus_tenant']);

                // Not linked to OptiNexus: nothing can be granted.
                if (! $link || ! $link->subject || ! $link->nexus_tenant) {
                    return [];
                }

                return $this->pull((string) $link->nexus_tenant, (string) $link->subject)['permissions'];
            },
            (int) config('optientry.optinexus.permission_ttl_seconds'),
        );
    }

    /** Stores an answer that was just pulled (sign-in) so the first requests of the session do not ask again. */
    public function prime(string $tenantId, string $tenantUserId, array $permissions): void
    {
        $ttl = (int) config('optientry.optinexus.permission_ttl_seconds');
        $this->cache->rememberForTenant($tenantId, "nexus-perm:{$tenantUserId}", fn () => array_values($permissions), $ttl);
    }

    /**
     * Asks OptiNexus about every tenant permission for one user in one tenant.
     *
     * @return array{permissions:list<string>,scope:?string} `scope` is the widest scope OptiNexus granted
     *                                                       (GLOBAL > CUSTOMER > TENANT > APPLICATION > OWN), null when nothing was granted
     */
    public function pull(string $nexusTenantId, string $subject): array
    {
        $application = OptinexusSettings::applicationCode();
        $bodies = [];
        foreach (DB::table('permissions')->where('scope', 'tenant')->pluck('code') as $code) {
            $bodies[$code] = [
                'user_id' => $subject,
                'tenant_id' => $nexusTenantId,
                'application_code' => $application,
                'permission' => "{$application}.{$code}",
            ];
        }

        $granted = [];
        $rank = ['OWN' => 1, 'APPLICATION' => 2, 'TENANT' => 3, 'CUSTOMER' => 4, 'GLOBAL' => 5];
        $widest = null;

        foreach ($this->api->pool('/authorization/check', $bodies) as $code => $response) {
            $data = $this->api->data($response);
            if (($data['allowed'] ?? false) === true) {
                $granted[] = (string) $code;
                $scope = $data['scope'] ?? null;
                if (is_string($scope) && ($rank[$scope] ?? 0) > ($rank[$widest] ?? 0)) {
                    $widest = $scope;
                }
            }
        }

        sort($granted);

        return ['permissions' => $granted, 'scope' => $widest];
    }
}
