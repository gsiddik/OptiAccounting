<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\AccessControl\Services\PermissionSource;
use Illuminate\Support\Facades\DB;

/**
 * Permissions in `optinexus` mode: OptiNexus decides (role composition and assignment live there). One request,
 * POST /authorization/effective-permissions (schema 1.0), returns everything the member holds in the tenant for this
 * application, each key with its own scope; the application is the one OptiNexus bound to our service account, so nothing
 * of another application or tenant can come back. The answer is cached per tenant + member for at most 5 minutes under the
 * tenant access version, so a grant revoked in OptiNexus reaches us within that time (sooner when a revocation event bumps
 * the version). A failed call caches nothing and raises IdentityProviderUnavailable: access fails closed, a stale answer is
 * never used and nothing is granted by wildcard or by a local fallback (docs/architecture/OPTINEXUS_ADAPTER.md §4).
 */
class NexusPermissionSource implements PermissionSource
{
    public const SCHEMA_VERSION = '1.0';

    /** Narrowest first: the scope a member's local data scope starts from is the narrowest one OptiNexus granted. */
    private const SCOPE_RANK = ['OWN' => 1, 'APPLICATION' => 2, 'TENANT' => 3, 'CUSTOMER' => 4, 'GLOBAL' => 5];

    public function __construct(
        private readonly NexusApi $api,
        private readonly AccessCache $cache,
    ) {}

    public function tenantPermissions(string $tenantId, string $tenantUserId): array
    {
        return array_keys($this->tenantGrants($tenantId, $tenantUserId));
    }

    public function tenantGrants(string $tenantId, string $tenantUserId): array
    {
        return $this->cache->rememberForTenant(
            $tenantId,
            "nexus-grants:{$tenantUserId}",
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

                return $this->pull((string) $link->nexus_tenant, (string) $link->subject)['grants'];
            },
            (int) config('optientry.optinexus.permission_ttl_seconds'),
        );
    }

    /** Stores an answer that was just pulled (sign-in) so the first requests of the session do not ask again. @param array<string,string> $grants code => scope */
    public function prime(string $tenantId, string $tenantUserId, array $grants): void
    {
        $ttl = (int) config('optientry.optinexus.permission_ttl_seconds');
        $this->cache->rememberForTenant($tenantId, "nexus-grants:{$tenantUserId}", fn () => $grants, $ttl);
    }

    /**
     * Asks OptiNexus, in one request, what one user holds in one tenant.
     *
     * Only exact permission codes of the local tenant catalog are accepted: a key outside it, a wildcard, a malformed row or a
     * scope OptiNexus does not define is dropped, and an answer about another user, tenant or application is refused.
     *
     * @return array{permissions:list<string>,grants:array<string,string>,scope:?string} `scope` is the narrowest scope granted (null when nothing was)
     *
     * @throws IdentityProviderUnavailable
     */
    public function pull(string $nexusTenantId, string $subject): array
    {
        $application = OptinexusSettings::applicationCode();

        $response = $this->api->send('POST', '/authorization/effective-permissions', [
            'user_id' => $subject,
            'tenant_id' => $nexusTenantId,
            'application_code' => $application,
        ], repeatable: true);

        $data = $this->api->data($response);

        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($data['user_id'] ?? null) !== $subject
            || ($data['tenant_id'] ?? null) !== $nexusTenantId
            || ($data['application_code'] ?? null) !== $application
            || ! is_array($data['permissions'] ?? null)) {
            throw new IdentityProviderUnavailable('Unusable answer from effective-permissions (schema or subject mismatch).');
        }

        // An explicit refusal always wins, even if a malformed/stale response also carries grants.
        if (($data['allowed'] ?? false) !== true) {
            return ['permissions' => [], 'grants' => [], 'scope' => null];
        }

        $catalog = array_flip(DB::table('permissions')->where('scope', 'tenant')->pluck('code')->all());
        $prefix = "{$application}.";
        $grants = [];

        foreach ($data['permissions'] as $row) {
            $key = is_array($row) ? ($row['permission'] ?? null) : null;
            $scope = is_array($row) ? ($row['scope'] ?? null) : null;

            if (! is_string($key) || ! is_string($scope) || ! isset(self::SCOPE_RANK[$scope]) || ! str_starts_with($key, $prefix)) {
                continue;
            }

            $code = substr($key, strlen($prefix));
            if (isset($catalog[$code])) {
                $grants[$code] = $scope;
            }
        }

        ksort($grants);

        $narrowest = null;
        foreach ($grants as $scope) {
            if ($narrowest === null || self::SCOPE_RANK[$scope] < self::SCOPE_RANK[$narrowest]) {
                $narrowest = $scope;
            }
        }

        return ['permissions' => array_keys($grants), 'grants' => $grants, 'scope' => $narrowest];
    }
}
