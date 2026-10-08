<?php

namespace Tests\Feature\Payables;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Payables\Models\Vendor;
use App\Domain\ProductCatalog\Models\Module;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/**
 * OA2 batch L: one sweep over EVERY route of the three OA2 modules (read from the router, so a route added later is covered without
 * touching this file) for permission, tenant ownership and entitlement state. The OA1 routes have their own matrix
 * (Accounting\AccessMatrixTest); the document-level rules of each module live in that module's feature tests.
 */
class OperationalAccessMatrixTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures;

    private const PREFIX = 'api/v1/app/accounting/';

    private const MODULES = ['ACCOUNTING_AP', 'ACCOUNTING_EXPENSE', 'ACCOUNTING_CASH_BANK'];

    private Tenant $alpha;

    private Tenant $bravo;

    private string $admin;

    /** @var array<string,string> route parameter name => id of a real alpha record */
    private array $ids;

    private string $receiptId;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->payablesTenant('alpha', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false, 'sod_approver_not_poster' => false]); // one administrator prepares, approves and posts
        $this->bravo = $this->payablesTenant('bravo');
        $this->admin = $this->memberToken($this->alpha);

        $vendor = $this->vendor = $this->vendor($this->alpha);
        $bank = $this->cashAccount($this->alpha);
        $category = $this->expenseCategory($this->alpha);
        $client = $this->as($this->admin);
        $invoice = $this->postedInvoice($vendor);
        $cash = fn (string $purpose, string $counter) => ['cash_bank_account_id' => $bank->id, 'counter_account_id' => $this->account($this->alpha, $counter)->id, 'amount' => '10000',
            'transaction_date' => '2026-03-05', 'purpose' => $purpose, 'description' => $purpose];
        $statement = $client->postJson(self::AP.'/bank-statements', ['cash_bank_account_id' => $bank->id, 'reference' => 'BCA-03', 'statement_date' => '2026-03-31', 'closing_balance' => '0',
            'items' => [['item_date' => '2026-03-05', 'description' => 'Biaya admin', 'amount' => '-6500']]])->assertCreated()->json();

        $this->receiptId = $client->postJson(self::AP.'/cash-receipts', $cash('Setoran', '3100'))->assertCreated()->json('id');
        $this->ids = [
            'vendor' => $vendor->id,
            'term' => (string) DB::table('payment_terms')->where('tenant_id', $this->alpha->id)->value('id'),
            'invoice' => $client->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor))->assertCreated()->json('id'),
            'payment' => $client->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '100000']))->assertCreated()->json('id'),
            'category' => $category->id,
            'expense' => $client->postJson(self::AP.'/expenses', $this->payableExpenseBody($category->id, $vendor))->assertCreated()->json('id'),
            'cashBankAccount' => $bank->id,
            'cashTransaction' => $client->postJson(self::AP.'/cash-payments', $cash('Biaya', '6900'))->assertCreated()->json('id'),
            'statement' => $statement['id'],
            'item' => $statement['items'][0]['id'],
        ];
    }

    /** @return list<array{method:string,uri:string,permission:string,module:string,feature:string,mutates:bool,bound:bool}> */
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
            if (! in_array($options['module'] ?? null, self::MODULES, true)) {
                continue; // the OA1 matrix covers ACCOUNTING_CORE
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

    private function label(array $route): string
    {
        return $route['method'].' '.substr($route['uri'], strlen(self::PREFIX));
    }

    private function hit(string $token, array $route, ?array $ids = null): TestResponse
    {
        $ids ??= $this->ids;
        if (str_starts_with(substr($route['uri'], strlen(self::PREFIX)), 'cash-receipts') && $ids['cashTransaction'] === $this->ids['cashTransaction']) {
            $ids['cashTransaction'] = $this->receiptId; // a payment id is not found under /cash-receipts
        }
        $uri = preg_replace_callback('/\{(\w+)\}/', fn ($m) => $ids[$m[1]] ?? '', $route['uri']);

        return $this->as($token)->json($route['method'], '/'.$uri, []);
    }

    /** Every route's outcome as "METHOD uri" => "status[:code]". @return array<string,string> */
    private function sweep(string $token, ?array $only = null, ?array $ids = null): array
    {
        $out = [];
        foreach ($only ?? $this->routes() as $route) {
            $response = $this->hit($token, $route, $ids);
            $this->assertLessThan(500, $response->getStatusCode(), $this->label($route).' answered with a server error: '.substr((string) $response->baseResponse instanceof StreamedResponse ? '' : $response->getContent(), 0, 300));
            $out[$this->label($route)] = $response->getStatusCode().(($code = $this->errorCode($response)) ? ":{$code}" : '');
        }

        return $out;
    }

    /** The machine-readable error code of a JSON error; exports are streamed CSV and have none. */
    private function errorCode(TestResponse $response): ?string
    {
        $content = $response->baseResponse instanceof StreamedResponse || $response->getStatusCode() < 400 ? false : $response->getContent();

        return is_string($content) && $content !== '' && $content[0] === '{' ? (json_decode($content, true)['code'] ?? null) : null;
    }

    private function entitle(string $module, array $attrs): void
    {
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->alpha->id)->where('module_id', Module::query()->where('code', $module)->value('id'))->update($attrs);
        app(AccessCache::class)->touchTenant($this->alpha->id);
    }

    private function subscription(array $attrs): void
    {
        DB::table('subscriptions')->where('tenant_id', $this->alpha->id)->update($attrs);
        app(AccessCache::class)->touchTenant($this->alpha->id);
    }

    private function featureState(string $feature, string $state): void
    {
        DB::table('tenant_feature_entitlements')->where('tenant_id', $this->alpha->id)->where('feature_id', DB::table('features')->where('code', $feature)->value('id'))->update(['state' => $state]);
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

    /** @return list<array> the routes of the given modules */
    private function of(array $modules): array
    {
        return array_values(array_filter($this->routes(), fn ($r) => in_array($r['module'], $modules, true)));
    }

    private function passedTheGate(string $outcome): bool
    {
        return ! str_starts_with($outcome, '401') && ! str_starts_with($outcome, '403') && ! str_starts_with($outcome, '5');
    }

    // ------------------------------------------------------------------------------------------ structure

    public function test_every_route_names_an_accounting_permission_its_module_and_one_of_that_modules_features(): void
    {
        $routes = $this->routes();
        $features = DB::table('features as f')->join('modules as m', 'm.id', '=', 'f.module_id')->get(['m.code as module', 'f.code as feature'])->groupBy('module')->map(fn ($g) => $g->pluck('feature')->all());
        $catalog = DB::table('permissions')->where('scope', 'tenant')->pluck('code')->all();

        $this->assertGreaterThan(95, count($routes));
        $this->assertEqualsCanonicalizing(self::MODULES, array_values(array_unique(array_column($routes, 'module'))), 'every OA2 module has routes');
        foreach ($routes as $route) {
            $label = $this->label($route);
            $this->assertMatchesRegularExpression('/^accounting(\.[a-z_]+){2,3}$/', $route['permission'], $label);
            $this->assertContains($route['permission'], $catalog, "{$label} names a permission that is not in the catalog");
            $this->assertContains($route['feature'], $features[$route['module']] ?? [], "{$label}: {$route['feature']} is not a feature of {$route['module']}");
        }
    }

    // ------------------------------------------------------------------------------------------ permissions

    /** The authorization contract of OA2, written out: a new route or a changed permission is a conscious edit of this table. */
    public function test_the_permission_of_every_route_is_pinned(): void
    {
        $expected = [
            'DELETE bank-statements/{statement}' => 'accounting.bank_reconciliation.manage',
            'DELETE bank-statements/{statement}/items/{item}' => 'accounting.bank_reconciliation.manage',
            'DELETE cash-bank-accounts/{cashBankAccount}' => 'accounting.cash_bank.manage',
            'DELETE expense-categories/{category}' => 'accounting.expense_category.manage',
            'DELETE payment-terms/{term}' => 'accounting.vendor.manage',
            'DELETE vendors/{vendor}' => 'accounting.vendor.manage',
            'GET ap-aging' => 'accounting.ap_aging.view',
            'GET ap-aging/export' => 'accounting.report.export',
            'GET ap-invoices' => 'accounting.ap_invoice.view',
            'GET ap-invoices/check-duplicate' => 'accounting.ap_invoice.view',
            'GET ap-invoices/export' => 'accounting.report.export',
            'GET ap-invoices/{invoice}' => 'accounting.ap_invoice.view',
            'GET bank-statements' => 'accounting.bank_reconciliation.view',
            'GET bank-statements/{statement}' => 'accounting.bank_reconciliation.view',
            'GET bank-statements/{statement}/export' => 'accounting.report.export',
            'GET bank-statements/{statement}/items/{item}/candidates' => 'accounting.bank_reconciliation.view',
            'GET cash-bank-accounts' => 'accounting.cash_bank.view',
            'GET cash-bank-accounts/{cashBankAccount}' => 'accounting.cash_bank.view',
            'GET cash-bank-accounts/{cashBankAccount}/transactions' => 'accounting.cash_bank.view',
            'GET cash-bank-accounts/{cashBankAccount}/transactions/export' => 'accounting.report.export',
            'GET cash-payments' => 'accounting.cash_transaction.view',
            'GET cash-payments/export' => 'accounting.report.export',
            'GET cash-payments/{cashTransaction}' => 'accounting.cash_transaction.view',
            'GET cash-receipts' => 'accounting.cash_transaction.view',
            'GET cash-receipts/export' => 'accounting.report.export',
            'GET cash-receipts/{cashTransaction}' => 'accounting.cash_transaction.view',
            'GET expense-categories' => 'accounting.expense.view',
            'GET expenses' => 'accounting.expense.view',
            'GET expenses/export' => 'accounting.report.export',
            'GET expenses/{expense}' => 'accounting.expense.view',
            'GET payment-terms' => 'accounting.vendor.view',
            'GET reconciliation/ap' => 'accounting.reconciliation.ap.view',
            'GET reconciliation/ap/export' => 'accounting.report.export',
            'GET reconciliation/cash-bank' => 'accounting.reconciliation.cash_bank.view',
            'GET reconciliation/cash-bank/export' => 'accounting.report.export',
            'GET vendor-payments' => 'accounting.ap_payment.view',
            'GET vendor-payments/export' => 'accounting.report.export',
            'GET vendor-payments/{payment}' => 'accounting.ap_payment.view',
            'GET vendors' => 'accounting.vendor.view',
            'GET vendors/export' => 'accounting.report.export',
            'GET vendors/{vendor}' => 'accounting.vendor.view',
            'GET vendors/{vendor}/allocation-suggestion' => 'accounting.ap_payment.create',
            'GET vendors/{vendor}/open-invoices' => 'accounting.ap_payment.view',
            'PATCH ap-invoices/{invoice}' => 'accounting.ap_invoice.update',
            'PATCH bank-statements/{statement}' => 'accounting.bank_reconciliation.manage',
            'PATCH bank-statements/{statement}/items/{item}' => 'accounting.bank_reconciliation.manage',
            'PATCH cash-bank-accounts/{cashBankAccount}' => 'accounting.cash_bank.manage',
            'PATCH cash-payments/{cashTransaction}' => 'accounting.cash_transaction.create',
            'PATCH cash-receipts/{cashTransaction}' => 'accounting.cash_transaction.create',
            'PATCH expense-categories/{category}' => 'accounting.expense_category.manage',
            'PATCH expenses/{expense}' => 'accounting.expense.update',
            'PATCH payment-terms/{term}' => 'accounting.vendor.manage',
            'PATCH vendor-payments/{payment}' => 'accounting.ap_payment.create',
            'PATCH vendors/{vendor}' => 'accounting.vendor.manage',
            'POST ap-invoices' => 'accounting.ap_invoice.create',
            'POST ap-invoices/{invoice}/approve' => 'accounting.ap_invoice.approve',
            'POST ap-invoices/{invoice}/cancel' => 'accounting.ap_invoice.update',
            'POST ap-invoices/{invoice}/post' => 'accounting.ap_invoice.post',
            'POST ap-invoices/{invoice}/reject' => 'accounting.ap_invoice.approve',
            'POST ap-invoices/{invoice}/reopen' => 'accounting.ap_invoice.update',
            'POST ap-invoices/{invoice}/reverse' => 'accounting.ap_invoice.reverse',
            'POST ap-invoices/{invoice}/submit' => 'accounting.ap_invoice.submit',
            'POST bank-statements' => 'accounting.bank_reconciliation.manage',
            'POST bank-statements/{statement}/complete' => 'accounting.bank_reconciliation.manage',
            'POST bank-statements/{statement}/items' => 'accounting.bank_reconciliation.manage',
            'POST bank-statements/{statement}/items/{item}/exception' => 'accounting.bank_reconciliation.manage',
            'POST bank-statements/{statement}/items/{item}/match' => 'accounting.bank_reconciliation.manage',
            'POST bank-statements/{statement}/items/{item}/unmatch' => 'accounting.bank_reconciliation.manage',
            'POST cash-bank-accounts' => 'accounting.cash_bank.manage',
            'POST cash-bank-accounts/{cashBankAccount}/status' => 'accounting.cash_bank.manage',
            'POST cash-payments' => 'accounting.cash_transaction.create',
            'POST cash-payments/{cashTransaction}/cancel' => 'accounting.cash_transaction.create',
            'POST cash-payments/{cashTransaction}/post' => 'accounting.cash_transaction.post',
            'POST cash-payments/{cashTransaction}/reverse' => 'accounting.cash_transaction.reverse',
            'POST cash-receipts' => 'accounting.cash_transaction.create',
            'POST cash-receipts/{cashTransaction}/cancel' => 'accounting.cash_transaction.create',
            'POST cash-receipts/{cashTransaction}/post' => 'accounting.cash_transaction.post',
            'POST cash-receipts/{cashTransaction}/reverse' => 'accounting.cash_transaction.reverse',
            'POST expense-categories' => 'accounting.expense_category.manage',
            'POST expense-categories/defaults' => 'accounting.expense_category.manage',
            'POST expense-categories/{category}/status' => 'accounting.expense_category.manage',
            'POST expenses' => 'accounting.expense.create',
            'POST expenses/{expense}/approve' => 'accounting.expense.approve',
            'POST expenses/{expense}/cancel' => 'accounting.expense.update',
            'POST expenses/{expense}/post' => 'accounting.expense.post',
            'POST expenses/{expense}/reject' => 'accounting.expense.approve',
            'POST expenses/{expense}/reopen' => 'accounting.expense.update',
            'POST expenses/{expense}/reverse' => 'accounting.expense.reverse',
            'POST expenses/{expense}/submit' => 'accounting.expense.submit',
            'POST payment-terms' => 'accounting.vendor.manage',
            'POST payment-terms/defaults' => 'accounting.vendor.manage',
            'POST payment-terms/{term}/status' => 'accounting.vendor.manage',
            'POST vendor-payments' => 'accounting.ap_payment.create',
            'POST vendor-payments/{payment}/approve' => 'accounting.ap_payment.approve',
            'POST vendor-payments/{payment}/cancel' => 'accounting.ap_payment.create',
            'POST vendor-payments/{payment}/post' => 'accounting.ap_payment.post',
            'POST vendor-payments/{payment}/reject' => 'accounting.ap_payment.approve',
            'POST vendor-payments/{payment}/reopen' => 'accounting.ap_payment.create',
            'POST vendor-payments/{payment}/reverse' => 'accounting.ap_payment.reverse',
            'POST vendor-payments/{payment}/submit' => 'accounting.ap_payment.submit',
            'POST vendors' => 'accounting.vendor.manage',
            'POST vendors/{vendor}/status' => 'accounting.vendor.manage',
        ];

        $actual = [];
        foreach ($this->routes() as $route) {
            $actual[$this->label($route)] = $route['permission'];
        }
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
        $this->assertGreaterThan(25, count($byPermission));

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
        $this->assertGreaterThan(60, count($bound));
        $before = $this->fingerprint();

        $outcome = $this->sweep($intruder, $bound);

        $this->assertSame([], $this->violations($outcome, fn ($o) => $o === '404'), json_encode($this->violations($outcome, fn ($o) => $o === '404')));
        $this->assertSame($before, $this->fingerprint(), 'a cross-tenant call left a trace in the other tenant');
    }

    /** @return array<string,string> table => hash of every alpha row */
    private function fingerprint(): array
    {
        $out = [];
        foreach ([
            'payment_terms', 'vendors', 'ap_invoices', 'ap_invoice_lines', 'vendor_payments', 'ap_payment_allocations', 'expense_categories', 'expenses', 'cash_bank_accounts',
            'cash_transactions', 'bank_statements', 'bank_statement_items', 'journal_entries', 'journal_lines', 'audit_logs', 'document_sequences',
        ] as $table) {
            $out[$table] = md5(DB::table($table)->where('tenant_id', $this->alpha->id)->orderBy('id')->get()->toJson());
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------ entitlement

    public function test_a_read_only_module_keeps_its_reads_open_and_refuses_its_mutations_without_touching_the_other_modules(): void
    {
        foreach (self::MODULES as $module) {
            $this->entitle($module, ['state' => 'READ_ONLY']);
            $outcome = $this->sweep($this->admin);
            $own = array_map(fn ($r) => $this->label($r), $this->of([$module]));
            $mutating = array_map(fn ($r) => $this->label($r), array_filter($this->routes(), fn ($r) => $r['module'] === $module && $r['mutates']));

            $bad = $this->violations($outcome, function ($o, $label) use ($own, $mutating) {
                if (in_array($label, $mutating, true)) {
                    return $o === '403:MODULE_READ_ONLY';
                }

                return $this->passedTheGate($o) || ! in_array($label, $own, true) && $o !== '403:MODULE_READ_ONLY';
            });
            $this->assertSame([], $bad, "{$module} READ_ONLY: ".json_encode($bad));
            $this->entitle($module, ['state' => 'ACTIVE']);
        }
    }

    public function test_an_overdue_subscription_is_read_only_for_every_module_but_not_for_administration(): void
    {
        $this->subscription(['status' => 'PAST_DUE']);
        $outcome = $this->sweep($this->admin);

        $bad = $this->violations($outcome, fn ($o, $r) => str_starts_with($r, 'GET ') ? $this->passedTheGate($o) : $o === '403:SUBSCRIPTION_READ_ONLY');
        $this->assertSame([], $bad, json_encode($bad));
        $this->as($this->admin)->postJson('/api/v1/app/branches', ['code' => 'LATE', 'name' => 'Cabang'])->assertCreated();
    }

    public function test_every_state_that_removes_a_module_closes_all_of_its_routes_reads_included_and_only_those(): void
    {
        $today = $this->alpha->businessDate();
        $states = [
            'SUSPENDED' => ['state' => 'SUSPENDED'],
            'DISABLED' => ['state' => 'DISABLED'],
            'expired yesterday' => ['state' => 'ACTIVE', 'effective_from' => '2020-01-01', 'effective_until' => Carbon::parse($today)->subDay()->toDateString()],
            'starts tomorrow' => ['effective_from' => Carbon::parse($today)->addDay()->toDateString(), 'effective_until' => null],
        ];

        $baseline = $this->entitlementRows();
        foreach (self::MODULES as $module) {
            $own = array_map(fn ($r) => $this->label($r), $this->of([$module]));
            foreach ($states as $label => $attrs) {
                $this->restoreEntitlementRows($baseline);
                $this->entitle($module, $attrs);
                $outcome = $this->sweep($this->admin);

                $bad = $this->violations($outcome, fn ($o, $route) => in_array($route, $own, true) ? $o === '403:MODULE_NOT_ENTITLED' : $o !== '403:MODULE_NOT_ENTITLED');
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
        $this->assertGreaterThan(8, count(array_unique(array_column($routes, 'feature'))));

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

    // ------------------------------------------------------------------------------------------ the parent module

    /** Every document that posts or reverses a journal needs ACCOUNTING_CORE writable, whichever OA2 module it belongs to. */
    public function test_a_lost_or_read_only_ledger_stops_every_document_from_moving_toward_the_books(): void
    {
        $client = $this->as($this->admin);
        $vendor = $this->vendor;
        $bank = $this->ids['cashBankAccount'];
        $category = $this->ids['category'];
        $big = $this->postedInvoice($vendor, ['lines' => [['description' => 'Besar', 'amount' => '50000000']]]);

        // One document of each document type in each state a transition starts from.
        $invoices = $this->ladder('ap-invoices', fn () => $client->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor))->assertCreated()->json('id'));
        $payments = $this->ladder('vendor-payments', fn () => $client->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank, [$big['id'] => '100000']))->assertCreated()->json('id'));
        $expenses = $this->ladder('expenses', fn () => $client->postJson(self::AP.'/expenses', $this->paidExpenseBody($category, $bank))->assertCreated()->json('id'));
        $cashDraft = $this->ids['cashTransaction'];
        $cashPosted = $client->postJson(self::AP.'/cash-payments', ['cash_bank_account_id' => $bank, 'counter_account_id' => $this->account($this->alpha, '6900')->id, 'amount' => '10000',
            'transaction_date' => '2026-03-05', 'purpose' => 'Biaya', 'description' => 'Biaya'])->assertCreated()->json('id');
        $client->postJson(self::AP."/cash-payments/{$cashPosted}/post")->assertOk();

        $targets = [];
        foreach ([['ap-invoices', $invoices], ['vendor-payments', $payments], ['expenses', $expenses]] as [$base, $documents]) {
            foreach (['DRAFT' => 'submit', 'SUBMITTED' => 'approve', 'APPROVED' => 'post', 'POSTED' => 'reverse'] as $state => $action) {
                $targets["{$base} {$state} {$action}"] = self::AP."/{$base}/{$documents[$state]}/{$action}";
            }
        }
        $targets['cash-payments DRAFT post'] = self::AP."/cash-payments/{$cashDraft}/post";
        $targets['cash-payments POSTED reverse'] = self::AP."/cash-payments/{$cashPosted}/reverse";

        $statuses = fn () => md5(json_encode([
            DB::table('ap_invoices')->orderBy('id')->pluck('status', 'id'), DB::table('vendor_payments')->orderBy('id')->pluck('status', 'id'),
            DB::table('expenses')->orderBy('id')->pluck('status', 'id'), DB::table('cash_transactions')->orderBy('id')->pluck('status', 'id'),
        ]));
        $before = ['documents' => $statuses(), 'ledger' => $this->glFigures($this->alpha), 'journals' => DB::table('journal_entries')->count()];
        $today = $this->alpha->businessDate();

        $baseline = $this->entitlementRows();
        foreach ([
            'READ_ONLY' => ['state' => 'READ_ONLY'],
            'SUSPENDED' => ['state' => 'SUSPENDED'],
            'DISABLED' => ['state' => 'DISABLED'],
            'expired yesterday' => ['state' => 'ACTIVE', 'effective_from' => '2020-01-01', 'effective_until' => Carbon::parse($today)->subDay()->toDateString()],
        ] as $label => $attrs) {
            $this->restoreEntitlementRows($baseline);
            $this->entitle('ACCOUNTING_CORE', $attrs);
            foreach ($targets as $name => $uri) {
                $response = $client->postJson($uri, ['reason' => 'uji']);
                $this->assertSame(403, $response->getStatusCode(), "{$label}: {$name} -> ".$response->getContent());
                $this->assertSame(['MODULE_NOT_AVAILABLE', 'ACCOUNTING_CORE'], [$response->json('code'), $response->json('details.module')], "{$label}: {$name}");
            }
        }
        $this->restoreEntitlementRows($baseline);

        $this->assertSame($before['documents'], $statuses(), 'no document moved');
        $this->assertEquals($before['ledger'], $this->glFigures($this->alpha), 'the ledger is untouched');
        $this->assertSame($before['journals'], DB::table('journal_entries')->count(), 'no journal was created');

        // The same transitions work again once the ledger is back: the guard was the only thing in the way.
        $client->postJson(self::AP."/ap-invoices/{$invoices['APPROVED']}/post")->assertOk();
        $client->postJson(self::AP."/cash-payments/{$cashDraft}/post")->assertOk();
    }

    /** @return array<string,string> a document of this type in each state a transition starts from: DRAFT, SUBMITTED, APPROVED, POSTED */
    private function ladder(string $base, callable $create): array
    {
        $out = [];
        foreach (['DRAFT' => [], 'SUBMITTED' => ['submit'], 'APPROVED' => ['submit', 'approve'], 'POSTED' => ['submit', 'approve', 'post']] as $state => $steps) {
            $out[$state] = $create();
            foreach ($steps as $step) {
                $this->as($this->admin)->postJson(self::AP."/{$base}/{$out[$state]}/{$step}")->assertOk();
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------ catalog

    public function test_the_backfill_migration_gives_existing_tenants_the_new_features_once(): void
    {
        $new = ['VENDOR', 'AP_PAYMENT', 'CASH_BANK_ACCOUNT'];
        $featureIds = DB::table('features')->whereIn('code', $new)->pluck('id');
        $this->assertCount(3, $featureIds);
        $mine = fn () => DB::table('tenant_feature_entitlements')->where('tenant_id', $this->alpha->id)->whereIn('feature_id', $featureIds);
        $others = fn () => DB::table('tenant_feature_entitlements')->where('tenant_id', $this->bravo->id)->whereIn('feature_id', $featureIds)->orderBy('feature_id')->get(['feature_id', 'state', 'source', 'effective_from', 'effective_until'])->toJson();
        $this->assertSame(3, $mine()->count(), 'a tenant that holds the modules holds the new features');

        // An upgraded install: the rows do not exist yet for alpha; bravo already has them.
        $existing = $mine()->get();
        $mine()->delete();
        $bravoBefore = $others();
        $this->assertSame(0, $mine()->count());

        $migration = require base_path('database/migrations/2026_10_10_100003_backfill_oa2_feature_entitlements.php');
        $migration->up();

        $restored = $mine()->get(['feature_id', 'state', 'source', 'effective_from', 'effective_until'])->sortBy('feature_id')->values();
        $this->assertCount(3, $restored);
        $this->assertSame(['BUNDLE'], $restored->pluck('source')->unique()->values()->all(), 'a bundle-sourced entitlement, like the module it belongs to');
        $this->assertEquals($existing->sortBy('feature_id')->values()->map(fn ($r) => [$r->feature_id, $r->state, $r->effective_from, $r->effective_until])->all(), $restored->map(fn ($r) => [$r->feature_id, $r->state, $r->effective_from, $r->effective_until])->all());
        $this->assertSame($bravoBefore, $others(), 'rows that already exist are left alone');

        $migration->up(); // idempotent
        $this->assertSame(3, $mine()->count());
        $this->as($this->admin)->getJson(self::AP.'/vendors')->assertOk();
    }
}
