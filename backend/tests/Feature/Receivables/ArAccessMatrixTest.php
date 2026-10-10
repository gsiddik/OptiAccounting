<?php

namespace Tests\Feature\Receivables;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\Receivables\Models\Customer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\TestCase;

/**
 * OA3 batch H: one sweep over EVERY route of the ACCOUNTING_AR module (read from the router, so a route added later is covered without
 * touching this file) for permission, tenant ownership and entitlement state. The document-level rules live in the feature tests of the module.
 */
class ArAccessMatrixTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, ReceivablesFixtures;

    private const PREFIX = 'api/v1/app/accounting/';

    private const MODULES = ['ACCOUNTING_AR'];

    private Tenant $alpha;

    private Tenant $bravo;

    private string $admin;

    /** @var array<string,string> route parameter name => id of a real alpha record */
    private array $ids;

    private string $bankId;

    private string $postedInvoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->receivablesTenant('alpha', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false, 'sod_approver_not_poster' => false]); // one administrator prepares, approves and posts
        $this->bravo = $this->receivablesTenant('bravo');
        $this->admin = $this->memberToken($this->alpha);

        $customer = $this->customer($this->alpha);
        $this->bankId = $this->cashAccount($this->alpha)->id;
        $client = $this->as($this->admin);
        $posted = $this->postedArInvoice($customer);
        $this->postedInvoiceId = $posted['id'];

        $this->ids = [
            'customer' => $customer->id,
            'term' => (string) DB::table('payment_terms')->where('tenant_id', $this->alpha->id)->value('id'),
            'invoice' => $client->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer))->assertCreated()->json('id'),
            'receipt' => $client->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $this->bankId, [$posted['id'] => '100000']))->assertCreated()->json('id'),
            'note' => $client->postJson(self::AR.'/ar-credit-notes', $this->creditNoteBody($posted['id']))->assertCreated()->json('id'),
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

    private function label(array $route): string
    {
        return $route['method'].' '.substr($route['uri'], strlen(self::PREFIX));
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
            $this->assertLessThan(500, $response->getStatusCode(), $this->label($route).' answered with a server error: '.substr($response->baseResponse instanceof StreamedResponse ? '' : (string) $response->getContent(), 0, 300));
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

        $this->assertGreaterThan(50, count($routes));
        foreach ($routes as $route) {
            $label = $this->label($route);
            $this->assertMatchesRegularExpression('/^accounting(\.[a-z_]+){2,3}$/', $route['permission'], $label);
            $this->assertContains($route['permission'], $catalog, "{$label} names a permission that is not in the catalog");
            $this->assertContains($route['feature'], $features[$route['module']] ?? [], "{$label}: {$route['feature']} is not a feature of {$route['module']}");
        }
        $this->assertEqualsCanonicalizing(['CUSTOMER', 'CUSTOMER_INVOICE', 'AR_RECEIPT', 'CREDIT_NOTE', 'AR_AGING'], array_values(array_unique(array_column($routes, 'feature'))), 'every AR feature has routes');
    }

    // ------------------------------------------------------------------------------------------ permissions

    /** The authorization contract of OA3, written out: a new route or a changed permission is a conscious edit of this table. */
    public function test_the_permission_of_every_route_is_pinned(): void
    {
        $expected = [
            'DELETE ar-payment-terms/{term}' => 'accounting.customer.manage',
            'DELETE customers/{customer}' => 'accounting.customer.manage',
            'GET ar-aging' => 'accounting.ar_aging.view',
            'GET ar-aging/export' => 'accounting.report.export',
            'GET ar-credit-notes' => 'accounting.ar_credit_note.view',
            'GET ar-credit-notes/export' => 'accounting.report.export',
            'GET ar-credit-notes/{note}' => 'accounting.ar_credit_note.view',
            'GET ar-invoices' => 'accounting.ar_invoice.view',
            'GET ar-invoices/export' => 'accounting.report.export',
            'GET ar-invoices/{invoice}' => 'accounting.ar_invoice.view',
            'GET ar-payment-terms' => 'accounting.customer.view',
            'GET customer-receipts' => 'accounting.ar_receipt.view',
            'GET customer-receipts/export' => 'accounting.report.export',
            'GET customer-receipts/{receipt}' => 'accounting.ar_receipt.view',
            'GET customers' => 'accounting.customer.view',
            'GET customers/export' => 'accounting.report.export',
            'GET customers/{customer}' => 'accounting.customer.view',
            'GET customers/{customer}/allocation-suggestion' => 'accounting.ar_receipt.create',
            'GET customers/{customer}/open-invoices' => 'accounting.ar_receipt.view',
            'GET reconciliation/ar' => 'accounting.reconciliation.ar.view',
            'GET reconciliation/ar/export' => 'accounting.report.export',
            'PATCH ar-credit-notes/{note}' => 'accounting.ar_credit_note.create',
            'PATCH ar-invoices/{invoice}' => 'accounting.ar_invoice.update',
            'PATCH ar-payment-terms/{term}' => 'accounting.customer.manage',
            'PATCH customer-receipts/{receipt}' => 'accounting.ar_receipt.create',
            'PATCH customers/{customer}' => 'accounting.customer.manage',
            'POST ar-credit-notes' => 'accounting.ar_credit_note.create',
            'POST ar-credit-notes/{note}/approve' => 'accounting.ar_credit_note.approve',
            'POST ar-credit-notes/{note}/cancel' => 'accounting.ar_credit_note.create',
            'POST ar-credit-notes/{note}/post' => 'accounting.ar_credit_note.post',
            'POST ar-credit-notes/{note}/reject' => 'accounting.ar_credit_note.approve',
            'POST ar-credit-notes/{note}/reopen' => 'accounting.ar_credit_note.create',
            'POST ar-credit-notes/{note}/reverse' => 'accounting.ar_credit_note.reverse',
            'POST ar-credit-notes/{note}/submit' => 'accounting.ar_credit_note.submit',
            'POST ar-invoices' => 'accounting.ar_invoice.create',
            'POST ar-invoices/{invoice}/approve' => 'accounting.ar_invoice.approve',
            'POST ar-invoices/{invoice}/cancel' => 'accounting.ar_invoice.update',
            'POST ar-invoices/{invoice}/post' => 'accounting.ar_invoice.post',
            'POST ar-invoices/{invoice}/reject' => 'accounting.ar_invoice.approve',
            'POST ar-invoices/{invoice}/reopen' => 'accounting.ar_invoice.update',
            'POST ar-invoices/{invoice}/reverse' => 'accounting.ar_invoice.reverse',
            'POST ar-invoices/{invoice}/submit' => 'accounting.ar_invoice.submit',
            'POST ar-payment-terms' => 'accounting.customer.manage',
            'POST ar-payment-terms/defaults' => 'accounting.customer.manage',
            'POST ar-payment-terms/{term}/status' => 'accounting.customer.manage',
            'POST customer-receipts' => 'accounting.ar_receipt.create',
            'POST customer-receipts/{receipt}/approve' => 'accounting.ar_receipt.approve',
            'POST customer-receipts/{receipt}/cancel' => 'accounting.ar_receipt.create',
            'POST customer-receipts/{receipt}/post' => 'accounting.ar_receipt.post',
            'POST customer-receipts/{receipt}/reject' => 'accounting.ar_receipt.approve',
            'POST customer-receipts/{receipt}/reopen' => 'accounting.ar_receipt.create',
            'POST customer-receipts/{receipt}/reverse' => 'accounting.ar_receipt.reverse',
            'POST customer-receipts/{receipt}/submit' => 'accounting.ar_receipt.submit',
            'POST customers' => 'accounting.customer.manage',
            'POST customers/{customer}/status' => 'accounting.customer.manage',
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
        $this->assertGreaterThan(18, count($byPermission));

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
        $this->assertGreaterThan(30, count($bound));
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
            'payment_terms', 'customers', 'ar_invoices', 'ar_invoice_lines', 'customer_receipts', 'ar_receipt_allocations', 'ar_credit_notes', 'ar_credit_note_lines',
            'journal_entries', 'journal_lines', 'audit_logs', 'document_sequences',
        ] as $table) {
            $out[$table] = md5(DB::table($table)->where('tenant_id', $this->alpha->id)->orderBy('id')->get()->toJson());
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------ entitlement

    public function test_a_read_only_module_keeps_its_reads_open_and_refuses_its_mutations(): void
    {
        $this->entitle('ACCOUNTING_AR', ['state' => 'READ_ONLY']);
        $outcome = $this->sweep($this->admin);

        $bad = $this->violations($outcome, fn ($o, $label) => str_starts_with($label, 'GET ') ? $this->passedTheGate($o) : $o === '403:MODULE_READ_ONLY');
        $this->assertSame([], $bad, 'ACCOUNTING_AR READ_ONLY: '.json_encode($bad));
    }

    public function test_a_read_only_cash_bank_module_stops_a_receipt_from_moving_but_not_an_invoice_or_a_credit_note(): void
    {
        $client = $this->as($this->admin);
        $customer = $this->inTenant($this->alpha, fn () => Customer::query()->findOrFail($this->ids['customer']));
        $invoice = $client->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer))->assertCreated()->json('id');
        $client->postJson(self::AR."/ar-invoices/{$invoice}/submit")->assertOk();
        $note = $this->ids['note'];
        $submitted = $client->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $this->bankId, [$this->postedInvoiceId => '100000']))->assertCreated()->json('id');
        $client->postJson(self::AR."/customer-receipts/{$submitted}/submit")->assertOk();
        $approved = $client->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $this->bankId, [$this->postedInvoiceId => '100000']))->assertCreated()->json('id');
        $client->postJson(self::AR."/customer-receipts/{$approved}/submit")->assertOk();
        $client->postJson(self::AR."/customer-receipts/{$approved}/approve")->assertOk();

        $this->entitle('ACCOUNTING_CASH_BANK', ['state' => 'READ_ONLY']);
        $figures = $this->glFigures($this->alpha);

        foreach ([[$this->ids['receipt'], 'submit'], [$submitted, 'approve'], [$approved, 'post']] as [$id, $step]) {
            $response = $client->postJson(self::AR."/customer-receipts/{$id}/{$step}");
            $this->assertSame(403, $response->getStatusCode(), "receipt {$step}: ".$response->getContent());
            $this->assertSame(['MODULE_NOT_AVAILABLE', 'ACCOUNTING_CASH_BANK'], [$response->json('code'), $response->json('details.module')]);
        }
        $this->assertEquals($figures, $this->glFigures($this->alpha), 'nothing reached the ledger');
        $this->assertSame(['DRAFT', 'SUBMITTED', 'APPROVED'], DB::table('customer_receipts')->whereIn('id', [$this->ids['receipt'], $submitted, $approved])->orderByRaw("array_position(array['DRAFT','SUBMITTED','APPROVED'], status::text)")->pluck('status')->all());

        // The invoice and the credit note of the same module are not affected by a cash/bank module that is read-only.
        $client->postJson(self::AR."/ar-invoices/{$invoice}/approve")->assertOk();
        $client->postJson(self::AR."/ar-invoices/{$invoice}/post")->assertOk();
        $client->postJson(self::AR."/ar-credit-notes/{$note}/submit")->assertOk();
        $client->postJson(self::AR."/ar-credit-notes/{$note}/approve")->assertOk();
        $client->postJson(self::AR."/ar-credit-notes/{$note}/post")->assertOk();

        $this->entitle('ACCOUNTING_CASH_BANK', ['state' => 'ACTIVE']);
        $client->postJson(self::AR."/customer-receipts/{$this->ids['receipt']}/submit")->assertOk();
        $client->postJson(self::AR."/customer-receipts/{$approved}/post")->assertOk();
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
        foreach ($states as $label => $attrs) {
            $this->restoreEntitlementRows($baseline);
            $this->entitle('ACCOUNTING_AR', $attrs);
            $outcome = $this->sweep($this->admin);

            $bad = $this->violations($outcome, fn ($o) => $o === '403:MODULE_NOT_ENTITLED');
            $this->assertSame([], $bad, "ACCOUNTING_AR {$label}: ".json_encode(array_slice($bad, 0, 5)));
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
        $this->assertCount(5, array_unique(array_column($routes, 'feature')));

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

    /** Every document that posts or reverses a journal needs ACCOUNTING_CORE writable, whichever module it belongs to. */
    public function test_a_lost_or_read_only_ledger_stops_every_receivables_document_from_moving_toward_the_books(): void
    {
        $client = $this->as($this->admin);
        $customer = $this->inTenant($this->alpha, fn () => Customer::query()->findOrFail($this->ids['customer']));
        $big = $this->arInvoice($customer, '50000000');

        $invoices = $this->ladder('ar-invoices', fn () => $client->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer))->assertCreated()->json('id'));
        $receipts = $this->ladder('customer-receipts', fn () => $client->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $this->bankId, [$big['id'] => '100000']))->assertCreated()->json('id'));
        $notes = $this->ladder('ar-credit-notes', fn () => $client->postJson(self::AR.'/ar-credit-notes', $this->creditNoteBody($big['id']))->assertCreated()->json('id'));

        $targets = [];
        foreach ([['ar-invoices', $invoices], ['customer-receipts', $receipts], ['ar-credit-notes', $notes]] as [$base, $documents]) {
            foreach (['DRAFT' => 'submit', 'SUBMITTED' => 'approve', 'APPROVED' => 'post', 'POSTED' => 'reverse'] as $state => $action) {
                $targets["{$base} {$state} {$action}"] = self::AR."/{$base}/{$documents[$state]}/{$action}";
            }
        }

        $statuses = fn () => md5(json_encode([
            DB::table('ar_invoices')->orderBy('id')->pluck('status', 'id'), DB::table('customer_receipts')->orderBy('id')->pluck('status', 'id'),
            DB::table('ar_credit_notes')->orderBy('id')->pluck('status', 'id'),
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
        $client->postJson(self::AR."/ar-invoices/{$invoices['APPROVED']}/post")->assertOk();
        $client->postJson(self::AR."/customer-receipts/{$receipts['APPROVED']}/post")->assertOk();
    }

    /** @return array<string,string> a document of this type in each state a transition starts from: DRAFT, SUBMITTED, APPROVED, POSTED */
    private function ladder(string $base, callable $create): array
    {
        $out = [];
        foreach (['DRAFT' => [], 'SUBMITTED' => ['submit'], 'APPROVED' => ['submit', 'approve'], 'POSTED' => ['submit', 'approve', 'post']] as $state => $steps) {
            $out[$state] = $create();
            foreach ($steps as $step) {
                $this->as($this->admin)->postJson(self::AR."/{$base}/{$out[$state]}/{$step}")->assertOk();
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------ catalog

    public function test_the_backfill_migration_gives_existing_tenants_the_new_features_once(): void
    {
        $new = ['CUSTOMER', 'AR_RECEIPT'];
        $featureIds = DB::table('features')->whereIn('code', $new)->pluck('id');
        $this->assertCount(2, $featureIds);
        $mine = fn () => DB::table('tenant_feature_entitlements')->where('tenant_id', $this->alpha->id)->whereIn('feature_id', $featureIds);
        $others = fn () => DB::table('tenant_feature_entitlements')->where('tenant_id', $this->bravo->id)->whereIn('feature_id', $featureIds)->orderBy('feature_id')->get(['feature_id', 'state', 'source', 'effective_from', 'effective_until'])->toJson();
        $this->assertSame(2, $mine()->count(), 'a tenant that holds the module holds the new features');

        // An upgraded install: the rows do not exist yet for alpha; bravo already has them.
        $existing = $mine()->get();
        $mine()->delete();
        $bravoBefore = $others();
        $this->assertSame(0, $mine()->count());

        $migration = require base_path('database/migrations/2026_10_11_100002_prepare_oa3_catalog.php');
        $migration->up();

        $restored = $mine()->get(['feature_id', 'state', 'source', 'effective_from', 'effective_until'])->sortBy('feature_id')->values();
        $this->assertCount(2, $restored);
        $this->assertSame(['BUNDLE'], $restored->pluck('source')->unique()->values()->all(), 'a bundle-sourced entitlement, like the module it belongs to');
        $this->assertEquals($existing->sortBy('feature_id')->values()->map(fn ($r) => [$r->feature_id, $r->state, $r->effective_from, $r->effective_until])->all(), $restored->map(fn ($r) => [$r->feature_id, $r->state, $r->effective_from, $r->effective_until])->all());
        $this->assertSame($bravoBefore, $others(), 'rows that already exist are left alone');

        $migration->up(); // idempotent
        $this->assertSame(2, $mine()->count());
        $this->as($this->admin)->getJson(self::AR.'/customers')->assertOk();
    }
}
