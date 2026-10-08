<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** Seeding and bootstrap (OA0 §33-34): production-safe, idempotent, no default credentials; demo data documented. */
class SeederAndBootstrapTest extends TestCase
{
    use Fixtures;

    private const TABLES = ['modules', 'module_dependencies', 'features', 'permissions', 'roles', 'role_permissions'];

    private function snapshot(): array
    {
        return array_combine(self::TABLES, array_map(fn ($t) => DB::table($t)->count(), self::TABLES));
    }

    public function test_the_production_seeder_is_idempotent_and_creates_no_tenants_users_or_commercial_data(): void
    {
        $first = $this->snapshot();
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame($first, $this->snapshot());
        $this->assertGreaterThan(0, $first['modules']);
        foreach (['tenants', 'users', 'tenant_users', 'subscriptions', 'bundles', 'branches', 'tenant_module_entitlements', 'personal_access_tokens'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "{$table} must stay empty");
        }
    }

    public function test_seeding_never_overwrites_catalog_edits_made_by_an_operator(): void
    {
        DB::table('modules')->where('code', 'ACCOUNTING_AP')->update(['name' => 'Hutang Usaha']);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame('Hutang Usaha', DB::table('modules')->where('code', 'ACCOUNTING_AP')->value('name'));
    }

    public function test_the_catalog_matches_the_documented_modules(): void
    {
        $documented = ['ACCOUNTING_CORE', 'ACCOUNTING_AP', 'ACCOUNTING_EXPENSE', 'ACCOUNTING_CASH_BANK', 'ACCOUNTING_AR', 'ACCOUNTING_BUDGET',
            'ACCOUNTING_FIXED_ASSET', 'ACCOUNTING_TAX', 'ACCOUNTING_MULTI_CURRENCY', 'ACCOUNTING_REPORTING', 'ACCOUNTING_INTEGRATION', 'ACCOUNTING_ANALYTICS'];

        $this->assertEqualsCanonicalizing($documented, DB::table('modules')->pluck('code')->all());
        $this->assertSame(12, DB::table('module_dependencies')->count());
    }

    public function test_bootstrap_creates_the_first_platform_administrator_who_can_sign_in(): void
    {
        putenv('OPTIACCOUNTING_BOOTSTRAP_PASSWORD='.self::PASSWORD);

        $this->artisan('optiaccounting:bootstrap-platform-admin', ['email' => 'root@example.test', '--name' => 'Root'])
            ->expectsOutputToContain('Platform administrator ready')->assertExitCode(0);
        putenv('OPTIACCOUNTING_BOOTSTRAP_PASSWORD');

        $user = User::query()->where('email', 'root@example.test')->firstOrFail();
        $this->assertNotSame(self::PASSWORD, $user->password);
        $this->assertSame(1, DB::table('platform_role_assignments')->where('user_id', $user->id)->count());

        $login = $this->postJson('/api/v1/auth/login', ['email' => 'root@example.test', 'password' => self::PASSWORD])->assertOk();
        $this->assertSame('platform', $login->json('scope'));
        $this->as($login->json('token'))->getJson('/api/v1/platform/tenants')->assertOk();
    }

