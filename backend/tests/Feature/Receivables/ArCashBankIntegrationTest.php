<?php

namespace Tests\Feature\Receivables;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\TestCase;

/** OA3 batch G: customer receipts are a document kind of the cash/bank module: ledger, cash-to-GL reconciliation, bank statement matching. */
class ArCashBankIntegrationTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, ReceivablesFixtures;

    private Tenant $tenant;

    private string $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->receivablesTenant('alpha', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false]);
        $this->bank = $this->cashAccount($this->tenant, 'BCA')->id;
        $this->signedIn($this->tenant);
    }

    private function bankRow(): array
    {
        return collect($this->getJson(self::AP.'/reconciliation/cash-bank?as_of=2026-03-31')->assertOk()->json('accounts'))->firstWhere('code', 'BCA');
    }

    public function test_a_posted_receipt_moves_the_account_ledger_and_the_cash_to_gl_reconciliation_stays_matched(): void
    {
        $customer = $this->customer($this->tenant, 'C1');
        $invoice = $this->arInvoice($customer, '1000000');
        $receipt = $this->postedReceipt($customer, $this->bank, [$invoice['id'] => '600000']);

        $account = $this->bankRow();
        $this->assertSame('600000.0000', $account['book_balance']);
        $this->assertSame(['600000.0000', '600000.0000', '600000.0000', '0.0000', 'MATCHED'],
            [$account['documents']['customer_receipts'], $account['documents']['net'], $account['documents']['ledger_net'], $account['documents']['difference'], $account['documents']['status']]);
        $this->assertSame('0.0000', $account['documents']['receipts']); // cash receipts are a different kind

        $ledger = $this->getJson(self::AP."/cash-bank-accounts/{$this->bank}/transactions")->assertOk()->json('data');
        $this->assertCount(1, $ledger);
        $this->assertSame([$receipt['document_number'], '600000.0000'], [$ledger[0]['document_number'], $ledger[0]['running_balance']]);

        // Reversal drops it out of both sides; a reversal dated after the as-of date leaves the earlier picture intact.
        $this->postJson(self::AR."/customer-receipts/{$receipt['id']}/reverse", ['reason' => 'Salah rekening', 'posting_date' => '2026-04-05'])->assertOk();
        $this->assertSame('600000.0000', $this->bankRow()['documents']['customer_receipts']);
        $later = collect($this->getJson(self::AP.'/reconciliation/cash-bank?as_of=2026-04-30')->assertOk()->json('accounts'))->firstWhere('code', 'BCA');
        $this->assertSame(['0.0000', '0.0000', 'MATCHED'], [$later['documents']['customer_receipts'], $later['book_balance'], $later['documents']['status']]);
    }

    public function test_a_draft_or_unposted_receipt_does_not_touch_the_account(): void
    {
        $customer = $this->customer($this->tenant, 'C1');
        $invoice = $this->arInvoice($customer, '1000000');
        $draft = $this->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $this->bank, [$invoice['id'] => '300000']))->assertCreated()->json('id');
        $this->postJson(self::AR."/customer-receipts/{$draft}/submit")->assertOk();

        $account = $this->bankRow();
        $this->assertSame(['0.0000', '0.0000', 'MATCHED'], [$account['book_balance'], $account['documents']['customer_receipts'], $account['documents']['status']]);
        $this->assertSame([], $this->getJson(self::AP."/cash-bank-accounts/{$this->bank}/transactions")->assertOk()->json('data'));
    }

    public function test_a_receipt_can_be_matched_to_a_bank_statement_line_like_any_other_deposit(): void
    {
        $customer = $this->customer($this->tenant, 'C1');
        $invoice = $this->arInvoice($customer, '1000000');
        $receipt = $this->postedReceipt($customer, $this->bank, [$invoice['id'] => '600000'], ['receipt_date' => '2026-03-20', 'posting_date' => '2026-03-20']);
        $bookLine = (string) DB::table('journal_lines')->where('journal_entry_id', $receipt['journal_entry_id'])->where('account_id', $this->account($this->tenant, '1120')->id)->value('id');

        $statement = $this->postJson(self::AP.'/bank-statements', ['cash_bank_account_id' => $this->bank, 'reference' => 'BCA-03', 'statement_date' => '2026-03-31', 'closing_balance' => '600000',
            'items' => [['item_date' => '2026-03-20', 'description' => 'Transfer pelanggan C1', 'amount' => '600000']]])->assertCreated()->json();
        $item = $statement['items'][0]['id'];

        $candidates = $this->getJson(self::AP."/bank-statements/{$statement['id']}/items/{$item}/candidates")->assertOk()->json('data');
        $this->assertContains($bookLine, array_column($candidates, 'journal_line_id'));
        $this->postJson(self::AP."/bank-statements/{$statement['id']}/items/{$item}/match", ['journal_line_id' => $bookLine])->assertOk();
        $this->postJson(self::AP."/bank-statements/{$statement['id']}/complete")->assertOk();

        $account = $this->bankRow();
        $this->assertSame(['RECONCILED', '0.0000'], [$account['statement']['reconciliation'], $account['statement']['book_minus_statement']]);
    }
}
