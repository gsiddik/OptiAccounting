<?php

namespace Database\Seeders;

use App\Domain\AccessControl\Models\DataScope;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\AccessControl\Services\RoleService;
use App\Domain\Entitlement\Models\Subscription;
use App\Domain\Entitlement\Services\SubscriptionService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\MembershipService;
use App\Domain\Identity\Services\TenantService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Services\OrganizationService;
use App\Domain\ProductCatalog\Models\Bundle;
use App\Domain\ProductCatalog\Services\BundleService;
use App\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo data for local and showcase environments only: example bundles, platform operators,
 * four tenants in different commercial states and a user that belongs to two tenants.
 * Logins are documented in docs/DEMO.md. Idempotent: running it again changes nothing.
 * Run with: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    /** Documented demo password; override with OPTIACCOUNTING_DEMO_PASSWORD. Never use it outside demo environments. */
    public const DEFAULT_PASSWORD = 'Demo#Passw0rd2026';

    private const BUNDLES = [
        'STARTER' => ['Starter', ['ACCOUNTING_CORE', 'ACCOUNTING_CASH_BANK', 'ACCOUNTING_REPORTING'], ['USER_LIMIT' => 5, 'BRANCH_LIMIT' => 1, 'BUSINESS_UNIT_LIMIT' => 3]],
        'BUSINESS' => ['Business', ['ACCOUNTING_CORE', 'ACCOUNTING_CASH_BANK', 'ACCOUNTING_REPORTING', 'ACCOUNTING_AP', 'ACCOUNTING_AR', 'ACCOUNTING_EXPENSE', 'ACCOUNTING_TAX'], ['USER_LIMIT' => 10, 'BRANCH_LIMIT' => 3, 'BUSINESS_UNIT_LIMIT' => 10]],
        'ENTERPRISE' => ['Enterprise', null, []], // null = every module in the catalog, no capacity limits
    ];

    /** What a bookkeeper may do in the demo books: prepare and submit journals, read everything. */
    private const ACCOUNTANT_PERMISSIONS = [
        'accounting.profile.view', 'accounting.period.view', 'accounting.coa.view', 'accounting.dimension.view', 'accounting.posting_rule.view', 'accounting.account_mapping.view',
        'accounting.journal.view', 'accounting.journal.create', 'accounting.journal.update', 'accounting.journal.submit', 'accounting.opening_balance.view',
        'accounting.gl.view', 'accounting.trial_balance.view',
    ];

    private const PLATFORM_USERS = [
        ['platform.admin@demo.test', 'Admin Platform Demo', 'Platform Administrator'],
        ['platform.support@demo.test', 'Support Platform Demo', 'Platform Support (read-only)'],
    ];

    /** @var list<array<string,mixed>> */
    private const TENANTS = [
        [
            'code' => 'maju-jaya', 'name' => 'PT Maju Jaya', 'tenant_status' => 'ACTIVE', 'bundle' => 'BUSINESS', 'subscription_status' => 'ACTIVE',
            'admin' => ['Budi Santoso', 'admin@majujaya.demo.test'],
            'branches' => [['JKT', 'Kantor Pusat Jakarta'], ['SBY', 'Cabang Surabaya']],
            'units' => [['SALES', 'Penjualan', 'JKT'], ['OPS', 'Operasional Jakarta', 'JKT'], ['SBY-OPS', 'Operasional Surabaya', 'SBY']],
            'accounting' => ['akuntan@majujaya.demo.test', 'manajer@majujaya.demo.test'], // demo books (OA1)
            'roles' => [
                'Staf Keuangan' => ['organization.view', 'account.subscription.view', 'audit.view'],
                'Admin Cabang' => ['organization.view', 'organization.manage', 'access.user.view'],
                'Akuntan' => self::ACCOUNTANT_PERMISSIONS,
                'Manajer Keuangan' => [...self::ACCOUNTANT_PERMISSIONS, 'accounting.journal.approve', 'accounting.journal.post', 'accounting.journal.reverse', 'accounting.period.manage',
                    'accounting.period.close', 'accounting.opening_balance.manage', 'accounting.opening_balance.post', 'accounting.report.export', 'accounting.coa.manage',
                    'accounting.posting_rule.manage', 'accounting.account_mapping.manage', 'accounting.dimension.manage', 'accounting.profile.manage'],
            ],
            'users' => [
                ['Sari Keuangan', 'keuangan@majujaya.demo.test', 'Staf Keuangan', ['BRANCH', 'JKT']],
                ['Rudi Surabaya', 'cabang.sby@majujaya.demo.test', 'Admin Cabang', ['BRANCH', 'SBY']],
                ['Vina Viewer', 'viewer@majujaya.demo.test', 'Tenant Viewer', ['TENANT', null]],
                ['Andi Akuntan', 'akuntan@majujaya.demo.test', 'Akuntan', ['TENANT', null]],
                ['Maya Manajer Keuangan', 'manajer@majujaya.demo.test', 'Manajer Keuangan', ['TENANT', null]],
            ],
        ],
        [
            'code' => 'sinar-abadi', 'name' => 'CV Sinar Abadi', 'tenant_status' => 'ACTIVE', 'bundle' => 'STARTER', 'subscription_status' => 'ACTIVE',
            'admin' => ['Dewi Lestari', 'admin@sinarabadi.demo.test'],
            'branches' => [['PUSAT', 'Kantor Pusat']], 'units' => [], 'roles' => [], 'users' => [],
        ],
        [
            // Subscription overdue: every module is READ_ONLY (reads allowed, mutations refused).
            'code' => 'tunggakan', 'name' => 'PT Tunggakan Demo', 'tenant_status' => 'ACTIVE', 'bundle' => 'BUSINESS', 'subscription_status' => 'PAST_DUE',
            'admin' => ['Tono Tunggakan', 'admin@tunggakan.demo.test'],
            'branches' => [['PUSAT', 'Kantor Pusat']], 'units' => [], 'roles' => [], 'users' => [],
        ],
        [
            // Suspended tenant: nobody can enter it.
            'code' => 'ditangguhkan', 'name' => 'CV Ditangguhkan Demo', 'tenant_status' => 'SUSPENDED', 'bundle' => 'STARTER', 'subscription_status' => 'ACTIVE',
            'admin' => ['Sinta Tangguh', 'admin@ditangguhkan.demo.test'],
            'branches' => [['PUSAT', 'Kantor Pusat']], 'units' => [], 'roles' => [], 'users' => [],
        ],
    ];

    /** One account that belongs to two tenants (tenant switching). */
    private const MULTI_TENANT_USER = ['Multi Tenant Demo', 'multi@demo.test'];

    private string $password;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoSeeder must not run in production.');
        }

        $this->password = (string) (config('optiaccounting.demo_password') ?: self::DEFAULT_PASSWORD);

        $this->call([ModuleCatalogSeeder::class, AccessControlSeeder::class, AccountingCatalogSeeder::class]);

        DB::transaction(function () {
            $this->bundles();
            $this->platformUsers();
            foreach (self::TENANTS as $definition) {
                $this->tenant($definition);
            }
            $this->multiTenantUser();
        });
    }

    private function bundles(): void
    {
        foreach (self::BUNDLES as $code => [$name, $modules, $capacities]) {
            if (Bundle::query()->where('code', $code)->exists()) {
                continue;
            }
            $modules ??= DB::table('modules')->pluck('code')->all();
            app(BundleService::class)->create(['code' => $code, 'name' => $name, 'description' => 'Demo bundle'], $modules, $capacities);
        }
    }

    private function platformUsers(): void
    {
        foreach (self::PLATFORM_USERS as [$email, $name, $roleName]) {
            $user = $this->user($email, $name);
            $role = Role::query()->platform()->where('name', $roleName)->firstOrFail();
            $user->platformRoles()->syncWithoutDetaching([$role->id]);
        }
        app(AccessCache::class)->touchPlatform();
    }

    /** @param array<string,mixed> $d */
    private function tenant(array $d): void
    {
        if (Tenant::query()->where('code', $d['code'])->exists()) {
            return;
        }

        $tenants = app(TenantService::class);
        $context = app(TenantContext::class);

        $tenant = $tenants->create(
            ['code' => $d['code'], 'name' => $d['name'], 'timezone' => 'Asia/Jakarta', 'default_currency' => 'IDR', 'default_locale' => 'id'],
            ['name' => $d['admin'][0], 'email' => $d['admin'][1], 'password' => $this->password],
        );
        $tenant = $tenants->transition($tenant, Tenant::ACTIVE, 'Demo setup');

        $subscription = app(SubscriptionService::class)->create(
            $tenant, Bundle::query()->where('code', $d['bundle'])->firstOrFail(), $tenant->businessDate(),
            date('Y-m-d', strtotime($tenant->businessDate().' +1 year')), Subscription::ACTIVE,
        );
        if ($d['subscription_status'] !== Subscription::ACTIVE) {
            app(SubscriptionService::class)->transition($subscription, $d['subscription_status'], 'Demo: '.strtolower($d['subscription_status']));
        }

        $context->runAs($tenant->id, function () use ($tenant, $d) {
            $org = app(OrganizationService::class);
            foreach ($d['branches'] as [$code, $name]) {
                $org->createBranch($tenant->id, ['code' => $code, 'name' => $name]);
            }
            foreach ($d['units'] as [$code, $name, $branchCode]) {
                $org->createBusinessUnit($tenant->id, ['code' => $code, 'name' => $name], Branch::query()->where('code', $branchCode)->value('id'));
            }

            $admin = User::query()->whereRaw('lower(email) = ?', [strtolower($d['admin'][1])])->firstOrFail();
            foreach ($d['roles'] as $roleName => $permissions) {
                app(RoleService::class)->create(Role::TENANT, $tenant->id, $admin, $roleName, 'Demo role', $permissions);
            }
            foreach ($d['users'] as [$name, $email, $roleName, [$scopeType, $branchCode]]) {
                $this->member($tenant->id, $admin, $name, $email, $roleName, $scopeType, $branchCode);
            }
            if (isset($d['accounting'])) {
                [$accountant, $manager] = array_map(fn ($email) => User::query()->whereRaw('lower(email) = ?', [$email])->firstOrFail(), $d['accounting']);
                app(DemoAccountingSeeder::class)->seed($tenant, $accountant, $manager);
            }
        });

        if ($d['tenant_status'] !== Tenant::ACTIVE) {
            $tenants->transition($tenant, $d['tenant_status'], 'Demo: '.strtolower($d['tenant_status']));
        }
    }

    private function member(string $tenantId, User $actor, string $name, string $email, string $roleName, string $scopeType, ?string $branchCode): void
    {
        $roleId = Role::query()->forTenant($tenantId)->where('name', $roleName)->value('id');
        $scope = ['scope_type' => $scopeType];
        if ($scopeType === DataScope::BRANCH) {
            $scope['branch_id'] = Branch::query()->where('code', $branchCode)->value('id');
        }

        app(MembershipService::class)->add($tenantId, $actor, ['name' => $name, 'email' => $email, 'password' => $this->password], [$roleId], [$scope]);
    }

    /** multi@demo.test: Tenant Viewer in PT Maju Jaya, Tenant Administrator in CV Sinar Abadi. */
    private function multiTenantUser(): void
    {
        [$name, $email] = self::MULTI_TENANT_USER;

        foreach (['maju-jaya' => 'Tenant Viewer', 'sinar-abadi' => 'Tenant Administrator'] as $code => $roleName) {
            $tenant = Tenant::query()->where('code', $code)->firstOrFail();
            $user = User::query()->whereRaw('lower(email) = ?', [strtolower($email)])->first();

            app(TenantContext::class)->runAs($tenant->id, function () use ($tenant, $user, $name, $email, $roleName, $code) {
                if ($user && TenantUser::query()->where('user_id', $user->id)->exists()) {
                    return;
                }

                $adminEmail = collect(self::TENANTS)->firstWhere('code', $code)['admin'][1];
                $admin = User::query()->whereRaw('lower(email) = ?', [strtolower($adminEmail)])->firstOrFail();
                $roleId = Role::query()->forTenant($tenant->id)->where('name', $roleName)->value('id');

                $membership = app(MembershipService::class)->add($tenant->id, $admin, ['name' => $name, 'email' => $email, 'password' => $this->password], [$roleId], [['scope_type' => 'TENANT']]);
                if ($membership->status === TenantUser::INVITED) {
                    app(MembershipService::class)->acceptInvitation(User::query()->findOrFail($membership->user_id), $tenant->id);
                }
            });
        }
    }

    private function user(string $email, string $name): User
    {
        $user = User::query()->whereRaw('lower(email) = ?', [strtolower($email)])->first();
        if ($user) {
            return $user;
        }

        $user = new User(['name' => $name, 'email' => $email]);
        $user->password = $this->password; // hashed by the cast
        $user->status = User::ACTIVE;
        $user->save();

        return $user;
    }
}