    public function test_bootstrap_is_idempotent_and_does_not_reset_an_existing_password(): void
    {
        putenv('OPTIACCOUNTING_BOOTSTRAP_PASSWORD='.self::PASSWORD);
        $this->artisan('optiaccounting:bootstrap-platform-admin', ['email' => 'root@example.test'])->assertExitCode(0);
        $hash = User::query()->where('email', 'root@example.test')->value('password');

        putenv('OPTIACCOUNTING_BOOTSTRAP_PASSWORD=An0ther!Passw0rd#2');
        $this->artisan('optiaccounting:bootstrap-platform-admin', ['email' => 'ROOT@example.test'])->expectsOutputToContain('already a platform administrator')->assertExitCode(0);
        putenv('OPTIACCOUNTING_BOOTSTRAP_PASSWORD');

        $this->assertSame($hash, User::query()->where('email', 'root@example.test')->value('password'));
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, DB::table('platform_role_assignments')->count());
    }

    public function test_bootstrap_refuses_a_weak_password(): void
    {
        putenv('OPTIACCOUNTING_BOOTSTRAP_PASSWORD=weak');

        $this->artisan('optiaccounting:bootstrap-platform-admin', ['email' => 'root@example.test'])->assertExitCode(1);
        putenv('OPTIACCOUNTING_BOOTSTRAP_PASSWORD');

        $this->assertSame(0, User::query()->count());
    }

    public function test_bootstrap_can_promote_an_existing_user_without_touching_their_password(): void
    {
        $existing = User::query()->forceCreate(['name' => 'Existing', 'email' => 'exists@example.test', 'password' => bcrypt(self::PASSWORD), 'status' => 'ACTIVE']);
        $hash = $existing->password;

        $this->artisan('optiaccounting:bootstrap-platform-admin', ['email' => 'exists@example.test'])->expectsOutputToContain('The user exists')->assertExitCode(0);

        $this->assertSame($hash, $existing->fresh()->password);
        $this->assertSame(1, DB::table('platform_role_assignments')->where('user_id', $existing->id)->count());
    }

    public function test_the_demo_seeder_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not run in production');
        $this->app->make(DemoSeeder::class)->run(); // called directly: db:seed itself would ask for confirmation first
    }

    public function test_the_demo_seeder_is_idempotent(): void
    {
        $this->seed(DemoSeeder::class);
        $counts = fn () => array_map(fn ($t) => DB::table($t)->count(), ['users', 'tenants', 'tenant_users', 'subscriptions', 'bundles', 'branches', 'business_units', 'roles', 'data_scopes', 'tenant_module_entitlements']);
        $first = $counts();

        $this->seed(DemoSeeder::class);

        $this->assertSame($first, $counts());
    }

    public function test_every_documented_demo_login_works_and_lands_where_the_documentation_says(): void
    {
        $this->seed(DemoSeeder::class);
        $doc = file_get_contents(base_path('../docs/DEMO.md'));
        preg_match_all('/^\|\s*`([^`|]+@[^`|]+)`\s*\|\s*`([^`]+)`\s*\|\s*([a-z]+)\s*\|/m', $doc, $rows, PREG_SET_ORDER);

        $this->assertGreaterThanOrEqual(9, count($rows), 'docs/DEMO.md must list the demo logins');
        foreach ($rows as [, $email, $password, $scope]) {
            $this->flushHeaders();
            $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])
                ->assertOk()->assertJsonPath('scope', $scope);
        }
    }

    public function test_demo_tenants_show_the_documented_commercial_states(): void
    {
        $this->seed(DemoSeeder::class);
        $status = fn (string $code) => DB::table('tenants')->where('code', $code)->value('status');
        $sub = fn (string $code) => DB::table('subscriptions')->where('tenant_id', DB::table('tenants')->where('code', $code)->value('id'))->value('status');

        $this->assertSame(['ACTIVE', 'ACTIVE'], [$status('maju-jaya'), $sub('maju-jaya')]);
        $this->assertSame(['ACTIVE', 'PAST_DUE'], [$status('tunggakan'), $sub('tunggakan')]);
        $this->assertSame('SUSPENDED', $status('ditangguhkan'));

        $token = $this->postJson('/api/v1/auth/login', ['email' => 'admin@tunggakan.demo.test', 'password' => DemoSeeder::DEFAULT_PASSWORD])->assertOk()->json('token');
        $this->as($token)->getJson('/api/v1/app/branches')->assertOk();
        $this->as($token)->postJson('/api/v1/app/account/ping', [])->assertStatus(404); // sanity: unknown route is not a gate bypass

        $multi = $this->postJson('/api/v1/auth/login', ['email' => 'multi@demo.test', 'password' => DemoSeeder::DEFAULT_PASSWORD])->assertOk();
        $this->assertSame('identity', $multi->json('scope'));
        $this->assertCount(2, $multi->json('tenants'));
    }

    public function test_demo_users_are_limited_by_their_scope_and_role_permissions(): void
    {
        $this->seed(DemoSeeder::class);
        $login = fn (string $email) => $this->postJson('/api/v1/auth/login', ['email' => $email, 'password' => DemoSeeder::DEFAULT_PASSWORD])->assertOk()->json('token');

        $viewer = $login('viewer@majujaya.demo.test');
        $this->as($viewer)->getJson('/api/v1/app/branches')->assertOk();
        $this->as($viewer)->postJson('/api/v1/app/branches', ['code' => 'X', 'name' => 'X'])->assertForbidden();

        $finance = $login('keuangan@majujaya.demo.test');
        $this->as($finance)->getJson('/api/v1/app/audit-logs')->assertOk();
        $this->as($finance)->getJson('/api/v1/app/users')->assertForbidden();

        $admin = $login('admin@majujaya.demo.test');
        $this->as($admin)->getJson('/api/v1/app/users')->assertOk()->assertJsonPath('total', 5); // admin, 3 staff, multi-tenant viewer
    }
}
