<?php

namespace App\Domain\AccessControl\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Tenant-aware cache for derived access data (permissions, data scopes,
 * entitlement state). Every tenant has a version counter that is bumped on any
 * role, permission, membership, scope, organization, subscription or entitlement
 * change; keys embed the version, so stale entries are never read again. The TTL
 * is a second bound for changes made outside the services (e.g. direct SQL).
 */
class AccessCache
{
    public const TTL_SECONDS = 300;

    public function tenantVersion(string $tenantId): int
    {
        return (int) Cache::get($this->versionKey($tenantId), 1);
    }

    public function touchTenant(string $tenantId): void
    {
        Cache::add($this->versionKey($tenantId), 1, now()->addDays(30));
        Cache::increment($this->versionKey($tenantId));
    }

    public function platformVersion(): int
    {
        return (int) Cache::get('platform:access_v', 1);
    }

    public function touchPlatform(): void
    {
        Cache::add('platform:access_v', 1, now()->addDays(30));
        Cache::increment('platform:access_v');
    }

    /** Remember a tenant-scoped value under a key that embeds tenant id and version. */
    public function rememberForTenant(string $tenantId, string $name, callable $compute, int $ttl = self::TTL_SECONDS): mixed
    {
        $key = "t:{$tenantId}:v{$this->tenantVersion($tenantId)}:{$name}";

        return Cache::remember($key, min($ttl, self::TTL_SECONDS), $compute);
    }

    public function rememberForPlatform(string $name, callable $compute): mixed
    {
        return Cache::remember("platform:v{$this->platformVersion()}:{$name}", self::TTL_SECONDS, $compute);
    }

    private function versionKey(string $tenantId): string
    {
        return "t:{$tenantId}:access_v";
    }
}
