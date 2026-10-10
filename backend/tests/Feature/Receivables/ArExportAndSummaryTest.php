<?php

namespace Tests\Feature\Receivables;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Feature;
use App\Domain\Receivables\Services\ArSubledgerService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\TestCase;

/** OA3 batch H/I: CSV exports and the operational-summary receivables sections; same permission, entitlement, tenant and data scope as the screens. */
class ArExportAndSummaryTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, ReceivablesFixtures;

    private const SUMMARY = self::AR.'/operational-summary';

    private Tenant $tenant;

    private string $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->receivablesTenant('alpha', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false]);
        $this->bank = $this->cashAccount($this->tenant, 'BCA', 'BANK', '1120', ['account_number' => '1234567890123'])->id;
        $this->signedIn($this->tenant);
    }

    private function day(int $days = 0): string
    {
        return Carbon::parse($this->inTenant($this->tenant, fn () => app(ArSubledgerService::class)->today()))->addDays($days)->toDateString();
    }

    /** A posted invoice with a custom due date through the CUSTOM payment term. */
    private function dueInvoice(object $customer, string $amount, string $due, array $override = []): array
    {
        $term = (string) DB::table('payment_terms')->where('tenant_id', $this->tenant->id)->where('code', 'CUSTOM')->value('id');

        return $this->postedArInvoice($customer, ['lines' => [['description' => 'Jasa', 'amount' => $amount]], 'payment_term_id' => $term, 'due_date' => $due, 'document_date' => '2026-03-05', 'posting_date' => '2026-03-05'] + $override);
    }

    /** One of everything, posted: a part-paid invoice, its receipt, a credit note, plus a customer whose name is a spreadsheet formula. */
    private function world(): array
    {
        $customer = $this->customer($this->tenant, 'C-1', ['name' => 'Pelanggan Satu']);
        $this->customer($this->tenant, 'C-2', ['name' => '=HYPERLINK("http://x")']);
        $invoice = $this->postedArInvoice($customer, ['customer_reference' => 'PO-EXPORT-1']);
        $receipt = $this->postedReceipt($customer, $this->bank, [$invoice['id'] => '400000'], ['reference' => 'TRF-EXPORT-1']);
        $note = $this->postedCreditNote($invoice['id']);

        return [$customer, $invoice, $receipt, $note];
    }

    /** @return array<string,string> label => URL, every export of the module */
    private function exports(): array
    {
        return [
            'customers' => self::AR.'/customers/export', 'invoices' => self::AR.'/ar-invoices/export', 'receipts' => self::AR.'/customer-receipts/export',
            'credit notes' => self::AR.'/ar-credit-notes/export', 'aging' => self::AR.'/ar-aging/export', 'reconciliation' => self::AR.'/reconciliation/ar/export',
        ];
    }

    private function csv(string $url): string
    {
        return $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
    }

    // ------------------------------------------------------------------------------------------ exports

    public function test_every_list_and_the_reconciliation_can_be_exported_with_its_figures(): void
    {
        $this->world();

        $this->assertStringContainsString('Pelanggan Satu', $this->csv(self::AR.'/customers/export'));
        $invoices = $this->csv(self::AR.'/ar-invoices/export');
        $this->assertStringContainsString('PO-EXPORT-1', $invoices);
        $this->assertStringContainsString('1000000.0000', $invoices); // total
        $this->assertStringContainsString('500000.0000', $invoices); // outstanding after the 400.000 receipt and the 100.000 credit note
        $this->assertStringContainsString('PARTIALLY_PAID', $invoices);
        $receipts = $this->csv(self::AR.'/customer-receipts/export');
        $this->assertStringContainsString('TRF-EXPORT-1', $receipts);
        $this->assertStringContainsString('400000.0000', $receipts);
        $notes = $this->csv(self::AR.'/ar-credit-notes/export');
        $this->assertStringContainsString('Retur sebagian', $notes);
        $this->assertStringContainsString('100000.0000', $notes);
        $reconciliation = $this->csv(self::AR.'/reconciliation/ar/export?as_of=2026-03-31');
        $this->assertStringContainsString('MATCHED', $reconciliation);
        $this->assertStringContainsString('500000.0000', $reconciliation);
        $this->assertStringContainsString('C-1', $this->csv(self::AR.'/ar-aging/export?as_of=2026-03-31'));
    }

    public function test_filters_of_the_list_apply_to_its_export_and_formulas_and_bank_numbers_are_neutralised(): void
    {
        $this->world();
        $customers = $this->csv(self::AR.'/customers/export');
        $this->assertStringContainsString("'=HYPERLINK", $customers);
        $this->assertStringNotContainsString("\n=HYPERLINK", $customers);
        $inactive = $this->csv(self::AR.'/customers/export?status=INACTIVE');
        $this->assertStringNotContainsString('C-1', $inactive);
        $this->assertSame(1, count(array_filter(explode("\n", trim($inactive)))), 'only the header row');
        $this->assertStringNotContainsString('PO-EXPORT-1', $this->csv(self::AR.'/ar-invoices/export?payment_status=PAID'));
        $this->assertStringContainsString('PO-EXPORT-1', $this->csv(self::AR.'/ar-invoices/export?open=1'));
        $this->assertStringNotContainsString('TRF-EXPORT-1', $this->csv(self::AR.'/customer-receipts/export?status=DRAFT'));
        $this->get(self::AR.'/ar-invoices/export?status=NOPE')->assertStatus(422)->assertJsonValidationErrors('status'); // validated like the list

        foreach ($this->exports() as $label => $url) {
            $this->assertStringNotContainsString('1234567890123', $this->csv($url), "{$label} exposes the bank number");
        }
    }

    public function test_every_export_needs_the_export_permission_and_nothing_else_and_is_audited(): void
    {
        $this->world();
        $viewer = $this->memberToken($this->tenant, [
            'accounting.customer.view', 'accounting.ar_invoice.view', 'accounting.ar_receipt.view', 'accounting.ar_credit_note.view', 'accounting.ar_aging.view', 'accounting.reconciliation.ar.view',
        ]);
        $exporter = $this->memberToken($this->tenant, ['accounting.report.export']);
        $before = DB::table('audit_logs')->where('action', 'receivables.report.exported')->count();
        foreach ($this->exports() as $url) {
            $this->as($viewer)->get($url)->assertStatus(403);
            $this->as($exporter)->get($url)->assertOk();
        }
        $this->assertSame($before + count($this->exports()), DB::table('audit_logs')->where('action', 'receivables.report.exported')->count());
        $reports = DB::table('audit_logs')->where('action', 'receivables.report.exported')->pluck('resource_id')->all();
        foreach (['customers', 'ar_invoices', 'customer_receipts', 'ar_credit_notes', 'ar_aging', 'ar_reconciliation'] as $name) {
            $this->assertContains($name, $reports);
        }
        $log = DB::table('audit_logs')->where('resource_id', 'ar_invoices')->where('action', 'receivables.report.exported')->first();
        $this->assertSame(1, json_decode($log->changes, true)['after']['rows']);
        $this->assertSame($this->tenant->id, $log->tenant_id);
    }

    public function test_an_export_never_shows_another_tenant_or_a_row_outside_the_data_scope(): void
    {
        $this->world();
        $other = $this->receivablesTenant('bravo', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false]);
        $this->signedIn($other);
        $theirs = $this->customer($other, 'B-ONLY', ['name' => 'Pelanggan Bravo']);
        $this->postedArInvoice($theirs, ['customer_reference' => 'B-INV-1']);

        $this->signedIn($this->tenant);
        foreach (['customers', 'ar-invoices', 'customer-receipts', 'ar-credit-notes'] as $path) {
            $csv = $this->csv(self::AR."/{$path}/export");
            $this->assertStringNotContainsString('B-ONLY', $csv);
            $this->assertStringNotContainsString('B-INV-1', $csv);
        }
        $this->assertStringNotContainsString('B-ONLY', $this->csv(self::AR.'/ar-aging/export'));
        $this->assertStringNotContainsString('B-ONLY', $this->csv(self::AR.'/reconciliation/ar/export'));

        // Data scope: a branch user exports only what the list shows them.
        [$north, $south] = $this->branches($this->tenant, 'N', 'S');
        $customer = $this->customer($this->tenant, 'C-3');
        $this->signedIn($this->tenant);
        $this->postedArInvoice($customer, ['branch_id' => $north, 'customer_reference' => 'PO-NORTH']);
        $this->postedArInvoice($customer, ['branch_id' => $south, 'customer_reference' => 'PO-SOUTH']);
        $scoped = $this->as($this->scopedToken($this->tenant, ['accounting.report.export'], 'BRANCH', $north));
        $csv = $scoped->get(self::AR.'/ar-invoices/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('PO-NORTH', $csv);
        $this->assertStringNotContainsString('PO-SOUTH', $csv);
        $this->assertStringNotContainsString('PO-EXPORT-1', $csv); // tenant-level documents (no branch) are outside a branch scope
        $aging = $scoped->get(self::AR.'/ar-aging/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('PO-NORTH', $aging);
        $this->assertStringNotContainsString('PO-SOUTH', $aging);
    }

    public function test_an_export_is_capped_and_respects_module_and_feature_state(): void
    {
        $this->world();
        config(['optiaccounting.export_max_rows' => 1]);
        $this->get(self::AR.'/customers/export')->assertStatus(422)->assertJsonPath('code', 'EXPORT_TOO_LARGE')->assertJsonPath('details.rows', 2)->assertJsonPath('details.max_rows', 1);
        $this->csv(self::AR.'/customers/export?q=C-1'); // narrowed filters fit
        config(['optiaccounting.export_max_rows' => 10000]);

        // READ_ONLY keeps reading (an export is a read); a lost module or a disabled feature closes it.
        $module = fn (string $code, string $state) => DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('module_id', DB::table('modules')->where('code', $code)->value('id'))->update(['state' => $state]);
        $module('ACCOUNTING_AR', 'READ_ONLY');
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->csv(self::AR.'/ar-invoices/export');
        $module('ACCOUNTING_AR', 'DISABLED');
        app(AccessCache::class)->touchTenant($this->tenant->id);
        foreach ($this->exports() as $url) {
            $this->get($url)->assertStatus(403);
        }
        $module('ACCOUNTING_AR', 'ACTIVE');
        DB::table('tenant_feature_entitlements')->where('tenant_id', $this->tenant->id)->where('feature_id', Feature::query()->where('code', 'AR_RECEIPT')->value('id'))->update(['state' => 'DISABLED']);
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->get(self::AR.'/customer-receipts/export')->assertStatus(403)->assertJsonPath('code', 'FEATURE_NOT_ENTITLED');
        $this->csv(self::AR.'/ar-invoices/export');
    }

    // ------------------------------------------------------------------------------------------ operational summary

    public function test_receivables_figures_come_from_posted_documents_and_the_ledger(): void
    {
        $customer = $this->customer($this->tenant, 'C1');
        $this->signedIn($this->tenant);
        $overdue = $this->dueInvoice($customer, '200000', $this->day(-10));
        $this->dueInvoice($customer, '100000', $this->day(5)); // due in 5 days
        $this->dueInvoice($customer, '500000', $this->day(60)); // open, not near
        $paid = $this->dueInvoice($customer, '700000', $this->day(-30));
        $this->postedReceipt($customer, $this->bank, [$paid['id'] => '700000', $overdue['id'] => '50000']);
        $this->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer))->assertCreated(); // a draft counts nowhere
        $submitted = $this->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer))->assertCreated()->json('id');
        $this->postJson(self::AR."/ar-invoices/{$submitted}/submit")->assertOk();
        $approved = $this->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer))->assertCreated()->json('id');
        $this->postJson(self::AR."/ar-invoices/{$approved}/submit")->assertOk();
        $this->postJson(self::AR."/ar-invoices/{$approved}/approve")->assertOk();
        $waiting = $this->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $this->bank, [$overdue['id'] => '10000']))->assertCreated()->json('id');
        $this->postJson(self::AR."/customer-receipts/{$waiting}/submit")->assertOk();
        $note = $this->postJson(self::AR.'/ar-credit-notes', $this->creditNoteBody($overdue['id']))->assertCreated()->json('id');
        $this->postJson(self::AR."/ar-credit-notes/{$note}/submit")->assertOk();
        $this->postJson(self::AR."/ar-credit-notes/{$note}/approve")->assertOk();

        $summary = $this->getJson(self::SUMMARY)->assertOk()->json();
        // Outstanding: 150.000 (overdue invoice after its 50.000 receipt) + 100.000 + 500.000; the settled invoice and every unposted one are out.
        $this->assertSame(['750000.0000', 3], [$summary['receivables']['outstanding']['amount'], $summary['receivables']['outstanding']['invoices']]);
        $this->assertSame(['150000.0000', 1], [$summary['receivables']['overdue']['amount'], $summary['receivables']['overdue']['invoices']]);
        $this->assertSame([7, '100000.0000', 1], [$summary['receivables']['due_soon']['days'], $summary['receivables']['due_soon']['amount'], $summary['receivables']['due_soon']['invoices']]);
        $this->assertSame([1, 1], [$summary['receivables']['pending_approval'], $summary['receivables']['awaiting_posting']]);
        $this->assertSame(['pending_approval' => 1, 'awaiting_posting' => 0], $summary['receipts']);
        $this->assertSame(['pending_approval' => 0, 'awaiting_posting' => 1], $summary['credit_notes']);
        $this->assertTrue($summary['complete']);

        // Posting the approved credit note takes it out of the outstanding; the figures follow the ledger, not a cache.
        $this->postJson(self::AR."/ar-credit-notes/{$note}/post")->assertOk();
        $after = $this->getJson(self::SUMMARY)->assertOk()->json();
        $this->assertSame('650000.0000', $after['receivables']['outstanding']['amount']);
        $this->assertSame('50000.0000', $after['receivables']['overdue']['amount']);
        $this->assertSame(['pending_approval' => 0, 'awaiting_posting' => 0], $after['credit_notes']);
    }

    public function test_a_receivables_section_appears_only_when_the_user_may_open_its_list(): void
    {
        $customer = $this->customer($this->tenant, 'C1');
        $this->signedIn($this->tenant);
        $this->dueInvoice($customer, '100000', $this->day(5));

        $keys = ['receivables', 'receipts', 'credit_notes'];
        $sections = fn (string $token) => collect($this->as($token)->getJson(self::SUMMARY)->assertOk()->json())->only($keys)->map(fn ($s) => $s !== null)->all();
        $none = array_fill_keys($keys, false);
        $this->assertEquals($none, $sections($this->memberToken($this->tenant, ['accounting.journal.view'])));
        $this->assertEquals(['receivables' => true] + $none, $sections($this->memberToken($this->tenant, ['accounting.journal.view', 'accounting.ar_invoice.view'])));
        $this->assertEquals(['receipts' => true] + $none, $sections($this->memberToken($this->tenant, ['accounting.journal.view', 'accounting.ar_receipt.view'])));
        $this->assertEquals(['credit_notes' => true] + $none, $sections($this->memberToken($this->tenant, ['accounting.journal.view', 'accounting.ar_credit_note.view'])));

        // A module the tenant lost closes its sections even for a user who holds the permissions; READ_ONLY keeps showing the numbers.
        $all = $this->memberToken($this->tenant, null);
        $module = fn (string $state) => DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('module_id', DB::table('modules')->where('code', 'ACCOUNTING_AR')->value('id'))->update(['state' => $state]);
        $module('READ_ONLY');
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->assertSame('100000.0000', $this->as($all)->getJson(self::SUMMARY)->assertOk()->json('receivables.outstanding.amount'));
        $module('DISABLED');
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->assertEquals($none, $sections($all));
        $module('ACTIVE');
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->assertEquals(array_fill_keys($keys, true), $sections($all));
    }

    public function test_receivables_figures_are_scoped_to_the_data_scope_and_never_cross_tenants(): void
    {
        [$north, $south] = $this->branches($this->tenant, 'N', 'S');
        $customer = $this->customer($this->tenant, 'C1');
        $this->signedIn($this->tenant);
        $this->dueInvoice($customer, '100000', $this->day(5), ['branch_id' => $north]);
        $this->dueInvoice($customer, '900000', $this->day(5), ['branch_id' => $south]);

        $other = $this->receivablesTenant('bravo', ['sod_creator_not_approver' => false]);
        $this->signedIn($other);
        $this->postedArInvoice($this->customer($other, 'B1'), ['lines' => [['description' => 'x', 'amount' => '7777777']]]);

        $this->signedIn($this->tenant);
        $this->getJson(self::SUMMARY)->assertOk()->assertJsonPath('receivables.outstanding.amount', '1000000.0000')->assertJsonPath('complete', true);

        $scoped = $this->as($this->scopedToken($this->tenant, ['accounting.journal.view', 'accounting.ar_invoice.view'], 'BRANCH', $north));
        $scoped->getJson(self::SUMMARY)->assertOk()->assertJsonPath('receivables.outstanding.amount', '100000.0000')->assertJsonPath('receivables.outstanding.invoices', 1)->assertJsonPath('complete', false);
    }
}
