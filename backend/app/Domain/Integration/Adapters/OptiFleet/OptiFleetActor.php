<?php

namespace App\Domain\Integration\Adapters\OptiFleet;

use App\Domain\AccessControl\Models\DataScope;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The identity that prepares and posts what the OptiFleet adapter books. Every document service acts for a user (creator, submitter, poster,
 * audit actor, data scope), and OptiEntry has no automatic poster to borrow: depreciation and year end run as the person who started them.
 * So the integration gets a user of its own in each tenant, created the first time it is needed:
 *
 *   - no password: it can never sign in or obtain a token, and nothing but this class hands it out;
 *   - an ACTIVE membership without any role: it holds no permission, so no permission check passes for it, in particular not the exception
 *     for posting into a soft-closed period. Ledger access comes from the services, which gate on module entitlement;
 *   - TENANT data scope, so it can see the documents it prepares and use the tenant's cash/bank account.
 *
 * Suspending the user or its membership switches the integration's booking off (events then wait in NEEDS_REVIEW). It takes no seat in the
 * tenant's user limit (CapacityService leaves out `*.optientry.invalid` addresses).
 */
class OptiFleetActor
{
    public function __construct(private readonly TenantContext $context, private readonly AuditService $audit) {}

    /** The integration user of the tenant in the context. @throws DomainException when a person disabled it or its address belongs to a real account */
    public function resolve(): User
    {
        $tenant = Tenant::query()->findOrFail($this->context->tenantId());
        $email = "optifleet.integration@{$tenant->code}.optientry.invalid";
        $user = User::query()->whereRaw('lower(email) = ?', [$email])->first() ?? $this->create($tenant, $email);
        $membership = TenantUser::query()->where('user_id', $user->id)->first();

        if ($user->password !== null || $membership === null) {
            throw new DomainException('The integration user of this organization is not a service identity.', 'INTEGRATION_ACTOR_INVALID', 409);
        }
        if (! $user->isActive() || ! $membership->isActive()) {
            throw new DomainException('The integration user of this organization is disabled.', 'INTEGRATION_ACTOR_DISABLED', 409);
        }

        return $user;
    }

    private function create(Tenant $tenant, string $email): User
    {
        try {
            $user = DB::transaction(function () use ($tenant, $email) {
                $user = User::query()->forceCreate(['name' => 'Integrasi OptiFleet', 'email' => $email, 'status' => User::ACTIVE]);

                $membership = new TenantUser;
                $membership->tenant_id = $tenant->id;
                $membership->user_id = $user->id;
                $membership->status = TenantUser::ACTIVE;
                $membership->joined_at = now();
                $membership->save();

                $scope = new DataScope;
                $scope->tenant_id = $tenant->id;
                $scope->tenant_user_id = $membership->id;
                $scope->scope_type = DataScope::TENANT;
                $scope->save();

                $this->audit->record('accounting.integration.actor.created', 'user', $user->id, null, ['email' => $email, 'source_system' => OptiFleetAdapter::SOURCE]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) { // two workers created it at once: the other one's row is the integration user
            return User::query()->whereRaw('lower(email) = ?', [$email])->firstOrFail();
        }

        return $user;
    }
}
