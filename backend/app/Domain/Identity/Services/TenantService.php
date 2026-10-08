<?php

namespace App\Domain\Identity\Services;

use App\Domain\AccessControl\Models\DataScope;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\AccessControl\Services\SystemRoleSynchronizer;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

class TenantService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly SystemRoleSynchronizer $roles,
        private readonly AccessCache $cache,
        private readonly TenantContext $context,
    ) {}

    /**
     * Create a tenant (status DRAFT) with its system roles and, optionally, the first
     * administrator. The initial password is supplied by the platform operator and is
     * never logged or audited.
     *
     * @param  array{name:string,email:string,password?:string}|null  $admin
     */
    public function create(array $data, ?array $admin = null): Tenant
    {
        return DB::transaction(function () use ($data, $admin) {
            $tenant = new Tenant($data);
            $tenant->status = Tenant::DRAFT;
            $tenant->save();

            $this->roles->syncTenantRoles($tenant);

            if ($admin) {
                $this->addInitialAdministrator($tenant, $admin);
            }

            $this->audit->record('tenant.created', 'tenant', $tenant->id, null, $tenant->only(['code', 'name', 'status']), $tenant->id);

            return $tenant;
        });
    }

    public function update(Tenant $tenant, array $data): Tenant
    {
        return DB::transaction(function () use ($tenant, $data) {
            $before = $tenant->only(array_keys($data));
            $tenant->fill($data)->save();
            $this->cache->touchTenant($tenant->id);
            $this->audit->record('tenant.updated', 'tenant', $tenant->id, $before, $tenant->only(array_keys($data)), $tenant->id);

            return $tenant;
        });
    }

    public function transition(Tenant $tenant, string $to, string $reason): Tenant
    {
        return DB::transaction(function () use ($tenant, $to, $reason) {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $from = $tenant->status;

            if (! in_array($to, Tenant::TRANSITIONS[$from] ?? [], true)) {
                throw new DomainException("A {$from} tenant cannot become {$to}.", 'INVALID_TRANSITION');
            }

            $tenant->status = $to;
            $tenant->save();

            if ($to !== Tenant::ACTIVE) {
                // End any open tenant sessions; per-request checks would deny them anyway.
                DB::table('personal_access_tokens')->where('abilities', 'like', '%"tenant:'.$tenant->id.'"%')->delete();
            }

            $this->cache->touchTenant($tenant->id);
            $this->audit->record('tenant.status_changed', 'tenant', $tenant->id, ['status' => $from], ['status' => $to, 'reason' => $reason], $tenant->id);

            return $tenant;
        });
    }

    private function addInitialAdministrator(Tenant $tenant, array $admin): void
    {
        $user = User::query()->whereRaw('lower(email) = ?', [strtolower($admin['email'])])->first();

        if (! $user) {
            if (empty($admin['password'])) {
                throw new DomainException('A password is required for a new administrator.', 'PASSWORD_REQUIRED');
            }
            $user = new User(['name' => $admin['name'], 'email' => $admin['email']]);
            $user->password = $admin['password']; // hashed by the cast; never mass-assigned
            $user->status = User::ACTIVE;
            $user->save();
        }

        $this->context->runAs($tenant->id, function () use ($tenant, $user) {
            $membership = new TenantUser;
            $membership->tenant_id = $tenant->id;
            $membership->user_id = $user->id;
            $membership->status = TenantUser::ACTIVE;
            $membership->joined_at = now();
            $membership->save();

            $role = Role::query()->forTenant($tenant->id)->where('is_system', true)->where('name', 'Tenant Administrator')->firstOrFail();
            $membership->roles()->attach($role->id, ['tenant_id' => $tenant->id]);

            $scope = new DataScope;
            $scope->tenant_id = $tenant->id;
            $scope->tenant_user_id = $membership->id;
            $scope->scope_type = DataScope::TENANT;
            $scope->save();
        });
    }
}
