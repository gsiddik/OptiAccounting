<?php

namespace Tests\Support;

use App\Domain\AccessControl\Models\DataScope;
use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\Entitlement\Models\Subscription;
use App\Domain\Entitlement\Services\SubscriptionService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\TenantService;
use App\Domain\ProductCatalog\Models\Bundle;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\ProductCatalog\Services\BundleService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

trait Fixtures
{
    protected const PASSWORD = 'Str0ng!Passw0rd#1';

    protected function bundleAll(): Bundle
    {
        return Bundle::query()->where('code', 'ALL')->first()
            ?? app(BundleService::class)->create(['code' => 'ALL', 'name' => 'All modules'], Module::query()->pluck('code')->all());
    }

    /** An ACTIVE tenant; with a bundle subscription (all modules) unless $subscribed is false. */
    protected function tenant(string $code = 'alpha', bool $subscribed = true, string $timezone = 'Asia/Jakarta'): Tenant
    {
        $tenant = app(TenantService::class)->create(['code' => $code, 'name' => ucfirst($code).' Corp', 'timezone' => $timezone]);
        $tenant = app(TenantService::class)->transition($tenant, Tenant::ACTIVE, 'test setup');

        if ($subscribed) {
            app(SubscriptionService::class)->create($tenant, $this->bundleAll(), $tenant->businessDate(), null, Subscription::ACTIVE);
        }

        return $tenant;
    }

    /**
     * An ACTIVE member of $tenant. $permissions null => holds every tenant permission through a custom role
     * (never the seeded name); otherwise exactly those codes.
     *
     * @param  list<string>|null  $permissions
     * @return array{0:User,1:TenantUser}
     */
    protected function member(Tenant $tenant, ?array $permissions = null, ?string $email = null, string $scope = DataScope::TENANT, ?User $user = null): array
    {
        $user ??= User::query()->forceCreate([
            'name' => 'User '.substr(uniqid(), -5), 'email' => $email ?? uniqid('u').'@example.test',
            'password' => bcrypt(self::PASSWORD), 'status' => 'ACTIVE',
        ]);

        return app(TenantContext::class)->runAs($tenant->id, function () use ($tenant, $permissions, $user, $scope) {
            $membership = new TenantUser;
            $membership->tenant_id = $tenant->id;
            $membership->user_id = $user->id;
            $membership->status = TenantUser::ACTIVE;
            $membership->joined_at = now();
            $membership->save();

            $codes = $permissions ?? Permission::query()->where('scope', 'tenant')->pluck('code')->all();
            $role = Role::query()->forceCreate(['tenant_id' => $tenant->id, 'scope' => 'tenant', 'name' => 'Custom '.uniqid(), 'is_system' => false]);
            $role->permissions()->sync(Permission::query()->where('scope', 'tenant')->whereIn('code', $codes)->pluck('id')
                ->mapWithKeys(fn ($id) => [$id => ['scope' => 'tenant']])->all());
            $membership->roles()->attach($role->id, ['tenant_id' => $tenant->id]);

            $row = new DataScope;
            $row->tenant_id = $tenant->id;
            $row->tenant_user_id = $membership->id;
            $row->scope_type = $scope;
            $row->save();

            return [$user, $membership];
        });
    }

    /** @param list<string>|null $permissions null => every platform permission */
    protected function platformUser(?array $permissions = null): User
    {
        $user = User::query()->forceCreate([
            'name' => 'Operator', 'email' => uniqid('p').'@example.test', 'password' => bcrypt(self::PASSWORD), 'status' => 'ACTIVE',
        ]);
        $codes = $permissions ?? Permission::query()->where('scope', 'platform')->pluck('code')->all();
        $role = Role::query()->forceCreate(['scope' => 'platform', 'name' => 'Custom '.uniqid(), 'is_system' => false]);
        $role->permissions()->sync(Permission::query()->where('scope', 'platform')->whereIn('code', $codes)->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => ['scope' => 'platform']])->all());
        $user->platformRoles()->attach($role->id);

        return $user;
    }

    protected function tenantToken(User $user, Tenant $tenant): string
    {
        return $user->createToken('test', ['tenant:'.$tenant->id])->plainTextToken;
    }

    protected function platformToken(User $user): string
    {
        return $user->createToken('test', ['platform'])->plainTextToken;
    }

    protected function as(string $token): static
    {
        // The test application outlives a request: drop the cached guard user and the request-scoped tenant context.
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();
        // A real request builds its controller (and the request-scoped services injected into it) anew;
        // the router would otherwise keep the first request's instances for the rest of the test.
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }
        $this->flushHeaders();

        return $this->withToken($token);
    }

    protected function asMember(Tenant $tenant, ?array $permissions = null): static
    {
        [$user] = $this->member($tenant, $permissions);

        return $this->as($this->tenantToken($user, $tenant));
    }

    protected function asPlatform(?array $permissions = null): static
    {
        return $this->as($this->platformToken($this->platformUser($permissions)));
    }

    protected function rows(string $table, array $where = []): int
    {
        return DB::table($table)->where($where)->count();
    }
}
