<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** Cross-cutting security invariants (SECURITY_INVARIANTS §1, §2, §4): mass assignment, secrets, route coverage, role names. */
class SecurityTest extends TestCase
{
    use Fixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->tenant('alpha');
    }

    public function test_no_model_allows_mass_assignment_of_privileged_columns(): void
    {
        $privileged = ['id', 'tenant_id', 'status', 'is_system', 'password', 'created_by', 'updated_by', 'scope', 'source', 'state', 'subscription_id', 'user_id', 'role_id'];

        foreach ((new Finder)->files()->in(app_path('Domain'))->path('Models')->name('*.php') as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], 'Domain/'.substr($file->getRealPath(), strlen(app_path('Domain')) + 1));
            $reflection = new ReflectionClass($class);
            if (! $reflection->isSubclassOf(Model::class) || $reflection->isAbstract()) {
                continue;
            }

            if ($class === Permission::class) {
                continue; // the permission registry is written only by the catalog synchronizer; no route can create or change it
            }

            $model = $reflection->newInstanceWithoutConstructor();
            $this->assertSame([], array_values(array_intersect($privileged, $model->getFillable())), "{$class} lists a privileged column as fillable");
            $this->assertNotSame([], $model->getGuarded(), "{$class} must not disable mass-assignment protection");
        }
    }

    public function test_privileged_fields_in_payloads_are_ignored(): void
    {
        $admin = $this->tenantToken($this->member($this->tenant)[0], $this->tenant);
        $beta = $this->tenant('beta');

        $role = $this->as($admin)->postJson('/api/v1/app/roles', [
            'name' => 'Sneaky', 'permissions' => ['organization.view'], 'is_system' => true, 'tenant_id' => $beta->id, 'scope' => 'platform',
        ])->assertCreated()->json();
        $this->assertFalse((bool) $role['is_system']);
        $this->assertSame($this->tenant->id, $role['tenant_id']);
        $this->assertSame('tenant', $role['scope']);

        $member = $this->as($admin)->postJson('/api/v1/app/users', [
            'name' => 'M', 'email' => 'm@alpha.test', 'password' => self::PASSWORD, 'role_ids' => [],
            'status' => 'SUSPENDED', 'tenant_id' => $beta->id, 'is_platform_admin' => true,
        ])->assertCreated()->json();
        $this->assertSame('ACTIVE', $member['status']);
        $this->assertSame($this->tenant->id, $member['tenant_id']);
        $this->assertSame(0, $this->rows('platform_role_assignments', ['user_id' => $member['user_id']]));
        $this->assertSame('ACTIVE', DB::table('users')->where('email', 'm@alpha.test')->value('status'));

        $unit = $this->as($admin)->postJson('/api/v1/app/business-units', ['code' => 'U', 'name' => 'U', 'status' => 'INACTIVE', 'tenant_id' => $beta->id])->assertCreated()->json();
        $this->assertSame('ACTIVE', $unit['status']);
        $this->assertSame($this->tenant->id, $unit['tenant_id']);
    }

    public function test_platform_payloads_cannot_choose_ids_or_statuses_either(): void
    {
        $this->asPlatform()->postJson('/api/v1/platform/modules', ['code' => 'T_MOD', 'name' => 'T', 'status' => 'INACTIVE', 'id' => '01a11ae8-43cf-7125-a892-1623b11cf6a8'])
            ->assertCreated()->assertJsonPath('status', 'ACTIVE');

        $roleId = DB::table('roles')->where('scope', 'platform')->where('name', 'Platform Support (read-only)')->value('id');
        $created = $this->asPlatform()->postJson('/api/v1/platform/users', [
            'name' => 'Op', 'email' => 'op@platform.test', 'password' => self::PASSWORD, 'role_ids' => [$roleId], 'status' => 'INACTIVE',
        ])->assertCreated()->json();
        $this->assertSame('ACTIVE', DB::table('users')->where('email', 'op@platform.test')->value('status'));
        $this->assertNotNull($created);
    }

    public function test_responses_never_contain_password_hashes_or_tokens_of_others(): void
    {
        $admin = $this->tenantToken($this->member($this->tenant, null, 'admin@alpha.test')[0], $this->tenant);
        $this->as($admin)->postJson('/api/v1/app/users', ['name' => 'M', 'email' => 'm@alpha.test', 'password' => self::PASSWORD, 'role_ids' => []])->assertCreated();

        foreach (['/api/v1/app/users', '/api/v1/app/roles', '/api/v1/app/audit-logs', '/api/v1/app/capabilities', '/api/v1/auth/me'] as $url) {
            $body = $this->as($admin)->getJson($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('$2y$', $body, $url);
            $this->assertStringNotContainsString('"password"', $body, $url);
            $this->assertStringNotContainsString('remember_token', $body, $url);
        }

        $platform = $this->asPlatform()->getJson('/api/v1/platform/users')->assertOk()->getContent();
        $this->assertStringNotContainsString('$2y$', $platform);
        $this->assertStringNotContainsString('"password"', $platform);
    }

    public function test_new_passwords_must_be_strong(): void
    {
        $admin = $this->tenantToken($this->member($this->tenant)[0], $this->tenant);

        foreach (['short1A', 'alllowercase1234', 'ALLUPPERCASE1234', 'NoDigitsHereAtAll'] as $weak) {
            $this->as($admin)->postJson('/api/v1/app/users', ['name' => 'M', 'email' => 'weak@alpha.test', 'password' => $weak, 'role_ids' => []])
                ->assertStatus(422)->assertJsonValidationErrors('password');
        }
        $this->assertSame(0, $this->rows('users', ['email' => 'weak@alpha.test']));
    }

    public function test_expired_tokens_are_rejected_and_tokens_are_issued_with_an_expiry(): void
    {
        $this->assertSame(480, (int) config('sanctum.expiration'));

        [$user] = $this->member($this->tenant);
        $token = $user->createToken('t', ['tenant:'.$this->tenant->id], now()->subMinute())->plainTextToken;
        $this->as($token)->getJson('/api/v1/app/branches')->assertUnauthorized();

        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => self::PASSWORD])->assertOk();
        $this->assertNotNull($login->json('expires_at'));
    }

    public function test_search_input_cannot_break_out_of_the_query(): void
    {
        $admin = $this->tenantToken($this->member($this->tenant)[0], $this->tenant);

        foreach (["'; DROP TABLE users;--", '%', '_', '" OR 1=1 --', '\\'] as $payload) {
            $this->as($admin)->getJson('/api/v1/app/users?search='.urlencode($payload))->assertOk();
            $this->as($admin)->getJson('/api/v1/app/audit-logs?action='.urlencode($payload))->assertOk();
        }
        $this->as($admin)->getJson('/api/v1/app/users?status[]=ACTIVE&search[]=x')->assertStatus(422); // arrays are rejected, not a 500
        $this->asPlatform()->getJson('/api/v1/platform/audit-logs?tenant_id=not-a-uuid')->assertStatus(422);
        $this->asPlatform()->getJson('/api/v1/platform/tenants?search[]=x')->assertStatus(422);
        $this->assertGreaterThan(0, $this->rows('users'));
    }

    public function test_every_protected_route_rejects_anonymous_callers(): void
    {
        $public = ['api/v1/health', 'api/v1/auth/login'];
        $checked = 0;

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/') || in_array($uri, $public, true) || str_contains($uri, '_test')) {
                continue;
            }
            $method = $route->methods()[0];
            $path = preg_replace('/\{[^}]+\}/', '01a11ae8-43cf-7125-a892-1623b11cf6a8', $uri);

            $this->flushHeaders();
            $this->json($method, '/'.$path)->assertUnauthorized();
            $checked++;
        }

        $this->assertGreaterThan(60, $checked);
    }

    public function test_every_platform_and_tenant_route_declares_a_permission_check(): void
    {
        $exempt = ['api/v1/app/capabilities']; // the caller's own capability list
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! preg_match('#^api/v1/(platform|app)/#', $uri) || in_array($uri, $exempt, true)) {
                continue;
            }
            if (! collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'access:'))) {
                $missing[] = implode('|', $route->methods()).' '.$uri;
            }
        }

        $this->assertSame([], $missing);
    }

    public function test_roles_are_never_used_for_authorization_decisions(): void
    {
        $allowed = [ // provisioning only: attach the seeded role to the first administrator
            'Domain/Identity/Services/TenantService.php',
            'Domain/AccessControl/Services/SystemRoleSynchronizer.php',
            'Console/Commands/BootstrapPlatformAdmin.php',
        ];

        $offenders = [];
        foreach ((new Finder)->files()->in(app_path())->name('*.php') as $file) {
            $relative = substr($file->getRealPath(), strlen(app_path()) + 1);
            if (in_array($relative, $allowed, true)) {
                continue;
            }
            if (preg_match('/Tenant Administrator|Platform Administrator|Tenant Viewer|Platform Support|hasRole\\(|->role->name|\\bisAdmin\\b|role_name/', $file->getContents())) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_frontend_never_decides_by_role_name(): void
    {
        $src = base_path('../frontend/src');
        if (! is_dir($src)) {
            $this->markTestSkipped('frontend sources not present');
        }

        $offenders = [];
        foreach ((new Finder)->files()->in($src)->name(['*.ts', '*.tsx']) as $file) {
            if (preg_match("/role\\s*===|roles?\\.(includes|some)\\(|hasRole|isAdmin|Administrator['\"]\\s*\\)/", $file->getContents())) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders);
    }
}
