<?php

namespace Tests\Feature\Accounting;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Module;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\TestCase;

/**
 * OA1 batch M: one sweep over EVERY accounting route (read from the router, so a route added later is covered without
 * touching this file) for permission, tenant ownership and entitlement state.
 */
class AccessMatrixTest extends TestCase
{
    use AccountingFixtures, Fixtures;

    private const PREFIX = 'api/v1/app/accounting/';

    private Tenant $alpha;

    private Tenant $bravo;

    private string $admin;

    /** @var array<string,string> route parameter name => id of a real alpha record */
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->accountingTenant('alpha');
        $this->bravo = $this->accountingTenant('bravo');
        $this->admin = $this->tenantToken($this->member($this->alpha)[0], $this->alpha);

        $client = $this->as($this->admin);
        $this->ids = [
            'fiscalYear' => $this->fiscalYear($this->alpha, 'FY2026')->id,
            'period' => $this->period($this->alpha, '2026-03')->id,
            'account' => $this->account($this->alpha, '1110')->id,
            'journal' => $client->postJson('/'.self::PREFIX.'journals', $this->journalBody($this->alpha))->assertCreated()->json('id'),
            'costCenter' => $client->postJson('/'.self::PREFIX.'cost-centers', ['code' => 'OPS', 'name' => 'Operasional'])->assertCreated()->json('id'),
            'rule' => $client->postJson('/'.self::PREFIX.'posting-rules', ['code' => 'EXP', 'event_type' => 'EXPENSE_RECOGNIZED', 'name' => 'Beban', 'lines' => [
                ['side' => 'DEBIT', 'account_role' => 'EXPENSE', 'amount_key' => 'net'], ['side' => 'CREDIT', 'account_role' => 'ACCOUNTS_PAYABLE', 'amount_key' => 'total'],
            ]])->assertCreated()->json('id'),
            'mapping' => (string) DB::table('account_mappings')->where('tenant_id', $this->alpha->id)->value('id'),
        ];
    }

    /** @return list<array{method:string,uri:string,permission:string,module:?string,feature:?string,mutates:bool,bound:bool}> */
    private function routes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), self::PREFIX)) {
                continue;
            }
            $gate = collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'access:'));
            $this->assertNotNull($gate, 'no access gate on '.$route->uri());
            $parts = explode(',', substr($gate, 7));
            $options = collect(array_slice($parts, 1))->mapWithKeys(fn ($p) => [explode('=', $p)[0] => explode('=', $p)[1] ?? null]);
            if (($options['module'] ?? null) !== 'ACCOUNTING_CORE') {
                continue; // the OA2 modules have their own matrix (Payables/OperationalAccessMatrixTest)
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $routes[] = [
                    'method' => $method, 'uri' => $route->uri(), 'permission' => $parts[0], 'module' => $options['module'] ?? null, 'feature' => $options['feature'] ?? null,
                    'mutates' => $method !== 'GET', 'bound' => str_contains($route->uri(), '{'),
                ];
            }
        }

        return $routes;
    }

    private function hit(string $token, array $route, ?array $ids = null): TestResponse
    {
        $uri = preg_replace_callback('/\{(\w+)\}/', fn ($m) => ($ids ?? $this->ids)[$m[1]] ?? '', $route['uri']);

        return $this->as($token)->json($route['method'], '/'.$uri, []);
    }

    /** Every route's outcome as "METHOD uri" => "status[:code]". @return array<string,string> */
    private function sweep(string $token, ?array $only = null, ?array $ids = null): array
    {
        $out = [];
        foreach ($only ?? $this->routes() as $route) {
            $response = $this->hit($token, $route, $ids);
            $out[$route['method'].' '.substr($route['uri'], strlen(self::PREFIX))] = $response->getStatusCode().(($code = $this->errorCode($response)) ? ":{$code}" : '');
        }

        return $out;
    }

    /** The machine-readable error code of a JSON error; exports are streamed CSV and have none. */
    private function errorCode(TestResponse $response): ?string
    {
        $content = $response->baseResponse instanceof StreamedResponse || $response->getStatusCode() < 400 ? false : $response->getContent(); // a 200 body may carry a business field called code

        return is_string($content) && $content !== '' && $content[0] === '{' ? (json_decode($content, true)['code'] ?? null) : null;
    }

    private function entitlement(string $table, string $column, string $code, array $attrs): void
    {
        $ownerId = $table === 'tenant_module_entitlements'
            ? Module::query()->where('code', $code)->value('id')
            : DB::table('features')->where('code', $code)->value('id');
        DB::table($table)->where('tenant_id', $this->alpha->id)->where($column, $ownerId)->update($attrs);
        app(AccessCache::class)->touchTenant($this->alpha->id);
    }

    private function module(array $attrs): void
    {
        $this->entitlement('tenant_module_entitlements', 'module_id', 'ACCOUNTING_CORE', $attrs);
    }

    private function subscription(array $attrs): void
    {
        DB::table('subscriptions')->where('tenant_id', $this->alpha->id)->update($attrs);
        app(AccessCache::class)->touchTenant($this->alpha->id);
    }

    /** @return array<string,list<object>> the rows a state change can touch, to put back between cases */
    private function entitlementRows(): array
    {
        return [
            'tenant_module_entitlements' => DB::table('tenant_module_entitlements')->where('tenant_id', $this->alpha->id)->get()->all(),
            'subscriptions' => DB::table('subscriptions')->where('tenant_id', $this->alpha->id)->get()->all(),
            'tenants' => DB::table('tenants')->where('id', $this->alpha->id)->get()->all(),
        ];
    }

    private function restoreEntitlementRows(array $rows): void
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
    private function violations(array $outcomes, callable $ok): array
    {
        return array_filter($outcomes, fn ($outcome, $route) => ! $ok($outcome, $route), ARRAY_FILTER_USE_BOTH);
    }

    // ------------------------------------------------------------------------------------------ structure

    public function test_every_accounting_route_names_an_accounting_permission_the_module_and_one_of_its_features(): void
    {
        $routes = $this->routes();
        $features = DB::table('features as f')->join('modules as m', 'm.id', '=', 'f.module_id')->where('m.code', 'ACCOUNTING_CORE')->pluck('f.code')->all();
        $catalog = DB::table('permissions')->where('scope', 'tenant')->pluck('code')->all();

        $this->assertGreaterThan(55, count($routes));
        foreach ($routes as $route) {
            $label = $route['method'].' '.$route['uri'];
            $this->assertMatchesRegularExpression('/^accounting\.[a-z_]+\.[a-z_]+$/', $route['permission'], $label);
            $this->assertContains($route['permission'], $catalog, "{$label} names a permission that is not in the catalog");
            $this->assertSame('ACCOUNTING_CORE', $route['module'], $label);
            $this->assertContains($route['feature'], $features, "{$label} names an unknown feature");
        }
    }

    // ------------------------------------------------------------------------------------------ permissions

    /** The authorization contract of OA1, written out: a new route or a changed permission is a conscious edit of this table. */
    public function test_the_permission_of_every_route_is_pinned(): void
    {
        $expected = [
            'GET account-mappings' => 'accounting.account_mapping.view',
            'PUT account-mappings' => 'accounting.account_mapping.manage',
            'POST account-mappings/{mapping}/deactivate' => 'accounting.account_mapping.manage',
            'GET accounting-events' => 'accounting.posting_rule.view',
            'GET accounts' => 'accounting.coa.view',
            'POST accounts' => 'accounting.coa.manage',
            'GET accounts-export' => 'accounting.report.export',
            'PATCH accounts/{account}' => 'accounting.coa.manage',
            'DELETE accounts/{account}' => 'accounting.coa.manage',
            'POST accounts/{account}/status' => 'accounting.coa.manage',
            'GET coa-templates' => 'accounting.coa.view',
            'POST coa-templates/apply' => 'accounting.coa.manage',
            'GET cost-centers' => 'accounting.dimension.view',
            'POST cost-centers' => 'accounting.dimension.manage',
            'PATCH cost-centers/{costCenter}' => 'accounting.dimension.manage',
            'POST cost-centers/{costCenter}/status' => 'accounting.dimension.manage',
            'GET dashboard' => 'accounting.journal.view',
            'GET dimension-types' => 'accounting.dimension.view',
            'GET dimensions' => 'accounting.dimension.view',
            'GET event-types' => 'accounting.posting_rule.view',
            'GET fiscal-years' => 'accounting.period.view',
            'POST fiscal-years' => 'accounting.period.manage',
            'DELETE fiscal-years/{fiscalYear}' => 'accounting.period.manage',
            'POST fiscal-years/{fiscalYear}/close' => 'accounting.period.close',
            'POST fiscal-years/{fiscalYear}/open' => 'accounting.period.manage',
            'GET general-ledger' => 'accounting.gl.view',
            'GET general-ledger/export' => 'accounting.report.export',
            'GET journals' => 'accounting.journal.view',
            'POST journals' => 'accounting.journal.create',
            'GET journals/{journal}' => 'accounting.journal.view',
            'PATCH journals/{journal}' => 'accounting.journal.update',
            'POST journals/{journal}/approve' => 'accounting.journal.approve',
            'POST journals/{journal}/cancel' => 'accounting.journal.update',
            'POST journals/{journal}/post' => 'accounting.journal.post',
            'POST journals/{journal}/reject' => 'accounting.journal.approve',
            'POST journals/{journal}/reopen' => 'accounting.journal.update',
            'POST journals/{journal}/reverse' => 'accounting.journal.reverse',
            'POST journals/{journal}/submit' => 'accounting.journal.submit',
            'GET opening-balance' => 'accounting.opening_balance.view',
            'PUT opening-balance' => 'accounting.opening_balance.manage',
            'POST opening-balance/cancel' => 'accounting.opening_balance.manage',
            'POST opening-balance/post' => 'accounting.opening_balance.post',
            'POST periods/{period}/close' => 'accounting.period.close',
            'POST periods/{period}/open' => 'accounting.period.manage',
            'POST periods/{period}/soft-close' => 'accounting.period.manage',
            'GET posting-rules' => 'accounting.posting_rule.view',
            'POST posting-rules' => 'accounting.posting_rule.manage',
            'GET posting-rules/{rule}' => 'accounting.posting_rule.view',
            'PATCH posting-rules/{rule}' => 'accounting.posting_rule.manage',
            'DELETE posting-rules/{rule}' => 'accounting.posting_rule.manage',
            'POST posting-rules/{rule}/archive' => 'accounting.posting_rule.manage',
            'POST posting-rules/{rule}/new-version' => 'accounting.posting_rule.manage',
            'POST posting-rules/{rule}/publish' => 'accounting.posting_rule.manage',
            'POST posting-rules/{rule}/simulate' => 'accounting.posting_rule.view',
            'GET profile' => 'accounting.profile.view',
            'PUT profile' => 'accounting.profile.manage',
            'POST profile/activate' => 'accounting.profile.manage',
            'GET readiness' => 'accounting.profile.view',
            'GET trial-balance' => 'accounting.trial_balance.view',
            'GET trial-balance/export' => 'accounting.report.export',
        ];

        $actual = [];
        foreach ($this->routes() as $route) {
            $actual[$route['method'].' '.substr($route['uri'], strlen(self::PREFIX))] = $route['permission'];
        }
        ksort($expected);
        ksort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_a_member_with_no_accounting_permission_is_forbidden_on_every_route_even_for_unknown_ids(): void
    {
        $token = $this->tenantToken($this->member($this->alpha, ['organization.view', 'access.user.view'])[0], $this->alpha);

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
        $this->assertGreaterThan(20, count($byPermission));

        // Phase 1 changes nothing (every call is refused): lacking only P, P's routes are forbidden.
        $tokens = [];
        foreach ($byPermission as $permission => $group) {
            $tokens[$permission] = $this->tenantToken($this->member($this->alpha, array_values(array_diff($everything, [$permission])))[0], $this->alpha);
            $this->assertSame([], $this->violations($this->sweep($tokens[$permission], $group), fn ($o) => $o === '403:PERMISSION_DENIED'), "lacking only {$permission}");
        }

        // Phase 2: holding only P opens P's routes (whatever the business outcome: validation, conflict, not found) and nothing else.
        foreach ($byPermission as $permission => $group) {
            $token = $this->tenantToken($this->member($this->alpha, [$permission])[0], $this->alpha);
            $others = array_values(array_filter($routes, fn ($r) => $r['permission'] !== $permission));

            $opened = $this->sweep($token, $group);
            $this->assertSame([], $this->violations($opened, fn ($o) => ! str_starts_with($o, '401') && ! str_starts_with($o, '403') && ! str_starts_with($o, '5')), "holding only {$permission}: ".json_encode($opened));
            $this->assertSame([], $this->violations($this->sweep($token, $others), fn ($o) => str_starts_with($o, '403:PERMISSION_DENIED')), "{$permission} must not open other routes");
        }
    }

    // ------------------------------------------------------------------------------------------ tenant ownership

    public function test_another_tenants_records_are_not_found_on_every_route_and_nothing_of_theirs_changes(): void
    {
        $intruder = $this->tenantToken($this->member($this->bravo)[0], $this->bravo);
        $bound = array_values(array_filter($this->routes(), fn ($r) => $r['bound']));
        $this->assertGreaterThan(25, count($bound));
        $before = $this->fingerprint();

        $outcome = $this->sweep($intruder, $bound);

        $this->assertSame([], $this->violations($outcome, fn ($o) => $o === '404'), json_encode($outcome));
        $this->assertSame($before, $this->fingerprint(), 'a cross-tenant call left a trace in the other tenant');
    }

    /** @return array<string,string> table => hash of every alpha row */
    private function fingerprint(): array
    {
        $out = [];
        foreach (['fiscal_years', 'accounting_periods', 'accounts', 'journal_entries', 'journal_lines', 'posting_rules', 'account_mappings', 'cost_centers', 'audit_logs', 'document_sequences'] as $table) {
            $out[$table] = md5(DB::table($table)->where('tenant_id', $this->alpha->id)->orderBy('id')->get()->toJson());
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------ entitlement

    public function test_a_read_only_module_keeps_every_read_open_and_refuses_every_mutation(): void
    {
        $this->module(['state' => 'READ_ONLY']);
        $outcome = $this->sweep($this->admin);

        $this->assertSame([], $this->violations($outcome, fn ($o, $r) => str_starts_with($r, 'GET ') ? $o === '200' : $o === '403:MODULE_READ_ONLY'), json_encode($this->violations($outcome, fn ($o, $r) => str_starts_with($r, 'GET ') ? $o === '200' : $o === '403:MODULE_READ_ONLY')));
    }

    public function test_an_overdue_subscription_is_read_only_for_accounting_but_not_for_administration(): void
    {
        $this->subscription(['status' => 'PAST_DUE']);
        $outcome = $this->sweep($this->admin);

        $this->assertSame([], $this->violations($outcome, fn ($o, $r) => str_starts_with($r, 'GET ') ? $o === '200' : $o === '403:SUBSCRIPTION_READ_ONLY'));
        $this->as($this->admin)->postJson('/api/v1/app/branches', ['code' => 'LATE', 'name' => 'Cabang'])->assertCreated();
    }

    public function test_every_state_that_removes_the_module_closes_every_route_reads_included(): void
    {
        $today = $this->alpha->businessDate();
        $cases = [
            'module SUSPENDED' => [fn () => $this->module(['state' => 'SUSPENDED']), 'MODULE_NOT_ENTITLED'],
            'module DISABLED' => [fn () => $this->module(['state' => 'DISABLED']), 'MODULE_NOT_ENTITLED'],
            'module expired yesterday' => [fn () => $this->module(['state' => 'ACTIVE', 'effective_from' => '2020-01-01', 'effective_until' => Carbon::parse($today)->subDay()->toDateString()]), 'MODULE_NOT_ENTITLED'],
            'module starts tomorrow' => [fn () => $this->module(['effective_from' => Carbon::parse($today)->addDay()->toDateString(), 'effective_until' => null]), 'MODULE_NOT_ENTITLED'],
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
            $this->assertSame([], $this->violations($outcome, fn ($o) => $o === "403:{$code}"), "{$label}: ".json_encode(array_slice($this->violations($outcome, fn ($o) => $o === "403:{$code}"), 0, 5)));
        }
    }

    public function test_a_disabled_feature_closes_only_its_own_routes(): void
    {
        $routes = $this->routes();
        foreach (array_unique(array_column($routes, 'feature')) as $feature) {
            DB::table('tenant_feature_entitlements')->where('tenant_id', $this->alpha->id)
                ->where('feature_id', DB::table('features')->where('code', $feature)->value('id'))->update(['state' => 'DISABLED']);
            app(AccessCache::class)->touchTenant($this->alpha->id);

            $outcome = $this->sweep($this->admin, array_values(array_filter($routes, fn ($r) => $r['mutates'] === false)));
            foreach ($outcome as $label => $result) {
                $own = collect($routes)->first(fn ($r) => $label === $r['method'].' '.substr($r['uri'], strlen(self::PREFIX)))['feature'] === $feature;
                $own ? $this->assertSame('403:FEATURE_NOT_ENTITLED', $result, "{$feature} disabled: {$label}")
                    : $this->assertStringStartsNotWith('403', $result, "{$feature} disabled must not close {$label}");
            }

            DB::table('tenant_feature_entitlements')->where('tenant_id', $this->alpha->id)
                ->where('feature_id', DB::table('features')->where('code', $feature)->value('id'))->update(['state' => 'ACTIVE']);
            app(AccessCache::class)->touchTenant($this->alpha->id);
        }
    }

    public function test_a_membership_or_user_that_is_no_longer_active_loses_every_route_at_once(): void
    {
        [$user, $membership] = $this->member($this->alpha);
        $token = $this->tenantToken($user, $this->alpha);
        $this->assertSame([], $this->violations($this->sweep($token, array_slice($this->routes(), 0, 3)), fn ($o) => ! str_starts_with($o, '403')));

        DB::table('tenant_users')->where('id', $membership->id)->update(['status' => 'SUSPENDED']);
        app(AccessCache::class)->touchTenant($this->alpha->id);

        $outcome = $this->sweep($token, array_values(array_filter($this->routes(), fn ($r) => ! $r['bound'])));
        $this->assertSame([], $this->violations($outcome, fn ($o) => str_starts_with($o, '403:') || $o === '401'), json_encode($this->violations($outcome, fn ($o) => str_starts_with($o, '403:') || $o === '401')));
    }
}
