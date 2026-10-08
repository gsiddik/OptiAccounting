<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\AccessControl\Models\DataScope;
use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Entitlement\Services\CapacityException;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\TenantService;
use App\Domain\Integration\Services\OutboxPublisher;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns verified OptiNexus claims into local tenant, user and membership rows (docs/architecture/OPTINEXUS_ADAPTER.md §3).
 * OptiNexus decides who may enter and what they may do; this class only keeps the local projection. The tenant is
 * taken from the verified `tenant_id` claim, never from a request parameter, and nothing is created from a claim
 * that the service account could not confirm.
 */
class AccountLinker
{
    public function __construct(
        private readonly NexusPermissionSource $permissions,
        private readonly EntitlementProjector $projector,
        private readonly TenantService $tenants,
        private readonly CapacityService $capacity,
        private readonly AccessCache $cache,
        private readonly AuditService $audit,
        private readonly OutboxPublisher $outbox,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  array<string,mixed>  $claims  verified id_token claims
     * @return array{0:User,1:Tenant,2:TenantUser}
     *
     * @throws SsoException
     * @throws IdentityProviderUnavailable
     */
    public function link(array $claims): array
    {
        $application = OptinexusSettings::applicationCode();
        if (! in_array($application, array_column((array) ($claims['apps'] ?? []), 'code'), true)) {
            throw new SsoException('access_denied');
        }

        $tenant = $this->tenant($claims);
        $this->projector->syncIfStale($tenant);
        $tenant->refresh();
        if (! $tenant->isActive()) {
            throw new SsoException('tenant_inactive');
        }

        // What OptiNexus lets this person do here. A person with no permission has nothing to do in the app.
        $pulled = $this->permissions->pull((string) $claims['tenant_id'], (string) $claims['sub']);
        if ($pulled['permissions'] === []) {
            throw new SsoException('no_permissions');
        }

        [$user, $membership] = DB::transaction(function () use ($claims, $tenant, $pulled) {
            $user = $this->user($claims);
            $membership = $this->membership($user, $tenant, $pulled['scope']);

            $user->forceFill(['last_login_at' => now()])->save();
            $this->context->setUser($user);
            $this->audit->record('auth.sso_login', 'user', $user->id, null, ['optinexus_subject' => $claims['sub']], $tenant->id);
            $this->outbox->audit('sso.login', $tenant->id, ['resource_type' => 'user', 'resource_id' => (string) $claims['sub'], 'actor_identity' => (string) $claims['sub']]);

            return [$user, $membership];
        });

        $this->permissions->prime($tenant->id, $membership->id, $pulled['permissions']);

        return [$user, $tenant, $membership];
    }

    /** @throws SsoException */
    private function tenant(array $claims): Tenant
    {
        $tenant = Tenant::query()->where('optinexus_tenant_id', $claims['tenant_id'])->first();

        return $tenant ?? $this->provision($claims);
    }

    private function provision(array $claims): Tenant
    {
        if (! config('optiaccounting.optinexus.provision_tenants')) {
            throw new SsoException('tenant_not_linked');
        }

        $nexusTenantId = (string) $claims['tenant_id'];
        $context = $this->projector->fetch($nexusTenantId);
        if (! $context
            || ($context['tenant_status'] ?? null) !== 'ACTIVE'
            || ! in_array(OptinexusSettings::applicationCode(), (array) ($context['applications_enabled'] ?? []), true)) {
            throw new SsoException('tenant_not_entitled');
        }

        try {
            return DB::transaction(function () use ($claims, $nexusTenantId) {
                $tenant = $this->tenants->create([
                    'code' => $this->freeCode((string) ($claims['tenant_code'] ?? '')),
                    'name' => Str::limit((string) ($claims['tenant_name'] ?? $claims['tenant_code'] ?? 'Organization'), 250, ''),
                ]);
                DB::table('tenants')->where('id', $tenant->id)->update(['optinexus_tenant_id' => $nexusTenantId]);
                $tenant = $this->tenants->transition($tenant->refresh(), Tenant::ACTIVE, 'Provisioned from OptiNexus');

                $this->audit->record('tenant.linked', 'tenant', $tenant->id, null, ['optinexus_tenant_id' => $nexusTenantId, 'code' => $tenant->code], $tenant->id);
                $this->outbox->event('tenant.linked', $tenant->id, ['optinexus_tenant_id' => $nexusTenantId, 'code' => $tenant->code]);

                return $tenant;
            });
        } catch (UniqueConstraintViolationException) {
            // Two first sign-ins raced; the other one won.
            return Tenant::query()->where('optinexus_tenant_id', $nexusTenantId)->firstOrFail();
        }
    }

    private function freeCode(string $preferred): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($preferred)), '-');
        $slug = substr($slug, 0, 40);
        if (strlen($slug) < 3) {
            $slug = 'org-'.Str::lower(Str::random(6));
        }
        $code = $slug;
        while (Tenant::query()->where('code', $code)->exists()) {
            $code = $slug.'-'.Str::lower(Str::random(4));
        }

        return $code;
    }

    /** @throws SsoException */
    private function user(array $claims): User
    {
        $subject = (string) $claims['sub'];
        $user = User::query()->where('optinexus_subject', $subject)->first();

        if (! $user) {
            $email = strtolower(trim((string) ($claims['email'] ?? '')));
            if ($email === '') {
                throw new SsoException('email_required');
            }

            $existing = User::query()->whereRaw('lower(email) = ?', [$email])->first();
            if ($existing) {
                // Same e-mail: only a verified one may claim an unlinked, non-operator account; never re-bind.
                if (($claims['email_verified'] ?? false) !== true) {
                    throw new SsoException('email_not_verified');
                }
                if ($existing->optinexus_subject !== null || DB::table('platform_role_assignments')->where('user_id', $existing->id)->exists()) {
                    throw new SsoException('account_conflict');
                }
                $existing->forceFill(['optinexus_subject' => $subject])->save();
                $user = $existing;
            } else {
                $user = new User(['name' => Str::limit((string) ($claims['name'] ?? $email), 250, ''), 'email' => $email]);
                $user->status = User::ACTIVE;
                $user->optinexus_subject = $subject; // password stays null: this identity signs in at OptiNexus only
                $user->save();
            }
        }

        // OptiNexus has just let this person in: undo exactly what an earlier OptiNexus event switched off.
        if ($user->status === User::INACTIVE && $user->optinexus_deactivated_at !== null) {
            $user->forceFill(['status' => User::ACTIVE, 'optinexus_deactivated_at' => null])->save();
        }
        if (! $user->isActive()) {
            throw new SsoException('user_inactive');
        }

        return $user;
    }

    /** @throws SsoException */
    private function membership(User $user, Tenant $tenant, ?string $grantedScope): TenantUser
    {
        return $this->context->runAs($tenant->id, function () use ($user, $tenant, $grantedScope) {
            $membership = TenantUser::query()->where('user_id', $user->id)->first();

            if (! $membership) {
                try {
                    $this->capacity->reserve($tenant->id, CapacityService::USER_LIMIT);
                } catch (CapacityException) {
                    throw new SsoException('capacity_exceeded');
                }

                $membership = new TenantUser;
                $membership->tenant_id = $tenant->id;
                $membership->user_id = $user->id;
                $membership->status = TenantUser::ACTIVE;
                $membership->joined_at = now();
                $membership->save();

                // Data scope stays local. Start from what OptiNexus granted; a local administrator may narrow it.
                $scope = new DataScope;
                $scope->tenant_id = $tenant->id;
                $scope->tenant_user_id = $membership->id;
                $scope->scope_type = $grantedScope === 'OWN' ? DataScope::OWN : DataScope::TENANT;
                $scope->save();

                $this->cache->touchTenant($tenant->id);
                $this->audit->record('membership.provisioned', 'tenant_user', $membership->id, null, ['user_id' => $user->id, 'scope' => $scope->scope_type], $tenant->id);
                $this->outbox->event('membership.provisioned', $tenant->id, ['user_subject' => $user->optinexus_subject, 'membership_id' => $membership->id]);

                return $membership;
            }

            if ($membership->status === TenantUser::INACTIVE && $membership->optinexus_deactivated_at !== null) {
                $membership->forceFill(['status' => TenantUser::ACTIVE, 'optinexus_deactivated_at' => null])->save();
                $this->cache->touchTenant($tenant->id);
                $this->audit->record('membership.reactivated', 'tenant_user', $membership->id, ['status' => TenantUser::INACTIVE], ['status' => TenantUser::ACTIVE], $tenant->id);
            } elseif ($membership->status === TenantUser::INVITED) {
                $membership->forceFill(['status' => TenantUser::ACTIVE, 'joined_at' => now()])->save();
                $this->cache->touchTenant($tenant->id);
            }

            if (! $membership->isActive()) {
                throw new SsoException('membership_inactive');
            }

            return $membership;
        });
    }
}
