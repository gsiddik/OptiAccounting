<?php

namespace Tests\Feature\Tax;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/** OA4: the tax report reads the frozen tax of POSTED documents only; a reversal takes a document out in its own period; data scope narrows it. */
class TaxReportTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, ReceivablesFixtures, TaxFixtures;

    private Tenant $tenant;

    /** @var array<string,mixed> */
    private array $in;

    /** @var array<string,mixed> */
    private array $out;

    /** @var array<string,mixed> */
    private array $cost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->taxTenant();
        $this->signedIn($this->tenant);
        $this->in = $this->taxCode(['code' => 'PPN-IN']);
        $this->out = $this->outputCode(['code' => 'PPN-OUT']);
        $this->cost = $this->taxCode(['code' => 'PPN-NR', 'is_recoverable' => false]);
    }

    /** @return array<string,array<string,mixed>> */
    private function postedSet(): array
    {
        $vendor = $this->vendor($this->tenant);
        $customer = $this->customer($this->tenant);

        return [
            'ap' => $this->postedTaxedInvoice($vendor, $this->in['id'], '1000000'),
            'ar' => $this->postedArInvoice($customer, ['lines' => [['description' => 'Jasa', 'amount' => '2000000', 'tax_code_id' => $this->out['id']]]]),
            'nr' => $this->postedTaxedInvoice($vendor, $this->cost['id'], '500000'),
        ];
    }

    public function test_the_summary_separates_output_recoverable_input_and_non_recoverable_input_tax(): void
    {
        $this->postedSet();

        $report = $this->getJson(self::TX.'/tax-report?date_from=2026-03-01&date_to=2026-03-31')->assertOk()->json();

        $this->assertSame([
            'output_base' => '2000000.0000', 'output_tax' => '220000.0000', 'input_base' => '1500000.0000',
            'input_tax_recoverable' => '110000.0000', 'input_tax_non_recoverable' => '55000.0000', 'net_payable' => '110000.0000',
        ], $report['totals']);
        $rows = collect($report['rows'])->keyBy('tax_code');
        $this->assertSame(['INPUT', '1000000.0000', '110000.0000', 1], [$rows['PPN-IN']['direction'], $rows['PPN-IN']['base_amount'], $rows['PPN-IN']['tax_amount'], $rows['PPN-IN']['transactions']]);
        $this->assertSame(['OUTPUT', '2000000.0000', '220000.0000'], [$rows['PPN-OUT']['direction'], $rows['PPN-OUT']['base_amount'], $rows['PPN-OUT']['tax_amount']]);
        $this->assertFalse($rows['PPN-NR']['is_recoverable']);
        $this->assertTrue($report['complete']);
        $this->assertSame('posting_date', $report['basis']);
    }

    public function test_the_report_agrees_with_the_tax_accounts_of_the_ledger(): void
    {
        $this->postedSet();

        $totals = $this->getJson(self::TX.'/tax-report')->assertOk()->json('totals');

        $this->assertSame($totals['input_tax_recoverable'], $this->glBalance($this->tenant, '1150'));
        $this->assertSame('-'.$totals['output_tax'], $this->glBalance($this->tenant, '2120'));
    }

    public function test_filters_narrow_the_figures(): void
    {
        $this->postedSet();

        $this->assertSame('220000.0000', $this->getJson(self::TX.'/tax-report?direction=OUTPUT')->assertOk()->json('totals.output_tax'));
        $this->assertSame('0.0000', $this->getJson(self::TX.'/tax-report?direction=OUTPUT')->assertOk()->json('totals.input_tax_recoverable'));
        $this->assertSame('110000.0000', $this->getJson(self::TX."/tax-report?tax_code_id={$this->in['id']}")->assertOk()->json('totals.input_tax_recoverable'));
        $this->assertSame('0.0000', $this->getJson(self::TX.'/tax-report?date_from=2026-04-01&date_to=2026-04-30')->assertOk()->json('totals.output_tax'));
        $this->assertSame('220000.0000', $this->getJson(self::TX.'/tax-report?source_type=ar_invoice')->assertOk()->json('totals.output_tax'));
        $this->getJson(self::TX.'/tax-report?basis=nope')->assertStatus(422);
        $this->getJson(self::TX.'/tax-report?date_from=2026-04-01&date_to=2026-03-01')->assertStatus(422);
    }

    public function test_a_draft_or_cancelled_document_never_reaches_the_report(): void
    {
        $vendor = $this->vendor($this->tenant);
        $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $this->in['id']]]]))->assertCreated();

        $this->assertSame('0.0000', $this->getJson(self::TX.'/tax-report')->assertOk()->json('totals.input_tax_recoverable'));
        $this->assertSame(0, $this->getJson(self::TX.'/tax-transactions')->assertOk()->json('total'));
        $this->assertSame(1, $this->getJson(self::TX.'/tax-transactions?status=DRAFT')->assertOk()->json('total'));
    }

    public function test_a_reversal_leaves_the_original_period_alone_and_takes_the_tax_out_in_its_own(): void
    {
        $docs = $this->postedSet();
        $this->postJson(self::AR."/ar-invoices/{$docs['ar']['id']}/reverse", ['reason' => 'Salah tagih', 'posting_date' => '2026-04-05'])->assertOk();

        $march = $this->getJson(self::TX.'/tax-report?date_from=2026-03-01&date_to=2026-03-31')->assertOk()->json('totals');
        $april = $this->getJson(self::TX.'/tax-report?date_from=2026-04-01&date_to=2026-04-30')->assertOk()->json('totals');
        $all = $this->getJson(self::TX.'/tax-report')->assertOk()->json('totals');

        $this->assertSame(['220000.0000', '-220000.0000', '0.0000'], [$march['output_tax'], $april['output_tax'], $all['output_tax']]);
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '2120'));

        // on the tax date basis a reversed document simply does not count
        $this->assertSame('0.0000', $this->getJson(self::TX.'/tax-report?basis=tax_date&date_from=2026-03-01&date_to=2026-03-31')->assertOk()->json('totals.output_tax'));
        $this->assertSame(0, $this->getJson(self::TX.'/tax-transactions?source_type=ar_invoice')->assertOk()->json('total'));
        $this->assertSame(1, $this->getJson(self::TX.'/tax-transactions?status=REVERSED')->assertOk()->json('total'));
    }

    public function test_a_later_rate_change_does_not_move_a_past_period(): void
    {
        $this->postedSet();
        $before = $this->getJson(self::TX.'/tax-report?date_from=2026-03-01&date_to=2026-03-31')->assertOk()->json('totals');

        $this->postJson(self::TX."/tax-codes/{$this->in['id']}/rates", ['rate' => '15', 'effective_from' => '2026-05-01'])->assertCreated();
        $this->postJson(self::TX."/tax-codes/{$this->in['id']}/deactivate")->assertOk();

        $this->assertSame($before, $this->getJson(self::TX.'/tax-report?date_from=2026-03-01&date_to=2026-03-31')->assertOk()->json('totals'));
    }

    public function test_the_transaction_list_pages_and_orders_by_the_chosen_date(): void
    {
        $this->postedSet();

        $list = $this->getJson(self::TX.'/tax-transactions?per_page=2')->assertOk()->json();

        $this->assertSame([3, 2], [$list['total'], count($list['data'])]);
        $this->assertSame('POSTED', $list['data'][0]['status']);
        $this->assertNotNull($list['data'][0]['document_number']);
    }

    public function test_manual_credit_note_tax_is_shown_beside_the_report_but_is_not_part_of_it(): void
    {
        $docs = $this->postedSet();
        $this->postedCreditNote($docs['ar']['id'], ['tax_amount' => '11000']);

        $report = $this->getJson(self::TX.'/tax-report')->assertOk()->json();

        $this->assertSame(['11000.0000', 1, true], [$report['credit_note_tax']['ar_credit_note_tax'], $report['credit_note_tax']['ar_credit_notes'], $report['credit_note_tax']['informational']]);
        $this->assertSame('220000.0000', $report['totals']['output_tax']);
    }

    public function test_the_export_is_audited_and_uses_the_same_figures(): void
    {
        $this->postedSet();

        $response = $this->get(self::TX.'/tax-report/export?date_from=2026-03-01&date_to=2026-03-31')->assertOk();

        $csv = $response->streamedContent();
        $this->assertStringContainsString('PPN-OUT', $csv);
        $this->assertStringContainsString('220000', $csv);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'tax.report.exported')->count());
    }

    public function test_a_scoped_user_sees_only_the_tax_of_their_branch_and_the_report_says_it_is_incomplete(): void
    {
        [$north, $south] = $this->branches($this->tenant, 'N', 'S');
        $vendor = $this->vendor($this->tenant);
        $this->postedTaxedInvoice($vendor, $this->in['id'], '1000000', ['branch_id' => $north]);
        $this->postedTaxedInvoice($vendor, $this->in['id'], '3000000', ['branch_id' => $south]);

        $token = $this->scopedToken($this->tenant, ['accounting.tax.report.view'], 'BRANCH', $north);
        $report = $this->as($token)->getJson(self::TX.'/tax-report')->assertOk()->json();

        $this->assertSame(['110000.0000', false], [$report['totals']['input_tax_recoverable'], $report['complete']]);
        $this->assertSame(1, $this->as($token)->getJson(self::TX.'/tax-transactions')->assertOk()->json('total'));
    }

    public function test_the_report_is_tenant_scoped(): void
    {
        $this->postedSet();
        $bravo = $this->taxTenant('bravo');
        $this->signedIn($bravo);

        $this->assertSame('0.0000', $this->getJson(self::TX.'/tax-report')->assertOk()->json('totals.output_tax'));
        $this->assertSame(0, $this->getJson(self::TX.'/tax-transactions')->assertOk()->json('total'));
    }
}
