<?php

namespace Tests\Support;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Module;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One sweep over EVERY route of one or more modules (read from the router, so a route added later is covered without touching the test)
 * for permission, tenant ownership and entitlement state. A test class uses the trait, sets $alpha, $bravo, $admin (a token holding every
 * permission) and $ids (route parameter name => id of a real alpha record), and answers the abstract hooks. Needs Fixtures and PayablesFixtures.
 */
trait ModuleAccessMatrix
{
    protected Tenant $alpha;

    protected Tenant $bravo;

    protected string $admin;

    /** @var array<string,string> route parameter name => id of a real alpha record */
    protected array $ids;

    /** @return list<string> the module codes whose routes the sweep covers */
    abstract protected function matrixModules(): array;

    /** @return list<string> every feature those routes name */
    abstract protected function matrixFeatures(): array;

    /** @return array<string,string> "METHOD uri" => permission, the authorization contract written out */
    abstract protected function pinnedPermissions(): array;

    /** @return list<string> tables holding tenant-owned rows a cross-tenant call must not touch */
    abstract protected function fingerprintTables(): array;

    protected function prefix(): string
    {
        return 'api/v1/app/accounting/';
    }

    /** @return list<array{method:string,uri:string,permission:string,module:string,feature:string,mutates:bool,bound:bool}> */
    protected function routes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), $this->prefix())) {
                continue;
            }
            $gate = collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'access:'));
            $this->assertNotNull($gate, 'no access gate on '.$route->uri());
            $parts = explode(',', substr($gate, 7));
            $options = collect(array_slice($parts, 1))->mapWithKeys(fn ($p) => [explode('=', $p)[0] => explode('=', $p)[1] ?? null]);
            if (! in_array($options['module'] ?? null, $this->matrixModules(), true)) {
                continue; // the other modules have their own matrices
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = [
                    'method' => $method, 'uri' => $route->uri(), 'permission' => $parts[0], 'module' => $options['module'], 'feature' => $options['feature'],
                    'mutates' => $method !== 'GET', 'bound' => str_contains($route->uri(), '{'),
                ];
            }
        }

        return $routes;
    }

    protected function label(array $route): string
    {
        return $route['method'].' '.substr($route['uri'], strlen($this->prefix()));
    }

    protected function hit(string $token, array $route, ?array $ids = null): TestResponse
    {
        $uri = preg_replace_callback('/\{(\w+)\}/', fn ($m) => ($ids ?? $this->ids)[$m[1]] ?? '', $route['uri']);

        return $this->as($token)->json($route['method'], '/'.$uri, []);
    }

    /** Every route's outcome as "METHOD uri" => "status[:code]". @return array<string,string> */
    protected function sweep(string $token, ?array $only = null, ?array $ids = null): array
    {
        $out = [];
        foreach ($only ?? $this->routes() as $route) {
            $response = $this->hit($token, $route, $ids);
            $this->assertLessThan(500, $response->getStatusCode(), $this->label($route).' answered with a server error: '.substr($response->baseResponse instanceof StreamedResponse ? '' : (string) $response->getContent(), 0, 300));
            $out[$this->label($route)] = $response->getStatusCode().(($code = $this->errorCode($response)) ? ":{$code}" : '');
        }

        return $out;
    }

    /** The machine-readable error code of a JSON error; exports are streamed CSV and have none. */
    protected function errorCode(TestResponse $response): ?string
    {
        $content = $response->baseResponse instanceof StreamedResponse || $response->getStatusCode() < 400 ? false : $response->getContent();

        return is_string($content) && $content !== '' && $content[0] === '{' ? (json_decode($content, true)['code'] ?? null) : null;
    }

    protected function entitle(string $module, array $attrs): void
    {
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->alpha->id)->where('module_id', Module::query()->where('code', $module)->value('id'))->update($attrs);
        app(AccessCache::class)->touchTenant($this->alpha->id);
    }

    protected function subscription(array $attrs): void
    {
        DB::table('subscriptions')->where('tenant_id', $this->alpha->id)->update($attrs);
        app(AccessCache::class)->touchTenant($this->alpha->id);
    }

    protected function featureState(string $feature, string $state): void
    {
        DB::table('tenant_feature_entitlements')->where('tenant_id', $this->alpha->id)->where('feature_id', DB::table('features')->where('code', $feature)->value('id'))->update(['state' => $state]);
        app(AccessCache::class)->touchTenant($this->alpha->id);
    }

    /** @return array<string,list<object>> the rows a state change can touch, to put back between cases */
    protected function entitlementRows(): array
    {
        return [
            'tenant_module_entitlements' => DB::table('tenant_module_entitlements')->where('tenant_id', $this->alpha->id)->get()->all(),
            'subscriptions' => DB::table('subscriptions')->where('tenant_id', $this->alpha->id)->get()->all(),
            'tenants' => DB::table('tenants')->where('id', $this->alpha->id)->get()->all(),
        ];
    }

    protected function restoreEntitlementRows(array $rows): void
    {
        foreach ($rows as $table => $list) {
            foreach ($list as $row) {
                DB::table($table)->where('id', $row->id)->update((array) $row);
            }
        }
        $this->alpha = Tenant::query()->findOrFail($this->alpha->id);
        app(AccessCache::class)->touchTenant($this->alpha->id);
    }

    /** @param array<string,string> $outcomes @return array<string,string> the entries that do not satisfy $ok */
    protected function violations(array $outcomes, callable $ok): array
    {
        return array_filter($outcomes, fn ($outcome, $route) => ! $ok($outcome, $route), ARRAY_FILTER_USE_BOTH);
    }

    protected function passedTheGate(string $outcome): bool
    {
        return ! str_starts_with($outcome, '401') && ! str_starts_with($outcome, '403') && ! str_starts_with($outcome, '5');
    }

    /** @return array<string,string> table => hash of every alpha row */
    protected function fingerprint(): array
    {
        $out = [];
        foreach ($this->fingerprintTables() as $table) {
            $out[$table] = md5(DB::table($table)->where('tenant_id', $this->alpha->id)->orderBy('id')->get()->toJson());
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------ structure

    public function test_every_route_names_an_accounting_permission_its_module_and_one_of_that_modules_features(): void
    {
        $routes = $this->routes();
        $features = DB::table('features as f')->join('modules as m', 'm.id', '=', 'f.module_id')->get(['m.code as module', 'f.code as feature'])->groupBy('module')->map(fn ($g) => $g->pluck('feature')->all());
        $catalog = DB::table('permissions')->where('scope', 'tenant')->pluck('code')->all();

        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $label = $this->label($route);
            $this->assertMatchesRegularExpression('/^accounting(\.[a-z_]+){2,3}$/', $route['permission'], $label);
            $this->assertContains($route['permission'], $catalog, "{$label} names a permission that is not in the catalog");
            $this->assertContains($route['feature'], $features[$route['module']] ?? [], "{$label}: {$route['feature']} is not a feature of {$route['module']}");
        }
        $this->assertEqualsCanonicalizing($this->matrixFeatures(), array_values(array_unique(array_column($routes, 'feature'))), 'every feature of the module has routes');
    }

    // ------------------------------------------------------------------------------------------ permissions

    public function test_the_permission_of_every_route_is_pinned(): void
    {
        $actual = [];
        foreach ($this->routes() as $route) {
            $actual[$this->label($route)] = $route['permission'];
        }
        $expected = $this->pinnedPermissions();
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_a_member_with_no_accounting_permission_is_forbidden_on_every_route_even_for_unknown_ids(): void
    {
        $token = $this->memberToken($this->alpha, ['organization.view', 'access.user.view']);

        $real = $this->violations($this->sweep($token), fn ($o) => $o === '403:PERMISSION_DENIED');
        $unknown = array_map(fn () => '00000000-0000-4000-8000-000000000000', $this->ids);
        $guessed = $this->violations($this->sweep($token, null, $unknown), fn ($o) => $o === '403:PERMISSION_DENIED');

        $this->assertSame([], $real);
        $this->assertSame([], $guessed, 'a caller without permission must not learn whether an id exists');
    }

    public function test_each_atomic_permission_guards_exactly_its_own_routes(): void
    {
        $routes = $this->routes();
        $byPermission = [];
        foreach ($routes as $route) {
            $byPermission[$route['permission']][] = $route;
        }
        $everything = DB::table('permissions')->where('scope', 'tenant')->pluck('code')->all();

        // Lacking only P, P's routes are forbidden.
        foreach ($byPermission as $permission => $group) {
            $token = $this->memberToken($this->alpha, array_values(array_diff($everything, [$permission])));
            $this->assertSame([], $this->violations($this->sweep($token, $group), fn ($o) => $o === '403:PERMISSION_DENIED'), "lacking only {$permission}");
        }

        // Holding only P opens P's routes (whatever the business outcome: validation, conflict, not found) and nothing else.
        foreach ($byPermission as $permission => $group) {
            $token = $this->memberToken($this->alpha, [$permission]);
            $others = array_values(array_filter($routes, fn ($r) => $r['permission'] !== $permission));

            $opened = $this->sweep($token, $group);
            $this->assertSame([], $this->violations($opened, fn ($o) => $this->passedTheGate($o)), "holding only {$permission}: ".json_encode($opened));
            $this->assertSame([], $this->violations($this->sweep($token, $others), fn ($o) => str_starts_with($o, '403:PERMISSION_DENIED')), "{$permission} must not open other routes");
        }
    }

    // ------------------------------------------------------------------------------------------ tenant ownership

    public function test_another_tenants_records_are_not_found_on_every_route_and_nothing_of_theirs_changes(): void
    {
        $intruder = $this->memberToken($this->bravo);
        $bound = array_values(array_filter($this->routes(), fn ($r) => $r['bound']));
        $this->assertNotEmpty($bound);
        $before = $this->fingerprint();

        $outcome = $this->sweep($intruder, $bound);

        $this->assertSame([], $this->violations($outcome, fn ($o) => $o === '404'), json_encode($this->violations($outcome, fn ($o) => $o === '404')));
        $this->assertSame($before, $this->fingerprint(), 'a cross-tenant call left a trace in the other tenant');
    }

    // ------------------------------------------------------------------------------------------ entitlement

    public function test_a_read_only_module_keeps_its_reads_open_and_refuses_its_mutations(): void
    {
        foreach ($this->matrixModules() as $module) {
            $this->entitle($module, ['state' => 'READ_ONLY']);
        }
        $outcome = $this->sweep($this->admin);

        $bad = $this->violations($outcome, fn ($o, $label) => str_starts_with($label, 'GET ') ? $this->passedTheGate($o) : $o === '403:MODULE_READ_ONLY');
        $this->assertSame([], $bad, 'READ_ONLY: '.json_encode($bad));
    }

    public function test_an_overdue_subscription_is_read_only_for_every_module_but_not_for_administration(): void
    {
        $this->subscription(['status' => 'PAST_DUE']);
        $outcome = $this->sweep($this->admin);

        $bad = $this->violations($outcome, fn ($o, $r) => str_starts_with($r, 'GET ') ? $this->passedTheGate($o) : $o === '403:SUBSCRIPTION_READ_ONLY');
        $this->assertSame([], $bad, json_encode($bad));
        $this->as($this->admin)->postJson('/api/v1/app/branches', ['code' => 'LATE', 'name' => 'Cabang'])->assertCreated();
    }

    public function test_every_state_that_removes_the_module_closes_all_of_its_routes_reads_included(): void
    {
        $today = $this->alpha->businessDate();
        $states = [
            'SUSPENDED' => ['state' => 'SUSPENDED'],
            'DISABLED' => ['state' => 'DISABLED'],
            'expired yesterday' => ['state' => 'ACTIVE', 'effective_from' => '2020-01-01', 'effective_until' => Carbon::parse($today)->subDay()->toDateString()],
            'starts tomorrow' => ['effective_from' => Carbon::parse($today)->addDay()->toDateString(), 'effective_until' => null],
        ];

        $baseline = $this->entitlementRows();
        foreach ($this->matrixModules() as $module) {
            foreach ($states as $label => $attrs) {
                $this->restoreEntitlementRows($baseline);
                $this->entitle($module, $attrs);
                $outcome = $this->sweep($this->admin, array_values(array_filter($this->routes(), fn ($r) => $r['module'] === $module)));

                $bad = $this->violations($outcome, fn ($o) => $o === '403:MODULE_NOT_ENTITLED');
                $this->assertSame([], $bad, "{$module} {$label}: ".json_encode(array_slice($bad, 0, 5)));
            }
        }
    }

    public function test_every_subscription_or_tenant_state_that_removes_access_closes_every_route(): void
    {
        $today = $this->alpha->businessDate();
        $cases = [
            'subscription PENDING' => [fn () => $this->subscription(['status' => 'PENDING']), 'SUBSCRIPTION_INACTIVE'],
            'subscription SUSPENDED' => [fn () => $this->subscription(['status' => 'SUSPENDED']), 'SUBSCRIPTION_INACTIVE'],
            'subscription EXPIRED' => [fn () => $this->subscription(['status' => 'EXPIRED']), 'SUBSCRIPTION_INACTIVE'],
            'subscription CANCELLED' => [fn () => $this->subscription(['status' => 'CANCELLED']), 'SUBSCRIPTION_INACTIVE'],
            'active subscription past its end date' => [fn () => $this->subscription(['status' => 'ACTIVE', 'starts_on' => '2020-01-01', 'ends_on' => Carbon::parse($today)->subDay()->toDateString()]), 'SUBSCRIPTION_INACTIVE'],
            'tenant SUSPENDED' => [fn () => $this->alpha->forceFill(['status' => Tenant::SUSPENDED])->save(), 'TENANT_INACTIVE'],
        ];

        $baseline = $this->entitlementRows();
        foreach ($cases as $label => [$apply, $code]) {
            $this->restoreEntitlementRows($baseline);
            $apply();
            $outcome = $this->sweep($this->admin);
            $bad = $this->violations($outcome, fn ($o) => $o === "403:{$code}");
            $this->assertSame([], $bad, "{$label}: ".json_encode(array_slice($bad, 0, 5)));
        }
    }

    public function test_a_disabled_feature_closes_only_its_own_routes(): void
    {
        $routes = $this->routes();
        $reads = array_values(array_filter($routes, fn ($r) => ! $r['mutates']));

        foreach (array_unique(array_column($routes, 'feature')) as $feature) {
            $this->featureState($feature, 'DISABLED');
            $outcome = $this->sweep($this->admin, $reads);
            foreach ($reads as $route) {
                $result = $outcome[$this->label($route)];
                $route['feature'] === $feature ? $this->assertSame('403:FEATURE_NOT_ENTITLED', $result, "{$feature} disabled: ".$this->label($route))
                    : $this->assertStringStartsNotWith('403', $result, "{$feature} disabled must not close ".$this->label($route));
            }
            $this->featureState($feature, 'ACTIVE');
        }
    }

    public function test_a_membership_that_is_no_longer_active_loses_every_route_at_once(): void
    {
        [$user, $membership] = $this->member($this->alpha);
        $token = $this->tenantToken($user, $this->alpha);
        $routes = array_values(array_filter($this->routes(), fn ($r) => ! $r['bound']));
        $this->assertSame([], $this->violations($this->sweep($token, array_slice($routes, 0, 3)), fn ($o) => ! str_starts_with($o, '403')));

        DB::table('tenant_users')->where('id', $membership->id)->update(['status' => 'SUSPENDED']);
        app(AccessCache::class)->touchTenant($this->alpha->id);

        $outcome = $this->sweep($token, $routes);
        $bad = $this->violations($outcome, fn ($o) => str_starts_with($o, '403:') || $o === '401');
        $this->assertSame([], $bad, json_encode($bad));
    }
}
