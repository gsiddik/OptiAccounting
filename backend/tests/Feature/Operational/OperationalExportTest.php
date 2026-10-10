<?php

namespace Tests\Feature\Operational;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Feature;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA2 batch I: CSV exports of the lists and reconciliations; same permission, entitlement, tenant and data scope as the screens. */
class OperationalExportTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    private string $bank;

    private array $statement;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->payablesTenant('alpha', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false]);
        $this->signedIn($this->tenant);
    }

    /** One of everything, posted: invoice (partly paid), payment, payable and direct-paid expense, cash receipt and payment, and a bank statement. */
    private function world(): void
    {
        $vendor = $this->vendor($this->tenant, 'V-1', ['name' => 'Vendor Satu']);
        $this->vendor($this->tenant, 'V-2', ['name' => '=HYPERLINK("http://x")']);
        $bank = $this->cashAccount($this->tenant, 'BCA', 'BANK', '1120', ['account_number' => '1234567890123']);
        $this->bank = $bank->id;
        $this->signedIn($this->tenant);
        $receipt = $this->postJson(self::AP.'/cash-receipts', ['cash_bank_account_id' => $bank->id, 'counter_account_id' => $this->account($this->tenant, '3100')->id, 'amount' => '5000000',
            'transaction_date' => '2026-03-01', 'purpose' => 'Setoran modal', 'description' => 'Setoran modal pemilik'])->assertCreated()->json('id');
        $this->postJson(self::AP."/cash-receipts/{$receipt}/post")->assertOk();
        $invoice = $this->postedInvoice($vendor, ['vendor_invoice_number' => 'INV-EXPORT-1']);
        $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '400000'], ['payment_date' => '2026-03-10', 'posting_date' => '2026-03-10']);
        $category = $this->expenseCategory($this->tenant, 'UTIL', ['account_code' => '6300']);
        $this->postedExpense($this->payableExpenseBody($category->id, $vendor));
        $this->postedExpense($this->paidExpenseBody($category->id, $bank->id));
        $fee = $this->postJson(self::AP.'/cash-payments', ['cash_bank_account_id' => $bank->id, 'counter_account_id' => $this->account($this->tenant, '6900')->id, 'amount' => '25000',
            'transaction_date' => '2026-03-15', 'purpose' => 'Biaya administrasi bank', 'description' => 'Biaya admin Maret'])->assertCreated()->json('id');
        $this->postJson(self::AP."/cash-payments/{$fee}/post")->assertOk();
        $this->statement = $this->postJson(self::AP.'/bank-statements', ['cash_bank_account_id' => $bank->id, 'reference' => 'BCA-03', 'statement_date' => '2026-03-31', 'closing_balance' => '4000000',
            'items' => [['item_date' => '2026-03-01', 'description' => 'Setoran', 'amount' => '5000000']]])->assertCreated()->json();
    }

    /** @return array<string,string> label => URL, every export of the batch */
    private function exports(): array
    {
        return [
            'vendors' => self::AP.'/vendors/export', 'invoices' => self::AP.'/ap-invoices/export', 'payments' => self::AP.'/vendor-payments/export', 'expenses' => self::AP.'/expenses/export',
            'cash payments' => self::AP.'/cash-payments/export', 'cash receipts' => self::AP.'/cash-receipts/export',
            'movements' => self::AP."/cash-bank-accounts/{$this->bank}/transactions/export", 'ap reconciliation' => self::AP.'/reconciliation/ap/export',
            'cash reconciliation' => self::AP.'/reconciliation/cash-bank/export', 'statement' => self::AP."/bank-statements/{$this->statement['id']}/export",
        ];
    }

    private function csv(string $url): string
    {
        return $this->get($url)->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
    }

    public function test_every_list_and_reconciliation_can_be_exported_with_its_figures(): void
    {
        $this->world();
        $this->assertStringContainsString('Vendor Satu', $this->csv(self::AP.'/vendors/export'));
        $invoices = $this->csv(self::AP.'/ap-invoices/export');
        $this->assertStringContainsString('INV-EXPORT-1', $invoices);
        $this->assertStringContainsString('1000000.0000', $invoices); // total
        $this->assertStringContainsString('600000.0000', $invoices); // outstanding after the 400.000 payment
        $this->assertStringContainsString('PARTIALLY_PAID', $invoices);
        $this->assertStringContainsString('400000.0000', $this->csv(self::AP.'/vendor-payments/export'));
        $expenses = $this->csv(self::AP.'/expenses/export');
        $this->assertStringContainsString('Biaya listrik Maret', $expenses);
        $this->assertStringContainsString('Parkir dan tol', $expenses);
        $this->assertStringContainsString('1110000.0000', $expenses);
        $this->assertStringContainsString('Biaya administrasi bank', $this->csv(self::AP.'/cash-payments/export'));
        $this->assertStringNotContainsString('Biaya administrasi bank', $this->csv(self::AP.'/cash-receipts/export'));
        $this->assertStringContainsString('Setoran modal', $this->csv(self::AP.'/cash-receipts/export'));

        $movements = $this->csv(self::AP."/cash-bank-accounts/{$this->bank}/transactions/export");
        $this->assertStringContainsString('5000000.0000', $movements);
        $this->assertStringContainsString('4375000.0000', $movements); // running balance after the 400.000 payment and the 25.000 fee... and 200.000 paid expense: 5.000.000 - 400.000 - 200.000 - 25.000
        $this->assertStringContainsString('4375000.0000', $this->csv(self::AP.'/reconciliation/cash-bank/export'));
        $ap = $this->csv(self::AP.'/reconciliation/ap/export?as_of=2026-03-31');
        $this->assertStringContainsString('V-1', $ap);
        $this->assertStringContainsString('MATCHED', $ap);
        $statement = $this->csv(self::AP."/bank-statements/{$this->statement['id']}/export");
        $this->assertStringContainsString('BCA-03', $statement);
        $this->assertStringContainsString('Setoran', $statement);
        $this->assertStringContainsString('5000000.0000', $statement);
    }

    public function test_filters_of_the_list_apply_to_its_export_and_formulas_and_bank_numbers_are_neutralised(): void
    {
        $this->world();
        $vendors = $this->csv(self::AP.'/vendors/export');
        $this->assertStringContainsString("'=HYPERLINK", $vendors);
        $this->assertStringNotContainsString("\n=HYPERLINK", $vendors);
        $inactive = $this->csv(self::AP.'/vendors/export?status=INACTIVE');
        $this->assertStringNotContainsString('V-1', $inactive);
        $this->assertSame(1, count(array_filter(explode("\n", trim($inactive)))), 'only the header row');
        $this->assertStringNotContainsString('INV-EXPORT-1', $this->csv(self::AP.'/ap-invoices/export?payment_status=PAID'));
        $this->assertStringContainsString('INV-EXPORT-1', $this->csv(self::AP.'/ap-invoices/export?open=1'));
        $this->assertStringNotContainsString('Parkir dan tol', $this->csv(self::AP.'/expenses/export?settlement=PAYABLE'));
        $this->assertStringNotContainsString('4375000.0000', $this->csv(self::AP."/cash-bank-accounts/{$this->bank}/transactions/export?direction=IN"));
        $this->get(self::AP.'/ap-invoices/export?status=NOPE')->assertStatus(422)->assertJsonValidationErrors('status'); // validated like the list

        foreach ($this->exports() as $label => $url) {
            $this->assertStringNotContainsString('1234567890123', $this->csv($url), "{$label} exposes the bank number");
        }
    }

    public function test_every_export_needs_the_export_permission_and_nothing_else_and_is_audited(): void
    {
        $this->world();
        $viewer = $this->memberToken($this->tenant, [
            'accounting.vendor.view', 'accounting.ap_invoice.view', 'accounting.ap_payment.view', 'accounting.expense.view', 'accounting.cash_transaction.view', 'accounting.cash_bank.view',
            'accounting.reconciliation.ap.view', 'accounting.reconciliation.cash_bank.view', 'accounting.bank_reconciliation.view', 'accounting.ap_aging.view',
        ]);
        $exporter = $this->memberToken($this->tenant, ['accounting.report.export']);
        $before = DB::table('audit_logs')->where('action', 'like', '%.report.exported')->count();
        foreach ($this->exports() as $label => $url) {
            $this->as($viewer)->get($url)->assertStatus(403);
            $this->as($exporter)->get($url)->assertOk();
        }
        $this->assertSame($before + count($this->exports()), DB::table('audit_logs')->where('action', 'like', '%.report.exported')->count());
        $reports = DB::table('audit_logs')->where('action', 'like', '%.report.exported')->orderBy('occurred_at')->pluck('resource_id')->all();
        foreach (['vendors', 'ap_invoices', 'vendor_payments', 'expenses', 'cash_payments', 'cash_receipts', 'cash_bank_movements', 'ap_reconciliation', 'cash_bank_reconciliation', 'bank_statement'] as $name) {
            $this->assertContains($name, $reports);
        }
        $log = DB::table('audit_logs')->where('resource_id', 'ap_invoices')->where('action', 'payables.report.exported')->first();
        $this->assertSame(2, json_decode($log->changes, true)['after']['rows']); // the invoice and the payable expense's payable
        $this->assertSame($this->tenant->id, $log->tenant_id);
    }

    public function test_an_export_never_shows_another_tenant_or_a_row_outside_the_data_scope(): void
    {
        $this->world();
        $other = $this->payablesTenant('bravo', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false]);
        $theirBank = $this->cashAccount($other, 'THEIR-BANK');
        $this->signedIn($other);
        $theirVendor = $this->vendor($other, 'B-ONLY', ['name' => 'Vendor Bravo']);
        $this->postedInvoice($theirVendor, ['vendor_invoice_number' => 'B-INV-1']);
        $theirStatement = $this->postJson(self::AP.'/bank-statements', ['cash_bank_account_id' => $theirBank->id, 'reference' => 'B-ST', 'statement_date' => '2026-03-31', 'closing_balance' => '1'])->assertCreated()->json('id');

        $this->signedIn($this->tenant);
        foreach (['vendors', 'ap-invoices', 'vendor-payments', 'expenses', 'cash-payments', 'cash-receipts'] as $path) {
            $csv = $this->csv(self::AP."/{$path}/export");
            $this->assertStringNotContainsString('B-ONLY', $csv);
            $this->assertStringNotContainsString('B-INV-1', $csv);
        }
        $this->get(self::AP."/cash-bank-accounts/{$theirBank->id}/transactions/export")->assertStatus(404);
        $this->get(self::AP."/bank-statements/{$theirStatement}/export")->assertStatus(404);
        $this->assertStringNotContainsString('THEIR-BANK', $this->csv(self::AP.'/reconciliation/cash-bank/export'));

        // Data scope: a branch user exports only what the list shows them.
        [$north, $south] = $this->branches($this->tenant, 'N', 'S');
        $northBank = $this->cashAccount($this->tenant, 'BCA-N', 'BANK', '1160', ['branch_id' => $north]);
        $vendor = $this->vendor($this->tenant, 'V-3');
        $this->signedIn($this->tenant);
        $this->postedInvoice($vendor, ['branch_id' => $north, 'vendor_invoice_number' => 'INV-NORTH']);
        $this->postedInvoice($vendor, ['branch_id' => $south, 'vendor_invoice_number' => 'INV-SOUTH']);
        $scoped = $this->as($this->scopedToken($this->tenant, ['accounting.report.export'], 'BRANCH', $north));
        $csv = $scoped->get(self::AP.'/ap-invoices/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('INV-NORTH', $csv);
        $this->assertStringNotContainsString('INV-SOUTH', $csv);
        $this->assertStringNotContainsString('INV-EXPORT-1', $csv); // tenant-level documents (no branch) are outside a branch scope
        $this->assertStringNotContainsString('BCA,', $scoped->get(self::AP.'/reconciliation/cash-bank/export')->streamedContent());
        $this->assertStringContainsString('BCA-N', $scoped->get(self::AP.'/reconciliation/cash-bank/export')->streamedContent());
        $scoped->get(self::AP."/cash-bank-accounts/{$this->bank}/transactions/export")->assertStatus(404);
        $scoped->get(self::AP."/cash-bank-accounts/{$northBank->id}/transactions/export")->assertOk();
        $scoped->get(self::AP."/bank-statements/{$this->statement['id']}/export")->assertStatus(404);
    }

    public function test_an_export_is_capped_and_respects_module_and_feature_state(): void
    {
        $this->world();
        config(['optientry.export_max_rows' => 1]);
        $this->get(self::AP.'/vendors/export')->assertStatus(422)->assertJsonPath('code', 'EXPORT_TOO_LARGE')->assertJsonPath('details.rows', 2)->assertJsonPath('details.max_rows', 1);
        $this->get(self::AP."/cash-bank-accounts/{$this->bank}/transactions/export")->assertStatus(422)->assertJsonPath('code', 'EXPORT_TOO_LARGE');
        $this->csv(self::AP.'/vendors/export?q=V-1'); // narrowed filters fit
        config(['optientry.export_max_rows' => 10000]);

        // READ_ONLY keeps reading (an export is a read); a lost module or a disabled feature closes it.
        $module = fn (string $code, string $state) => DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('module_id', DB::table('modules')->where('code', $code)->value('id'))->update(['state' => $state]);
        $module('ACCOUNTING_AP', 'READ_ONLY');
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->csv(self::AP.'/ap-invoices/export');
        $module('ACCOUNTING_AP', 'DISABLED');
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->get(self::AP.'/ap-invoices/export')->assertStatus(403);
        $this->get(self::AP.'/vendors/export')->assertStatus(403);
        $this->get(self::AP.'/reconciliation/ap/export')->assertStatus(403);
        $this->csv(self::AP.'/expenses/export'); // other modules are not affected
        $module('ACCOUNTING_AP', 'ACTIVE');
        DB::table('tenant_feature_entitlements')->where('tenant_id', $this->tenant->id)->where('feature_id', Feature::query()->where('code', 'RECEIPT')->value('id'))->update(['state' => 'DISABLED']);
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->get(self::AP.'/cash-receipts/export')->assertStatus(403)->assertJsonPath('code', 'FEATURE_NOT_ENTITLED');
        $this->csv(self::AP.'/cash-payments/export');
    }
}
