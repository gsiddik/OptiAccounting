<?php

namespace App\Domain\Integration\Adapters\OptiFleet;

use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\ServicePrincipalKinds;
use App\Domain\Identity\Services\ServicePrincipalRegistry;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;

/**
 * The identity that prepares and posts what the OptiFleet adapter books. Every document service acts for a user (creator, submitter, poster,
 * audit actor, data scope), and OptiEntry has no automatic poster to borrow: depreciation and year end run as the person who started them.
 * So the integration acts as a service principal of its own in each tenant (kind OPTIFLEET_INTEGRATION). The platform operator issues it
 * (ServicePrincipalRegistry: command or platform API, never a tenant) and this class only looks it up; it never creates one:
 *
 *   - no password: it can never sign in or obtain a token, and nothing but this class hands it out;
 *   - an ACTIVE membership without any role: it holds no permission, so no permission check passes for it, in particular not the exception
 *     for posting into a soft-closed period. Ledger access comes from the services, which gate on module entitlement;
 *   - TENANT data scope, so it can see the documents it prepares and use the tenant's cash/bank account.
 *
 * A tenant can switch the user off by suspending its membership (events then wait in NEEDS_REVIEW); the operator revokes it for good. It takes
 * no seat in the tenant's user limit (CapacityService leaves out live registry rows). Without a live principal the connector is not
 * available to the tenant (OptiFleetAdapter::entitlementProblems()): events are received and wait, nothing is booked.
 */
class OptiFleetActor
{
    public function __construct(private readonly TenantContext $context, private readonly ServicePrincipalRegistry $registry) {}

    /** The integration user of the tenant in the context. @throws DomainException when the operator has not issued it, or a person disabled it */
    public function resolve(): User
    {
        $tenantId = (string) $this->context->tenantId();
        $principal = $this->registry->live($tenantId, ServicePrincipalKinds::OPTIFLEET_INTEGRATION);
        if ($principal === null) {
            throw new DomainException('The integration user of this organization has not been issued by the platform operator, or was revoked.', 'INTEGRATION_ACTOR_MISSING', 409);
        }

        $user = User::query()->find($principal->user_id);
        $membership = TenantUser::query()->where('user_id', $principal->user_id)->first();
        if ($user === null || $user->password !== null || $membership === null) {
            throw new DomainException('The integration user of this organization is not a service identity.', 'INTEGRATION_ACTOR_INVALID', 409);
        }
        if (! $user->isActive() || ! $membership->isActive()) {
            throw new DomainException('The integration user of this organization is disabled.', 'INTEGRATION_ACTOR_DISABLED', 409);
        }

        return $user;
    }

    /** Whether the operator has issued the integration user (a precondition for the connector). */
    public function issued(string $tenantId): bool
    {
        return $this->registry->hasLive($tenantId, ServicePrincipalKinds::OPTIFLEET_INTEGRATION);
    }
}
