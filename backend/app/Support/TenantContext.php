<?php

namespace App\Support;

use App\Domain\Identity\Models\User;
use Closure;

/**
 * Request-scoped tenant/user context, resolved server-side from the
 * authenticated token (never from client input). Tenant-owned Eloquent models
 * read it through TenantScope, so a query without a context returns nothing
 * (fail closed). Registered as a scoped singleton (see AppServiceProvider).
 */
class TenantContext
{
    private ?string $tenantId = null;

    private ?User $user = null;

    private bool $platform = false;

    private int $bypassDepth = 0;

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function user(): ?User
    {
        return $this->user;
    }

    public function isPlatform(): bool
    {
        return $this->platform;
    }

    public function setTenant(?string $tenantId): void
    {
        $this->tenantId = $tenantId;
    }

    public function setUser(?User $user): void
    {
        $this->user = $user;
    }

    public function setPlatform(bool $platform): void
    {
        $this->platform = $platform;
    }

    public function isBypassed(): bool
    {
        return $this->bypassDepth > 0;
    }

    /** Run $callback as the given tenant (jobs, seeders, platform operations on one tenant). */
    public function runAs(string $tenantId, Closure $callback): mixed
    {
        $previous = $this->tenantId;
        $this->tenantId = $tenantId;

        try {
            return $callback();
        } finally {
            $this->tenantId = $previous;
        }
    }

    /** Explicitly cross-tenant work (platform listings). Keep the closure small and auditable. */
    public function withoutTenantScope(Closure $callback): mixed
    {
        $this->bypassDepth++;

        try {
            return $callback();
        } finally {
            $this->bypassDepth--;
        }
    }
}
