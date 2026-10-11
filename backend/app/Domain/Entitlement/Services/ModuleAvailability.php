<?php

namespace App\Domain\Entitlement\Services;

use App\Domain\Identity\Models\Tenant;
use App\Support\TenantContext;

/**
 * "Is this module usable for the current tenant right now?" for services that must adapt to the subscribed modules (module-aware close
 * readiness, reconciliation types, comparative reports). It reads the same EntitlementSnapshot the central access resolver uses, so it can
 * never disagree with the route gate; it is NOT an authorization decision (permission, data scope and the READ_ONLY rule for a mutation stay
 * with EffectiveAccess).
 */
class ModuleAvailability
{
    public function __construct(private readonly EntitlementSnapshot $snapshots, private readonly TenantContext $context) {}

    /** NONE | READ_ONLY | FULL: the weaker of the subscription and the module entitlement. */
    public function mode(string $moduleCode): string
    {
        return EntitlementSnapshot::moduleMode($this->snapshot(), $moduleCode);
    }

    /** The module is entitled (FULL or READ_ONLY): its data exists and may be read. */
    public function active(string $moduleCode): bool
    {
        return $this->mode($moduleCode) !== EntitlementSnapshot::NONE;
    }

    public function writable(string $moduleCode): bool
    {
        return $this->mode($moduleCode) === EntitlementSnapshot::FULL;
    }

    public function featureEnabled(string $featureCode): bool
    {
        $feature = $this->snapshot()['features'][$featureCode] ?? null;

        return $feature !== null && ($feature['enabled'] ?? false) && $this->active((string) ($feature['module'] ?? ''));
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        $tenantId = $this->context->tenantId() ?? throw new \LogicException('ModuleAvailability requires a tenant context.');

        return $this->snapshots->forTenant(Tenant::query()->findOrFail($tenantId));
    }
}
