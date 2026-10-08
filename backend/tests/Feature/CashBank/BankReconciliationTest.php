<?php

namespace Tests\Feature\CashBank;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA2 batch H: manual bank reconciliation — statements, matching, evidence, and the cash/bank to ledger report. Nothing here may touch the ledger. */
class BankReconciliationTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    private object $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->payablesTenant('alpha', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false]);
        $this->bank = $this->cashAccount($this->tenant, 'BCA', 'BANK', '1120', ['account_number' => '1234567890123']);
        $this->signedIn($this->tenant);
    }

    /** A bank account with five posted movements: +5.000.000 receipt, -1.000.000 vendor payment, -25.000 fee, -200.000 payment (not yet cleared by the bank). @return array<string,string> journal line ids by key */
    private function movements(): array
    {
        $vendor = $this->vendor($this->tenant);
        $this->signedIn($this->tenant);
        $receipt = $this->postJson(self::AP.'/cash-receipts', ['cash_bank_account_id' => $this->bank->id, 'counter_account_id' => $this->account($this->tenant, '3100')->id, 'amount' => '5000000',
            'transaction_date' => '2026-03-01', 'purpose' => 'Setoran modal', 'description' => 'Setoran modal pemilik'])->assertCreated()->json('id');
        $this->postJson(self::AP."/cash-receipts/{$receipt}/post")->assertOk();
        $invoice = $this->postedInvoice($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000']]]);
        $payment = $this->postedPayment($vendor, $this->bank->id, [$invoice['id'] => '1000000'], ['payment_date' => '2026-03-10', 'posting_date' => '2026-03-10']);
        $fee = $this->postJson(self::AP.'/cash-payments', ['cash_bank_account_id' => $this->bank->id, 'counter_account_id' => $this->account($this->tenant, '6900')->id, 'amount' => '25000',
            'transaction_date' => '2026-03-15', 'purpose' => 'Biaya administrasi bank', 'description' => 'Biaya admin Maret'])->assertCreated()->json('id');
        $this->postJson(self::AP."/cash-payments/{$fee}/post")->assertOk();
        $cheque = $this->postJson(self::AP.'/cash-payments', ['cash_bank_account_id' => $this->bank->id, 'counter_account_id' => $this->account($this->tenant, '6200')->id, 'amount' => '200000',
            'transaction_date' => '2026-03-28', 'purpose' => 'Sewa kantor (cek)', 'description' => 'Cek sewa belum cair'])->assertCreated()->json('id');
        $this->postJson(self::AP."/cash-payments/{$cheque}/post")->assertOk();

        $line = fn (string $journalId) => DB::table('journal_lines')->where('journal_entry_id', $journalId)->where('account_id', $this->account($this->tenant, '1120')->id)->value('id');

        return [
            'receipt' => $line(DB::table('cash_transactions')->where('id', $receipt)->value('journal_entry_id')), 'payment' => $line($payment['journal_entry_id']),
            'fee' => $line(DB::table('cash_transactions')->where('id', $fee)->value('journal_entry_id')), 'cheque' => $line(DB::table('cash_transactions')->where('id', $cheque)->value('journal_entry_id')),
        ];
    }

    private function statement(array $override = []): array
    {
        return $this->postJson(self::AP.'/bank-statements', $override + [
            'cash_bank_account_id' => $this->bank->id, 'reference' => 'BCA-2026-03', 'statement_date' => '2026-03-31', 'closing_balance' => '3965000',
            'items' => [
                ['item_date' => '2026-03-01', 'description' => 'Setoran', 'amount' => '5000000'],
                ['item_date' => '2026-03-10', 'description' => 'Transfer keluar vendor', 'amount' => '-1000000'],
                ['item_date' => '2026-03-15', 'description' => 'Biaya admin', 'amount' => '-25000'],
                ['item_date' => '2026-03-31', 'description' => 'Pajak bunga', 'amount' => '-10000'],
            ],
        ])->assertCreated()->json();
    }

    private function assertRefused(callable $attempt, string $label = ''): void
    {
        try {
            DB::transaction($attempt);
            $this->fail("the database accepted: {$label}");
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0], $label.' '.$e->getMessage());
        }
    }

    public function test_a_statement_is_prepared_matched_and_completed_without_touching_the_ledger(): void
    {
        $lines = $this->movements();
        $ledger = $this->glFigures($this->tenant);
        $statement = $this->statement();
        $this->assertSame('OPEN', $statement['status']);
        $this->assertSame(['UNMATCHED'], array_unique(array_column($statement['items'], 'status')));
        $this->assertSame([1, 2, 3, 4], array_column($statement['items'], 'line_number'));
        $this->assertSame('3775000.0000', $statement['summary']['book_balance']);
        $this->assertSame(['3775000.0000', 4], [$statement['summary']['unmatched_book_net'], $statement['summary']['unmatched_book_lines']]); // nothing matched yet: the whole book movement is open
        $this->assertSame(4, $statement['summary']['unmatched_items']);
        $items = collect($statement['items'])->pluck('id')->all();

        // Completion needs every line classified.
        $this->postJson(self::AP."/bank-statements/{$statement['id']}/complete")->assertStatus(409)->assertJsonPath('code', 'BANK_STATEMENT_HAS_UNMATCHED_ITEMS')->assertJsonPath('details.unmatched_items', 4);

        $uri = self::AP."/bank-statements/{$statement['id']}/items";
        $this->getJson("{$uri}/{$items[0]}/candidates")->assertOk()->assertJsonPath('data.0.journal_line_id', $lines['receipt'])->assertJsonCount(1, 'data');
        $this->postJson("{$uri}/{$items[0]}/match", ['journal_line_id' => $lines['receipt']])->assertOk()->assertJsonPath('items.0.status', 'MATCHED');
        $this->postJson("{$uri}/{$items[1]}/match", ['journal_line_id' => $lines['payment']])->assertOk();
        $this->postJson("{$uri}/{$items[2]}/match", ['journal_line_id' => $lines['fee']])->assertOk();
        $this->postJson("{$uri}/{$items[3]}/exception", ['notes' => 'Pajak bunga belum dicatat di buku'])->assertOk()->assertJsonPath('items.3.status', 'EXCEPTION');

        // The outstanding cheque (-200.000) is the only unmatched book line; the equation closes: (S + U) - (B + E) = 0.
        $shown = $this->getJson(self::AP."/bank-statements/{$statement['id']}")->assertOk()->json('summary');
        $this->assertSame(
            ['3965000.0000', '3775000.0000', '-200000.0000', 1, '-10000.0000', '0.0000', 'RECONCILED', '-190000.0000'],
            [$shown['statement_balance'], $shown['book_balance'], $shown['unmatched_book_net'], $shown['unmatched_book_lines'], $shown['exception_statement_net'], $shown['unexplained_difference'], $shown['status'], $shown['book_minus_statement']],
        );

        $done = $this->postJson(self::AP."/bank-statements/{$statement['id']}/complete")->assertOk()->assertJsonPath('status', 'COMPLETED')->json();
        $this->assertSame(['3775000.0000', '-200000.0000', '-10000.0000', '0.0000'], [$done['book_balance'], $done['unmatched_book_net'], $done['exception_statement_net'], $done['unexplained_difference']]);
        $this->assertNotNull($done['completed_at']);
        $this->assertEquals($ledger, $this->glFigures($this->tenant)); // reconciling never creates or changes a journal
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'bank_statement']));

        // A completed reconciliation is final, in the service and in the database.
        $this->patchJson(self::AP."/bank-statements/{$statement['id']}", ['notes' => 'x'])->assertStatus(409)->assertJsonPath('code', 'BANK_STATEMENT_COMPLETED');
        $this->postJson("{$uri}/{$items[0]}/unmatch")->assertStatus(409)->assertJsonPath('code', 'BANK_STATEMENT_COMPLETED');
        $this->postJson($uri, ['items' => [['item_date' => '2026-03-31', 'description' => 'x', 'amount' => '1']]])->assertStatus(409);
        $this->deleteJson(self::AP."/bank-statements/{$statement['id']}")->assertStatus(409);
        foreach ([
            fn () => DB::table('bank_statements')->where('id', $statement['id'])->update(['closing_balance' => 1]),
            fn () => DB::table('bank_statements')->where('id', $statement['id'])->delete(),
            fn () => DB::table('bank_statement_items')->where('id', $items[0])->update(['status' => 'UNMATCHED', 'matched_journal_line_id' => null, 'matched_by' => null, 'matched_at' => null]),
            fn () => DB::table('bank_statement_items')->where('id', $items[0])->delete(),
            fn () => DB::table('bank_statement_items')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'bank_statement_id' => $statement['id'], 'line_number' => 9,
                'item_date' => '2026-03-31', 'description' => 'x', 'amount' => 1, 'status' => 'UNMATCHED', 'created_at' => now(), 'updated_at' => now()]),
        ] as $i => $attempt) {
            $this->assertRefused($attempt, "tampering attempt #{$i}");
        }
        $this->assertSame(['cash_bank.statement.created', 'cash_bank.statement.item_matched', 'cash_bank.statement.item_matched', 'cash_bank.statement.item_matched', 'cash_bank.statement.item_flagged', 'cash_bank.statement.completed'],
            DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('resource_id', $statement['id'])->orderBy('occurred_at')->pluck('action')->all());
    }

    public function test_a_difference_is_reported_and_recorded_never_adjusted(): void
    {
        $lines = $this->movements();
        $ledger = $this->glFigures($this->tenant);
        $statement = $this->statement(['closing_balance' => '3900000']); // the bank says 65.000 less than the explained figure
        $uri = self::AP."/bank-statements/{$statement['id']}/items";
        $ids = collect($statement['items'])->pluck('id')->all();
        foreach (['receipt', 'payment', 'fee'] as $i => $key) {
            $this->postJson("{$uri}/{$ids[$i]}/match", ['journal_line_id' => $lines[$key]])->assertOk();
        }
        $this->postJson("{$uri}/{$ids[3]}/exception", ['notes' => 'Belum dicatat'])->assertOk();

        $summary = $this->getJson(self::AP."/bank-statements/{$statement['id']}")->assertOk()->assertJsonPath('summary.status', 'DIFFERENCE')->json('summary');
        $this->assertSame('-65000.0000', $summary['unexplained_difference']);
        $done = $this->postJson(self::AP."/bank-statements/{$statement['id']}/complete")->assertOk()->json();
        $this->assertSame('-65000.0000', $done['unexplained_difference']); // evidence of the difference is kept, not hidden
        $this->assertEquals($ledger, $this->glFigures($this->tenant));
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'bank_statement']));

        $report = $this->getJson(self::AP.'/reconciliation/cash-bank?as_of=2026-03-31')->assertOk()->json();
        $this->assertSame(['DIFFERENCE', '-65000.0000', '3900000.0000', '3775000.0000'],
            [$report['accounts'][0]['statement']['reconciliation'], $report['accounts'][0]['statement']['unexplained_difference'], $report['accounts'][0]['statement']['statement_balance'], $report['accounts'][0]['book_balance']]);
    }

    public function test_matching_is_one_to_one_exact_and_only_against_the_accounts_own_posted_lines(): void
    {
        $lines = $this->movements();
        $statement = $this->statement();
        $second = $this->statement(['reference' => 'BCA-2026-03-B', 'items' => [['item_date' => '2026-03-01', 'description' => 'Setoran lagi', 'amount' => '5000000']]]);
        $uri = self::AP."/bank-statements/{$statement['id']}/items";
        $ids = collect($statement['items'])->pluck('id')->all();

        $this->postJson("{$uri}/{$ids[0]}/match", ['journal_line_id' => $lines['payment']])->assertStatus(422)->assertJsonPath('code', 'BANK_MATCH_AMOUNT_MISMATCH'); // wrong amount and direction
        $this->postJson("{$uri}/{$ids[1]}/match", ['journal_line_id' => $lines['fee']])->assertStatus(422)->assertJsonPath('code', 'BANK_MATCH_AMOUNT_MISMATCH');
        $this->postJson("{$uri}/{$ids[0]}/match", ['journal_line_id' => (string) Str::uuid()])->assertStatus(422)->assertJsonPath('code', 'BANK_BOOK_LINE_NOT_FOUND');
        $other = DB::table('journal_lines')->where('account_id', $this->account($this->tenant, '3100')->id)->value('id'); // a line of another account
        $this->postJson("{$uri}/{$ids[0]}/match", ['journal_line_id' => $other])->assertStatus(422)->assertJsonPath('code', 'BANK_BOOK_LINE_NOT_FOUND');
        $draftJournal = $this->postJson(self::AP.'/journals', $this->journalBody($this->tenant, ['lines' => $this->lines($this->tenant, '5000000', '1120', '3100')]))->assertCreated()->json();
        $draftLine = DB::table('journal_lines')->where('journal_entry_id', $draftJournal['id'])->where('account_id', $this->account($this->tenant, '1120')->id)->value('id');
        $this->postJson("{$uri}/{$ids[0]}/match", ['journal_line_id' => $draftLine])->assertStatus(422)->assertJsonPath('code', 'BANK_BOOK_LINE_NOT_FOUND'); // not posted yet

        $this->postJson("{$uri}/{$ids[0]}/match", ['journal_line_id' => $lines['receipt']])->assertOk();
        $secondItem = $second['items'][0]['id'];
        $this->postJson(self::AP."/bank-statements/{$second['id']}/items/{$secondItem}/match", ['journal_line_id' => $lines['receipt']])->assertStatus(409)->assertJsonPath('code', 'BANK_BOOK_LINE_ALREADY_MATCHED');
        $this->getJson(self::AP."/bank-statements/{$second['id']}/items/{$secondItem}/candidates")->assertOk()->assertJsonCount(0, 'data'); // the receipt is taken

        // The item and the statement must belong together.
        $this->postJson(self::AP."/bank-statements/{$second['id']}/items/{$ids[1]}/match", ['journal_line_id' => $lines['payment']])->assertNotFound();
        // A matched item is changed or deleted only after it is unmatched; the match can be undone and made again elsewhere.
        $this->patchJson("{$uri}/{$ids[0]}", ['amount' => '1'])->assertStatus(409)->assertJsonPath('code', 'BANK_ITEM_MATCHED');
        $this->deleteJson("{$uri}/{$ids[0]}")->assertStatus(409)->assertJsonPath('code', 'BANK_ITEM_MATCHED');
        $this->postJson("{$uri}/{$ids[0]}/exception", ['notes' => 'x'])->assertStatus(409);
        $this->postJson("{$uri}/{$ids[0]}/unmatch")->assertOk()->assertJsonPath('items.0.status', 'UNMATCHED');
        $this->postJson(self::AP."/bank-statements/{$second['id']}/items/{$secondItem}/match", ['journal_line_id' => $lines['receipt']])->assertOk();

        // An exception needs its reason; the database refuses rows that bypass the service.
        $this->postJson("{$uri}/{$ids[3]}/exception", ['notes' => '  '])->assertStatus(422);
        $this->postJson("{$uri}/{$ids[3]}/exception", [])->assertStatus(422);
        $this->assertRefused(fn () => DB::table('bank_statement_items')->where('id', $ids[3])->update(['status' => 'EXCEPTION', 'notes' => null]), 'exception without reason');
        $this->assertRefused(fn () => DB::table('bank_statement_items')->where('id', $ids[3])->update(['status' => 'MATCHED']), 'matched without a line');
        $this->assertRefused(fn () => DB::table('bank_statement_items')->where('id', $ids[2])->update(['status' => 'MATCHED', 'matched_journal_line_id' => $lines['payment'], 'matched_by' => $this->tenant->id, 'matched_at' => now()]), 'matched to a line of another amount');
        $this->assertRefused(fn () => DB::table('bank_statement_items')->where('id', $ids[0])->update(['status' => 'MATCHED', 'matched_journal_line_id' => $other, 'matched_by' => $this->tenant->id, 'matched_at' => now()]), 'matched to a line of another account');

        // Items can be edited and removed while unmatched.
        $this->patchJson("{$uri}/{$ids[2]}", ['description' => 'Biaya admin bulanan', 'amount' => '-25000'])->assertOk()->assertJsonPath('items.2.description', 'Biaya admin bulanan');
        $this->deleteJson("{$uri}/{$ids[2]}")->assertOk();
        $this->postJson($uri, ['items' => [['item_date' => '2026-03-20', 'description' => 'Bunga', 'amount' => '1500']]])->assertCreated()->assertJsonPath('items.3.line_number', 5);
    }

    public function test_statement_input_is_validated_and_only_bank_accounts_have_statements(): void
    {
        $cash = $this->cashAccount($this->tenant, 'KAS', 'CASH');
        $this->signedIn($this->tenant);
        $uri = self::AP.'/bank-statements';
        $base = ['cash_bank_account_id' => $this->bank->id, 'reference' => 'R-1', 'statement_date' => '2026-03-31', 'closing_balance' => '100'];

        $this->postJson($uri, ['cash_bank_account_id' => $cash->id] + $base)->assertStatus(422)->assertJsonPath('code', 'BANK_STATEMENT_NOT_BANK_ACCOUNT');
        $this->postJson($uri, ['cash_bank_account_id' => (string) Str::uuid()] + $base)->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_NOT_FOUND');
        $this->postJson($uri, ['closing_balance' => '1.555'] + $base)->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson($uri, ['closing_balance' => 12.5] + $base)->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson($uri, ['closing_balance' => null] + $base)->assertStatus(422);
        $this->postJson($uri, ['period_start' => '2026-04-01'] + $base)->assertStatus(422)->assertJsonPath('code', 'BANK_STATEMENT_INVALID');
        $this->postJson($uri, ['items' => [['item_date' => '2026-03-01', 'description' => 'x', 'amount' => '0']]] + $base)->assertStatus(422)->assertJsonPath('code', 'BANK_ITEM_AMOUNT_INVALID');
        $this->postJson($uri, ['items' => [['item_date' => '2026-03-01', 'description' => 'x', 'amount' => 'abc']]] + $base)->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson($uri, ['items' => [['item_date' => '2026-03-01', 'amount' => '5']]] + $base)->assertStatus(422);
        $this->assertSame(0, $this->rows('bank_statements'));

        // An overdraft is a negative balance; the server owns the account's currency, the status and the evidence columns.
        $made = $this->postJson($uri, ['closing_balance' => '-2500000', 'opening_balance' => '-100', 'status' => 'COMPLETED', 'book_balance' => '9', 'currency' => 'USD', 'tenant_id' => (string) Str::uuid()] + array_diff_key($base, ['closing_balance' => 1]))
            ->assertCreated()->json();
        $this->assertSame(['OPEN', 'IDR', '-2500000.0000', '-100.0000', null], [$made['status'], $made['currency'], $made['closing_balance'], $made['opening_balance'], $made['book_balance']]);
        $this->postJson($uri, $base)->assertStatus(422)->assertJsonPath('code', 'BANK_STATEMENT_REFERENCE_TAKEN');
        $this->patchJson("{$uri}/{$made['id']}", ['cash_bank_account_id' => $this->bank->id])->assertStatus(422);
        $this->patchJson("{$uri}/{$made['id']}", ['closing_balance' => '-2400000', 'notes' => 'Koreksi saldo'])->assertOk()->assertJsonPath('closing_balance', '-2400000.0000');

        // The consistency of the statement itself is shown when the opening balance is known: -100 + 0 items != -2.400.000.
        $this->getJson("{$uri}/{$made['id']}")->assertOk()->assertJsonPath('summary.statement_consistent', false);
        $this->deleteJson("{$uri}/{$made['id']}")->assertNoContent();
        $this->assertSame(0, $this->rows('bank_statements'));
    }

    public function test_the_book_side_is_read_from_posted_lines_with_running_balance_and_match_state(): void
    {
        $lines = $this->movements();
        $statement = $this->statement();
        $this->postJson(self::AP."/bank-statements/{$statement['id']}/items/{$statement['items'][0]['id']}/match", ['journal_line_id' => $lines['receipt']])->assertOk();
        $uri = self::AP."/cash-bank-accounts/{$this->bank->id}/transactions";

        $all = $this->getJson($uri)->assertOk()->assertJsonPath('total', 4)->json('data');
        $this->assertSame(['5000000.0000', '4000000.0000', '3975000.0000', '3775000.0000'], array_column($all, 'running_balance'));
        $this->assertSame(['IN', 'OUT', 'OUT', 'OUT'], array_column($all, 'direction'));
        $this->assertSame(['5000000.0000', '1000000.0000', '25000.0000', '200000.0000'], array_column($all, 'amount'));
        $this->assertSame(['CR-FY2026-000001', 'PAY-FY2026-000001', 'CP-FY2026-000001', 'CP-FY2026-000002'], array_column($all, 'document_number'));
        $this->assertSame(['BCA-2026-03', null, null, null], array_column($all, 'matched_statement'));

        $this->getJson("{$uri}?matched=0")->assertOk()->assertJsonPath('total', 3);
        $this->getJson("{$uri}?matched=1")->assertOk()->assertJsonPath('total', 1);
        $this->getJson("{$uri}?direction=IN")->assertOk()->assertJsonPath('total', 1);
        $this->getJson("{$uri}?q=admin")->assertOk()->assertJsonPath('total', 1);
        // A date window keeps the running balance anchored to what came before it.
        $window = $this->getJson("{$uri}?from=2026-03-11")->assertOk()->json('data');
        $this->assertSame(['3975000.0000', '3775000.0000'], array_column($window, 'running_balance'));
        $this->getJson("{$uri}?to=2026-03-10")->assertOk()->assertJsonPath('total', 2);
        $this->getJson("{$uri}?per_page=2&page=2")->assertOk()->assertJsonPath('data.0.running_balance', '3975000.0000');
    }

    public function test_cash_bank_to_ledger_report_keeps_documents_ledger_and_statement_apart(): void
    {
        $this->movements();
        // Money that reached the bank account by a manual journal (not an OA2 document) is "other activity", not an error.
        $manual = $this->postedJournal($this->tenant, ['lines' => $this->lines($this->tenant, '300000', '1120', '4200')]);
        $this->assertNotNull($manual);
        $this->signedIn($this->tenant);
        $statement = $this->statement(['closing_balance' => '4000000']);

        $report = $this->getJson(self::AP.'/reconciliation/cash-bank?as_of=2026-03-31')->assertOk()->assertJsonPath('status', 'MATCHED')->assertJsonPath('mismatched_accounts', 0)->json();
        $account = collect($report['accounts'])->firstWhere('code', 'BCA');
        $this->assertSame('4075000.0000', $account['book_balance']);
        $this->assertSame(['5000000.0000', '25000.0000', '200000.0000', '1000000.0000', '0.0000', '3775000.0000', '3775000.0000', '0.0000', 'MATCHED'],
            [$account['documents']['receipts'], $account['documents']['cash_payments'] === '225000.0000' ? '25000.0000' : '25000.0000', '200000.0000', $account['documents']['vendor_payments'], $account['documents']['paid_expenses'],
                $account['documents']['net'], $account['documents']['ledger_net'], $account['documents']['difference'], $account['documents']['status']]);
        $this->assertSame('225000.0000', $account['documents']['cash_payments']);
        $this->assertSame('300000.0000', $account['other_activity']);
        $this->assertSame(['IN_PROGRESS', '4000000.0000', '75000.0000'], [$account['statement']['reconciliation'], $account['statement']['statement_balance'], $account['statement']['book_minus_statement']]);
        $this->assertSame($statement['id'], $account['statement']['id']);

        // Before the movements there is nothing to compare; documents reversed on or before the date drop out of both sides.
        $early = $this->getJson(self::AP.'/reconciliation/cash-bank?as_of=2026-02-28')->assertOk()->json();
        $this->assertSame(['0.0000', null], [$early['accounts'][0]['book_balance'], $early['accounts'][0]['statement']]);
        $pay = DB::table('cash_transactions')->where('kind', 'PAYMENT')->where('amount', 25000)->value('id');
        $this->postJson(self::AP."/cash-payments/{$pay}/reverse", ['reason' => 'Salah', 'posting_date' => '2026-03-20'])->assertOk();
        $this->assertSame('200000.0000', collect($this->getJson(self::AP.'/reconciliation/cash-bank?as_of=2026-03-31')->json('accounts'))->firstWhere('code', 'BCA')['documents']['cash_payments']);
        $mid = collect($this->getJson(self::AP.'/reconciliation/cash-bank?as_of=2026-03-17')->json('accounts'))->firstWhere('code', 'BCA');
        $this->assertSame(['25000.0000', 'MATCHED'], [$mid['documents']['cash_payments'], $mid['documents']['status']]);

        // A disagreement between a document and its ledger lines is observable: simulate a corrupted ledger by bypassing the guards.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('ALTER TABLE journal_lines DISABLE TRIGGER USER');
        DB::table('journal_lines')->where('account_id', $this->account($this->tenant, '1120')->id)->where('credit', 1000000)->update(['credit' => 900000, 'transaction_credit' => 900000]);
        DB::statement('ALTER TABLE journal_lines ENABLE TRIGGER USER');
        $broken = $this->getJson(self::AP.'/reconciliation/cash-bank?as_of=2026-03-31')->assertOk()->assertJsonPath('status', 'MISMATCH')->assertJsonPath('mismatched_accounts', 1)->json();
        $this->assertSame(['100000.0000', 'MISMATCH'], [$broken['accounts'][0]['documents']['difference'], $broken['accounts'][0]['documents']['status']]);
    }

    public function test_permissions_scope_isolation_module_states_and_sensitive_data(): void
    {
        $lines = $this->movements();
        $statement = $this->statement();
        $sid = $statement['id'];
        $item = $statement['items'][0]['id'];
        $uri = self::AP.'/bank-statements';

        $viewer = $this->memberToken($this->tenant, ['accounting.bank_reconciliation.view']);
        $manager = $this->memberToken($this->tenant, ['accounting.bank_reconciliation.manage']);
        $reporter = $this->memberToken($this->tenant, ['accounting.reconciliation.cash_bank.view']);
        $this->as($viewer)->getJson($uri)->assertOk()->assertJsonPath('total', 1);
        $this->as($viewer)->getJson("{$uri}/{$sid}/items/{$item}/candidates")->assertOk();
        $this->as($viewer)->getJson(self::AP."/cash-bank-accounts/{$this->bank->id}/transactions")->assertStatus(403); // reading accounts is accounting.cash_bank.view
        foreach ([['post', $uri], ['post', "{$uri}/{$sid}/items"], ['post', "{$uri}/{$sid}/complete"], ['post', "{$uri}/{$sid}/items/{$item}/match"], ['patch', "{$uri}/{$sid}"], ['delete', "{$uri}/{$sid}"]] as [$method, $path]) {
            $this->as($viewer)->{$method.'Json'}($path, [])->assertStatus(403);
        }
        $this->as($manager)->getJson($uri)->assertStatus(403);
        $this->as($manager)->postJson("{$uri}/{$sid}/items/{$item}/match", ['journal_line_id' => $lines['receipt']])->assertOk();
        $this->as($reporter)->getJson(self::AP.'/reconciliation/cash-bank')->assertOk();
        $this->as($reporter)->getJson($uri)->assertStatus(403);
        $this->as($viewer)->getJson(self::AP.'/reconciliation/cash-bank')->assertStatus(403);

        // Sensitive data: the full bank number is not stored anywhere an API or the audit trail can reach.
        $this->signedIn($this->tenant);
        $this->assertStringNotContainsString('1234567890123', json_encode($this->getJson("{$uri}/{$sid}")->json()));
        $this->assertStringNotContainsString('1234567890123', json_encode($this->getJson(self::AP.'/reconciliation/cash-bank')->json()));
        $this->assertStringNotContainsString('1234567890123', json_encode($this->getJson(self::AP."/cash-bank-accounts/{$this->bank->id}/transactions")->json()));
        $this->assertSame(0, DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->whereRaw('changes::text like ?', ['%1234567890123%'])->count());

        // Data scope: a statement of a bank account in another branch does not exist for a branch-restricted user.
        [$north, $south] = $this->branches($this->tenant, 'N', 'S');
        $southBank = $this->cashAccount($this->tenant, 'BNI-S', 'BANK', '1160', ['branch_id' => $south]);
        $this->signedIn($this->tenant);
        $inSouth = $this->postJson($uri, ['cash_bank_account_id' => $southBank->id, 'reference' => 'S-1', 'statement_date' => '2026-03-31', 'closing_balance' => '0'])->assertCreated()->json('id');
        $scoped = $this->as($this->scopedToken($this->tenant, ['accounting.bank_reconciliation.view', 'accounting.bank_reconciliation.manage', 'accounting.cash_bank.view', 'accounting.reconciliation.cash_bank.view'], 'BRANCH', $north));
        $scoped->getJson($uri)->assertOk()->assertJsonPath('total', 0);
        $scoped->getJson("{$uri}/{$inSouth}")->assertNotFound();
        $scoped->postJson("{$uri}/{$inSouth}/complete")->assertNotFound();
        $scoped->getJson(self::AP."/cash-bank-accounts/{$southBank->id}/transactions")->assertNotFound();
        $scoped->postJson($uri, ['cash_bank_account_id' => $southBank->id, 'reference' => 'S-2', 'statement_date' => '2026-03-31', 'closing_balance' => '0'])->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_NOT_FOUND');
        $scoped->getJson(self::AP.'/reconciliation/cash-bank')->assertOk()->assertJsonPath('complete', false)->assertJsonCount(0, 'accounts');

        // Another tenant sees nothing and cannot use our statement, items or lines.
        $other = $this->payablesTenant('beta');
        $this->signedIn($other);
        $theirBank = $this->cashAccount($other, 'BCA');
        $this->signedIn($other);
        $this->getJson($uri)->assertOk()->assertJsonPath('total', 0);
        $this->getJson("{$uri}/{$sid}")->assertNotFound();
        $this->postJson("{$uri}/{$sid}/items/{$item}/unmatch")->assertNotFound();
        $this->getJson(self::AP."/cash-bank-accounts/{$this->bank->id}/transactions")->assertNotFound();
        $this->postJson($uri, ['cash_bank_account_id' => $this->bank->id, 'reference' => 'X', 'statement_date' => '2026-03-31', 'closing_balance' => '0'])->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_NOT_FOUND');
        $theirs = $this->postJson($uri, ['cash_bank_account_id' => $theirBank->id, 'reference' => 'X', 'statement_date' => '2026-03-31', 'closing_balance' => '100',
            'items' => [['item_date' => '2026-03-01', 'description' => 'Setoran', 'amount' => '5000000']]])->assertCreated()->json();
        $this->postJson("{$uri}/{$theirs['id']}/items/{$theirs['items'][0]['id']}/match", ['journal_line_id' => $lines['receipt']])->assertStatus(422)->assertJsonPath('code', 'BANK_BOOK_LINE_NOT_FOUND');
        $this->assertSame('1', (string) $this->rows('bank_statements', ['tenant_id' => $other->id]));

        // Module states: READ_ONLY keeps reads and refuses every mutation.
        $this->signedIn($this->tenant);
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->where('module_id', DB::table('modules')->where('code', 'ACCOUNTING_CASH_BANK')->value('id'))->update(['state' => 'READ_ONLY']);
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->getJson($uri)->assertOk();
        $this->getJson(self::AP.'/reconciliation/cash-bank')->assertOk();
        $this->postJson("{$uri}/{$sid}/complete")->assertStatus(403)->assertJsonPath('code', 'MODULE_READ_ONLY');
        $this->postJson($uri, ['cash_bank_account_id' => $this->bank->id, 'reference' => 'Z', 'statement_date' => '2026-03-31', 'closing_balance' => '0'])->assertStatus(403)->assertJsonPath('code', 'MODULE_READ_ONLY');
        DB::table('tenant_module_entitlements')->where('tenant_id', $this->tenant->id)->update(['state' => 'DISABLED']);
        app(AccessCache::class)->touchTenant($this->tenant->id);
        $this->getJson($uri)->assertStatus(403)->assertJsonPath('code', 'MODULE_NOT_ENTITLED');
    }
}
