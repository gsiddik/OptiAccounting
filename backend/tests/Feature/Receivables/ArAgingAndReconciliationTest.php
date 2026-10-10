<?php

namespace Tests\Feature\Receivables;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\TestCase;

/** OA2 batch E: AP aging (due date, as-of date, buckets, filters, scope, export) and the AP-to-GL reconciliation. */
class ArAgingAndReconciliationTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, ReceivablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->receivablesTenant('alpha', ['sod_creator_not_approver' => false]);
        $this->signedIn($this->tenant);
    }

    private function dueInvoice(object $customer, string $amount, string $due, array $override = []): array
    {
        $term = (string) DB::table('payment_terms')->where('tenant_id', $this->tenant->id)->where('code', 'CUSTOM')->value('id');

        return $this->postedArInvoice($customer, ['lines' => [['description' => 'Jasa', 'amount' => $amount]], 'payment_term_id' => $term, 'due_date' => $due, 'document_date' => '2026-01-05', 'posting_date' => '2026-01-05'] + $override);
    }

    public function test_aging_places_each_invoice_by_days_past_due_on_the_as_of_date(): void
    {
        $customer = $this->customer($this->tenant, 'V1');
        $other = $this->customer($this->tenant, 'V2');
        $this->dueInvoice($customer, '100000', '2026-04-20'); // not yet due on 15 April
        $this->dueInvoice($customer, '200000', '2026-04-05'); // 10 days
        $this->dueInvoice($other, '300000', '2026-02-20'); // 54 days
        $this->dueInvoice($other, '400000', '2026-01-31'); // 74 days
        $this->dueInvoice($other, '500000', '2026-01-10'); // 95 days
        $this->dueInvoice($customer, '50000', '2026-04-15'); // due on the as-of date itself: not overdue

        $report = $this->getJson(self::AR.'/ar-aging?as_of=2026-04-15')->assertOk()->json();
        $this->assertSame(['CURRENT', '1-30', '31-60', '61-90', '90+'], array_column($report['buckets'], 'key'));
        $this->assertSame('150000.0000', $report['totals']['CURRENT']);
        $this->assertSame('200000.0000', $report['totals']['1-30']);
        $this->assertSame('300000.0000', $report['totals']['31-60']);
        $this->assertSame('400000.0000', $report['totals']['61-90']);
        $this->assertSame('500000.0000', $report['totals']['90+']);
        $this->assertSame('1550000.0000', $report['totals']['total']);
        $this->assertSame(6, $report['invoice_count']);
        $this->assertTrue($report['complete']);

        $byCustomer = collect($report['data'])->keyBy('customer_code');
        $this->assertSame('350000.0000', $byCustomer['V1']['total']);
        $this->assertSame('1200000.0000', $byCustomer['V2']['total']);
        $this->assertSame('150000.0000', $byCustomer['V1']['buckets']['CURRENT']);

        // The same invoices on an earlier day sit in earlier buckets: aging follows the as-of date, not today.
        $earlier = $this->getJson(self::AR.'/ar-aging?as_of=2026-02-01')->assertOk()->json();
        $this->assertSame('1550000.0000', $earlier['totals']['total']); // all posted 5 January
        $this->assertSame('650000.0000', $earlier['totals']['CURRENT']); // due 20 April, 5 April, 20 February and 15 April
        $this->assertSame('900000.0000', $earlier['totals']['1-30']); // due 31 January (1 day) and 10 January (22 days)
    }

    public function test_aging_replays_receipts_and_their_reversals_by_posting_date(): void
    {
        $customer = $this->customer($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->dueInvoice($customer, '1000000', '2026-03-01');
        $receipt = $this->postedReceipt($customer, $bank->id, [$invoice['id'] => '400000'], ['posting_date' => '2026-04-10', 'receipt_date' => '2026-04-10']);

        $on = fn (string $date) => $this->getJson(self::AR."/ar-aging?as_of={$date}&detail=1")->assertOk()->json();
        $this->assertSame('1000000.0000', $on('2026-04-09')['totals']['total']); // before the receipt
        $this->assertSame('600000.0000', $on('2026-04-10')['totals']['total']);
        $this->assertSame('600000.0000', $on('2026-05-01')['invoices'][0]['outstanding_amount']);
        $this->assertSame('400000.0000', $on('2026-05-01')['invoices'][0]['received_amount']);

        $this->postJson(self::AR."/customer-receipts/{$receipt['id']}/reverse", ['reason' => 'x', 'posting_date' => '2026-04-20'])->assertOk();
        $this->assertSame('600000.0000', $on('2026-04-19')['totals']['total']); // still paid until the reversal date
        $this->assertSame('1000000.0000', $on('2026-04-20')['totals']['total']);
        $this->assertSame('600000.0000', $on('2026-04-12')['totals']['total']); // a past report does not change

        // A fully paid invoice and a reversed invoice leave the report from the day they stop being payable.
        $paid = $this->postedReceipt($customer, $bank->id, [$invoice['id'] => '1000000'], ['posting_date' => '2026-05-02', 'receipt_date' => '2026-05-02']);
        $this->assertSame('0.0000', $on('2026-05-02')['totals']['total']);
        $this->assertSame(0, $on('2026-05-02')['invoice_count']);
        $this->assertNotNull($paid);
        $this->postJson(self::AR."/customer-receipts/{$paid['id']}/reverse", ['reason' => 'x', 'posting_date' => '2026-05-03'])->assertOk();
        $this->postJson(self::AR."/ar-invoices/{$invoice['id']}/reverse", ['reason' => 'salah', 'posting_date' => '2026-06-01'])->assertOk();
        $this->assertSame('1000000.0000', $on('2026-05-31')['totals']['total']);
        $this->assertSame('0.0000', $on('2026-06-01')['totals']['total']);
    }

    public function test_buckets_are_configurable_and_filters_follow_the_data_scope(): void
    {
        [$north, $south] = $this->branches($this->tenant, 'N', 'S');
        $customer = $this->customer($this->tenant);
        $this->dueInvoice($customer, '100000', '2026-04-05', ['branch_id' => $north]); // 10 days
        $this->dueInvoice($customer, '200000', '2026-03-20', ['branch_id' => $south]); // 26 days
        $this->dueInvoice($customer, '300000', '2026-02-01'); // 73 days, no branch

        $custom = $this->getJson(self::AR.'/ar-aging?as_of=2026-04-15&buckets=7,14,45')->assertOk()->json();
        $this->assertSame(['CURRENT', '1-7', '8-14', '15-45', '45+'], array_column($custom['buckets'], 'key'));
        $this->assertSame('100000.0000', $custom['totals']['8-14']);
        $this->assertSame('200000.0000', $custom['totals']['15-45']);
        $this->assertSame('300000.0000', $custom['totals']['45+']);
        foreach (['0', '5,3,x', '10,10,10,10,10,10,10,10,10', '99999'] as $bad) {
            $this->getJson(self::AR.'/ar-aging?buckets='.$bad)->assertStatus(422);
        }
        $this->assertSame('10,20', implode(',', array_map(fn ($b) => $b['to'], array_slice($this->getJson(self::AR.'/ar-aging?buckets=20,10')->json('buckets'), 1, 2)))); // boundaries are sorted

        $this->getJson(self::AR.'/ar-aging?as_of=2026-04-15&branch_id='.$north)->assertOk()->assertJsonPath('totals.total', '100000.0000');
        $this->getJson(self::AR.'/ar-aging?as_of=2026-04-15&customer_id='.$customer->id)->assertOk()->assertJsonPath('totals.total', '600000.0000');

        $scoped = $this->as($this->scopedToken($this->tenant, ['accounting.ar_aging.view'], 'BRANCH', $north));
        $report = $scoped->getJson(self::AR.'/ar-aging?as_of=2026-04-15')->assertOk()->json();
        $this->assertSame('100000.0000', $report['totals']['total']); // no totals from other branches leak
        $this->assertFalse($report['complete']);
        $scoped->getJson(self::AR.'/ar-aging?as_of=2026-04-15&branch_id='.$south)->assertOk()->assertJsonPath('totals.total', '0.0000');
    }

    public function test_aging_can_be_exported_with_the_same_permission_and_scope_and_is_audited(): void
    {
        $customer = $this->customer($this->tenant, 'V-1');
        $this->dueInvoice($customer, '123456.78', '2026-04-05', ['customer_reference' => '=HYPERLINK("x")']);

        $response = $this->get(self::AR.'/ar-aging/export?as_of=2026-04-15')->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('V-1', $csv);
        $this->assertStringContainsString('123456.78', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv); // formulas are neutralised
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'receivables.report.exported')->count());

        $this->as($this->memberToken($this->tenant, ['accounting.ar_aging.view']))->get(self::AR.'/ar-aging/export')->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.report.export']))->getJson(self::AR.'/ar-aging')->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.report.export']))->get(self::AR.'/ar-aging/export')->assertOk();
    }

    public function test_the_subledger_reconciles_to_the_control_account_and_a_difference_is_shown_not_hidden(): void
    {
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $v1 = $this->customer($this->tenant, 'V1');
        $v2 = $this->customer($this->tenant, 'V2');
        $a = $this->dueInvoice($v1, '1000000', '2026-04-30', ['tax_amount' => '110000']);
        $b = $this->dueInvoice($v2, '500000', '2026-04-30');
        $this->postedReceipt($v1, $bank->id, [$a['id'] => '300000'], ['posting_date' => '2026-03-10', 'receipt_date' => '2026-03-10']);
        $paid = $this->postedReceipt($v2, $bank->id, [$b['id'] => '500000'], ['posting_date' => '2026-03-11', 'receipt_date' => '2026-03-11']);
        $this->postJson(self::AR."/customer-receipts/{$paid['id']}/reverse", ['reason' => 'x', 'posting_date' => '2026-03-15'])->assertOk();

        $report = $this->getJson(self::AR.'/reconciliation/ar?as_of=2026-04-01')->assertOk()->json();
        $this->assertSame('MATCHED', $report['status']);
        $this->assertSame('1310000.0000', $report['gl_balance']); // 1.110.000 - 300.000 + 500.000
        $this->assertSame('1310000.0000', $report['subledger_balance']);
        $this->assertSame('0.0000', $report['difference']);
        $this->assertSame('0.0000', $report['opening_balance_component']);
        $this->assertTrue($report['complete']);
        $this->assertSame(['V1', 'V2'], array_column($report['customers'], 'customer_code'));
        $this->assertSame(['810000.0000', '500000.0000'], array_column($report['customers'], 'subledger_balance'));
        $this->assertSame(['MATCHED', 'MATCHED'], array_column($report['customers'], 'status'));
        $this->assertSame('1130', $report['control_accounts'][0]['code']);

        // Each past date reconciles on its own figures: on 12 March the second receipt still held.
        $past = $this->getJson(self::AR.'/reconciliation/ar?as_of=2026-03-12')->assertOk()->json();
        $this->assertSame(['MATCHED', '810000.0000'], [$past['status'], $past['subledger_balance']]);
        $this->getJson(self::AR.'/reconciliation/ar?as_of=2026-04-01&customer_id='.$v1->id)->assertOk()->assertJsonPath('status', 'MATCHED')->assertJsonPath('subledger_balance', '810000.0000');

        // A line on the control account that no document explains makes the difference visible; neither side is adjusted.
        $this->postedJournal($this->tenant, ['lines' => $this->lines($this->tenant, '5000', '1130', '4100'), 'posting_date' => '2026-03-20', 'document_date' => '2026-03-20']);
        $off = $this->getJson(self::AR.'/reconciliation/ar?as_of=2026-04-01')->assertOk()->json();
        $this->assertSame('MISMATCH', $off['status']);
        $this->assertSame('5000.0000', $off['difference']);
        $this->assertSame('1315000.0000', $off['gl_balance']);
        $this->assertSame('1310000.0000', $off['subledger_balance']);
        $this->assertSame('MATCHED', $this->getJson(self::AR.'/reconciliation/ar?as_of=2026-03-19')->json('status')); // before the stray line
        $this->assertSame(0, DB::table('journal_entries')->where('description', 'like', '%penyesuaian%')->count());
    }

    public function test_an_opening_balance_on_the_control_account_is_its_own_component(): void
    {
        $this->putJson(self::AR.'/opening-balance', ['cutover_date' => '2026-01-01', 'reference' => 'SALDO-AWAL', 'lines' => [
            ['account_id' => $this->account($this->tenant, '1110')->id, 'debit' => '500000'],
            ['account_id' => $this->account($this->tenant, '1130')->id, 'debit' => '2000000', 'description' => 'Piutang awal'],
            ['account_id' => $this->account($this->tenant, '3100')->id, 'credit' => '2500000'],
        ]])->assertOk();
        $this->postJson(self::AR.'/opening-balance/post')->assertOk();
        $customer = $this->customer($this->tenant);
        $this->dueInvoice($customer, '700000', '2026-04-30');

        $report = $this->getJson(self::AR.'/reconciliation/ar?as_of=2026-04-01')->assertOk()->json();
        $this->assertSame('2700000.0000', $report['gl_balance']);
        $this->assertSame('2000000.0000', $report['opening_balance_component']);
        $this->assertSame('700000.0000', $report['gl_transactional_balance']);
        $this->assertSame('700000.0000', $report['subledger_balance']);
        $this->assertSame('MATCHED', $report['status']);
    }

    public function test_reports_need_their_permission_and_other_tenants_are_invisible(): void
    {
        $customer = $this->customer($this->tenant);
        $this->dueInvoice($customer, '100000', '2026-04-05');
        $this->as($this->memberToken($this->tenant, ['accounting.ar_invoice.view']))->getJson(self::AR.'/ar-aging')->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.ar_aging.view']))->getJson(self::AR.'/reconciliation/ar')->assertStatus(403);
        $this->as($this->memberToken($this->tenant, ['accounting.reconciliation.ar.view']))->getJson(self::AR.'/reconciliation/ar')->assertOk();

        $other = $this->receivablesTenant('beta');
        $this->signedIn($other);
        $this->getJson(self::AR.'/ar-aging?as_of=2026-04-15')->assertOk()->assertJsonPath('totals.total', '0.0000')->assertJsonPath('invoice_count', 0);
        $report = $this->getJson(self::AR.'/reconciliation/ar?as_of=2026-04-15')->assertOk()->json();
        $this->assertSame(['MATCHED', '0.0000', '0.0000'], [$report['status'], $report['gl_balance'], $report['subledger_balance']]);
        $this->getJson(self::AR.'/reconciliation/ar?customer_id='.$customer->id)->assertOk()->assertJsonPath('subledger_balance', '0.0000'); // another tenant's customer id shows nothing
    }
}
