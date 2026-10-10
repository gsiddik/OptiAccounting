<?php

namespace Tests\Concurrency;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\RaceHelpers;
use Tests\Support\RaceRunner;

/**
 * OA2 release gate (brief section 47): the payables, expense and cash/bank invariants under genuinely concurrent requests.
 * Workers are separate PHP processes hitting the real application, so the database sees real parallel transactions. After every
 * race the books are checked as a whole: journals balance, the AP control account equals the subledger, no invoice is
 * over-settled, no document number is issued twice or skipped, nothing was posted or reversed twice.
 */
class OperationalConcurrencyTest extends ConcurrencyTestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, RaceHelpers;

    private Tenant $tenant;

    private string $token;

    private object $vendor;

    private string $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->payablesTenant('alpha', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false, 'sod_approver_not_poster' => false]);
        [$user] = $this->member($this->tenant);
        $this->token = $this->tenantToken($user, $this->tenant);
        $this->vendor = $this->vendor($this->tenant, 'V1');
        $this->as($this->token);
        $this->bank = $this->cashAccount($this->tenant, 'BCA')->id;
        $this->as($this->token);
        $this->fund();
    }

    private function http()
    {
        return $this->as($this->token);
    }

    /** Money in the bank so that payments have something to draw on (the books do not enforce a balance, but reports read better). */
    private function fund(): void
    {
        $id = $this->http()->postJson(self::AP.'/cash-receipts', ['cash_bank_account_id' => $this->bank, 'counter_account_id' => $this->account($this->tenant, '3100')->id, 'amount' => '50000000',
            'transaction_date' => '2026-03-01', 'purpose' => 'Modal', 'description' => 'Modal awal'])->assertCreated()->json('id');
        $this->http()->postJson(self::AP."/cash-receipts/{$id}/post")->assertOk();
    }

    private function approvedInvoice(string $amount = '1000000', array $override = []): string
    {
        $id = $this->http()->postJson(self::AP.'/ap-invoices', $this->invoiceBody($this->vendor, ['lines' => [['description' => 'Jasa', 'amount' => $amount]]] + $override))->assertCreated()->json('id');
        $this->http()->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
        $this->http()->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();

        return $id;
    }

    private function postedInv(string $amount = '1000000'): string
    {
        $id = $this->approvedInvoice($amount);
        $this->http()->postJson(self::AP."/ap-invoices/{$id}/post")->assertOk();

        return $id;
    }

    private function approvedPayment(array $allocations, array $override = []): string
    {
        $id = $this->http()->postJson(self::AP.'/vendor-payments', $this->paymentBody($this->vendor, $this->bank, $allocations, $override))->assertCreated()->json('id');
        $this->http()->postJson(self::AP."/vendor-payments/{$id}/submit")->assertOk();
        $this->http()->postJson(self::AP."/vendor-payments/{$id}/approve")->assertOk();

        return $id;
    }

    private function postedPay(array $allocations): string
    {
        $id = $this->approvedPayment($allocations);
        $this->http()->postJson(self::AP."/vendor-payments/{$id}/post")->assertOk();

        return $id;
    }

    /** The whole book is still sound after a race. */
    private function assertBooksSound(): void
    {
        $tenant = $this->tenant->id;
        $unbalanced = DB::table('journal_entries as j')->join('journal_lines as l', 'l.journal_entry_id', '=', 'j.id')->where('j.tenant_id', $tenant)->where('j.status', 'POSTED')
            ->groupBy('j.id')->havingRaw('sum(l.debit) <> sum(l.credit)')->select('j.id')->get()->count();
        $this->assertSame(0, $unbalanced, 'every posted journal balances');

        // The payables control account equals what the subledger says is owed, and no invoice is settled for more than it is worth.
        $owed = DB::selectOne("select coalesce(sum(i.total_amount - coalesce((select sum(a.amount) from ap_payment_allocations a join vendor_payments p on p.id = a.vendor_payment_id
            where a.ap_invoice_id = i.id and a.is_effective and p.status = 'POSTED'), 0)), 0) as owed from ap_invoices i where i.tenant_id = ? and i.status = 'POSTED'", [$tenant])->owed;
        $this->assertSame(number_format((float) $owed, 4, '.', ''), number_format(-1 * (float) $this->glBalance($this->tenant, '2110'), 4, '.', ''), 'AP control account equals the subledger');
        $over = DB::selectOne('select count(*) as n from ap_invoices i where i.tenant_id = ? and i.total_amount < coalesce((select sum(a.amount) from ap_payment_allocations a where a.ap_invoice_id = i.id and a.is_effective), 0)', [$tenant])->n;
        $this->assertSame(0, (int) $over, 'no invoice is settled for more than its total');

        foreach (['ap_invoices' => 'journal_entry_id', 'vendor_payments' => 'journal_entry_id', 'expenses' => 'journal_entry_id', 'cash_transactions' => 'journal_entry_id'] as $table => $column) {
            $this->assertSame(0, DB::table($table)->where('tenant_id', $tenant)->whereNotNull($column)->groupBy($column)->havingRaw('count(*) > 1')->select($column)->get()->count(), "{$table}: one journal per document");
        }
    }

    /** @return list<string> the numbers issued for a document type, in order */
    private function numbers(string $table, array $where = []): array
    {
        return DB::table($table)->where('tenant_id', $this->tenant->id)->where($where)->whereNotNull('document_number')->orderBy('document_number')->pluck('document_number')->all();
    }

    // ------------------------------------------------------------------------------------------ vendor invoices

    public function test_the_same_approved_invoice_posted_by_several_requests_at_once_is_posted_exactly_once(): void
    {
        $id = $this->approvedInvoice();

        $results = (new RaceRunner)->start(array_fill(0, 6, $this->job('POST', self::AP."/ap-invoices/{$id}/post")))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, 'exactly one request posts: '.json_encode($outcomes));
        $this->assertSame(5, array_sum($outcomes) - 1);
        $invoice = DB::table('ap_invoices')->where('id', $id)->first();
        $this->assertSame('POSTED', $invoice->status);
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'ap_invoice')->where('source_id', $id)->count());
        $this->assertSame(1, DB::table('document_transitions')->where('document_id', $id)->where('to_status', 'POSTED')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'payables.ap_invoice.posted')->count());
        $this->assertSame('-1000000.0000', $this->glBalance($this->tenant, '2110'), 'the payable was recognised once');
        $this->assertBooksSound();
    }

    public function test_invoices_posted_in_parallel_get_unique_gapless_numbers(): void
    {
        $ids = array_map(fn () => $this->approvedInvoice('100000'), range(1, 8));

        $results = (new RaceRunner)->start(array_map(fn ($id) => $this->job('POST', self::AP."/ap-invoices/{$id}/post"), $ids), delay: 4.0)->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['200:' => 8], $this->outcomes($results));
        $numbers = $this->numbers('ap_invoices');
        $this->assertCount(8, array_unique($numbers));
        $first = (int) substr($numbers[0], -6);
        $this->assertSame(range($first, $first + 7), array_map(fn ($n) => (int) substr($n, -6), $numbers), 'no gap and no duplicate');
        $this->assertBooksSound();
    }

    public function test_a_posted_invoice_reversed_by_several_requests_is_reversed_once(): void
    {
        $id = $this->postedInv();

        $results = (new RaceRunner)->start(array_map(fn ($n) => $this->job('POST', self::AP."/ap-invoices/{$id}/reverse", ['reason' => "Koreksi {$n}", 'posting_date' => '2026-03-20']), range(1, 5)))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame('REVERSED', DB::table('ap_invoices')->where('id', $id)->value('status'));
        $this->assertSame(1, DB::table('journal_entries')->where('reverses_journal_id', DB::table('ap_invoices')->where('id', $id)->value('journal_entry_id'))->count());
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '2110'));
        $this->assertBooksSound();
    }

    // ------------------------------------------------------------------------------------------ vendor payments

    public function test_two_payments_cannot_settle_the_same_remaining_invoice_balance(): void
    {
        $invoice = $this->postedInv('1000000');
        $a = $this->approvedPayment([$invoice => '700000']);
        $b = $this->approvedPayment([$invoice => '700000']);

        $results = (new RaceRunner)->start([$this->job('POST', self::AP."/vendor-payments/{$a}/post"), $this->job('POST', self::AP."/vendor-payments/{$b}/post")])->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame(['AP_ALLOCATION_EXCEEDS_OUTSTANDING'], array_values(array_filter(array_map(fn ($r) => $r['body']['code'] ?? null, $results))));
        $this->assertSame(1, DB::table('vendor_payments')->whereIn('id', [$a, $b])->where('status', 'POSTED')->count());
        $this->assertSame('300000.0000', number_format((float) DB::table('ap_invoices')->where('id', $invoice)->value('total_amount') - (float) DB::table('ap_payment_allocations')->where('ap_invoice_id', $invoice)->where('is_effective', true)->sum('amount'), 4, '.', ''));
        $this->assertBooksSound();
    }

    public function test_many_payments_never_settle_an_invoice_for_more_than_it_is_worth(): void
    {
        $invoice = $this->postedInv('1000000');
        $payments = array_map(fn () => $this->approvedPayment([$invoice => '200000']), range(1, 7));

        $results = (new RaceRunner)->start(array_map(fn ($id) => $this->job('POST', self::AP."/vendor-payments/{$id}/post"), $payments), delay: 4.0)->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(['200:' => 5, '409:AP_ALLOCATION_EXCEEDS_OUTSTANDING' => 2], $outcomes);
        $this->assertSame('0.0000', number_format((float) DB::table('ap_invoices')->where('id', $invoice)->value('total_amount') - (float) DB::table('ap_payment_allocations')->where('ap_invoice_id', $invoice)->where('is_effective', true)->sum('amount'), 4, '.', ''));
        $this->assertSame(5, count($this->numbers('vendor_payments')));
        $this->assertSame(['PAY-FY2026-000001', 'PAY-FY2026-000002', 'PAY-FY2026-000003', 'PAY-FY2026-000004', 'PAY-FY2026-000005'], $this->numbers('vendor_payments'), 'only posted payments consumed a number, without gaps');
        $this->assertBooksSound();
    }

    public function test_the_same_approved_payment_posted_several_times_at_once_pays_once(): void
    {
        $invoice = $this->postedInv('1000000');
        $payment = $this->approvedPayment([$invoice => '400000']);

        $results = (new RaceRunner)->start(array_fill(0, 6, $this->job('POST', self::AP."/vendor-payments/{$payment}/post")))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'vendor_payment')->where('source_id', $payment)->count());
        $this->assertSame(1, DB::table('ap_payment_allocations')->where('vendor_payment_id', $payment)->where('is_effective', true)->count());
        $this->assertSame('-600000.0000', $this->glBalance($this->tenant, '2110'));
        $this->assertSame('49600000.0000', $this->glBalance($this->tenant, '1120'));
        $this->assertBooksSound();
    }

    public function test_a_payment_reversed_by_several_requests_releases_its_allocations_once(): void
    {
        $invoice = $this->postedInv('1000000');
        $payment = $this->postedPay([$invoice => '400000']);

        $results = (new RaceRunner)->start(array_map(fn ($n) => $this->job('POST', self::AP."/vendor-payments/{$payment}/reverse", ['reason' => "Dobel {$n}", 'posting_date' => '2026-03-25']), range(1, 5)))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame('REVERSED', DB::table('vendor_payments')->where('id', $payment)->value('status'));
        $this->assertSame(0, DB::table('ap_payment_allocations')->where('vendor_payment_id', $payment)->where('is_effective', true)->count());
        $this->assertSame('-1000000.0000', $this->glBalance($this->tenant, '2110'), 'the invoice is owed in full again, once');
        $this->assertSame('50000000.0000', $this->glBalance($this->tenant, '1120'));
        $this->assertBooksSound();
    }

    public function test_reversing_an_invoice_while_a_payment_is_being_posted_never_leaves_a_payment_without_its_invoice(): void
    {
        $pairs = [];
        $jobs = [];
        foreach (range(1, 4) as $n) {
            $invoice = $this->postedInv('500000');
            $payment = $this->approvedPayment([$invoice => '500000']);
            $pairs[] = [$invoice, $payment];
            $jobs[] = $this->job('POST', self::AP."/vendor-payments/{$payment}/post");
            $jobs[] = $this->job('POST', self::AP."/ap-invoices/{$invoice}/reverse", ['reason' => 'Salah faktur', 'posting_date' => '2026-03-25']);
        }

        $results = (new RaceRunner)->start($jobs, delay: 4.0)->results();

        $this->assertNoServerErrors($results);
        foreach ($pairs as $i => [$invoice, $payment]) {
            [$pay, $reverse] = [$results[$i * 2], $results[$i * 2 + 1]];
            $paid = DB::table('vendor_payments')->where('id', $payment)->value('status') === 'POSTED';
            $reversed = DB::table('ap_invoices')->where('id', $invoice)->value('status') === 'REVERSED';
            $this->assertNotSame($paid, $reversed, 'exactly one of the two wins (pair '.$i.')');
            $this->assertSame($paid ? 200 : 4, $paid ? $pay['status'] : intdiv($pay['status'], 100), json_encode([$pay, $reverse]));
            if ($paid) {
                $this->assertSame('AP_INVOICE_HAS_PAYMENTS', $reverse['body']['code'], 'the invoice refuses to be reversed under a payment');
            } else {
                $this->assertSame(200, $reverse['status']);
                $this->assertContains($pay['body']['code'], ['AP_INVOICE_NOT_PAYABLE', 'AP_ALLOCATION_INVALID', 'AP_INVOICE_NOT_FOUND'], 'the payment refuses an invoice that is no longer open');
            }
        }
        $this->assertBooksSound();
    }

    // ------------------------------------------------------------------------------------------ expenses

    public function test_a_payable_expense_posted_several_times_at_once_creates_one_payable(): void
    {
        $category = $this->expenseCategory($this->tenant, 'UTIL', ['account_code' => '6300']);
        $this->http();
        $expense = $this->approvedExpense($this->payableExpenseBody($category->id, $this->vendor));

        $results = (new RaceRunner)->start(array_fill(0, 6, $this->job('POST', self::AP."/expenses/{$expense}/post")))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame(1, DB::table('ap_invoices')->where('source_type', 'expense')->where('source_id', $expense)->count(), 'one payable for one expense');
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'expense')->where('source_id', $expense)->count());
        $this->assertSame('-1110000.0000', $this->glBalance($this->tenant, '2110'));
        $this->assertBooksSound();
    }

    public function test_directly_paid_expenses_posted_in_parallel_draw_the_bank_once_each_with_gapless_numbers(): void
    {
        $category = $this->expenseCategory($this->tenant, 'PARK', ['account_code' => '6900']);
        $this->http();
        $ids = array_map(fn () => $this->approvedExpense($this->paidExpenseBody($category->id, $this->bank)), range(1, 6));
        $jobs = array_map(fn ($id) => $this->job('POST', self::AP."/expenses/{$id}/post"), $ids);
        $jobs[] = $this->job('POST', self::AP."/expenses/{$ids[0]}/post"); // and one replay of the first

        $results = (new RaceRunner)->start($jobs, delay: 4.0)->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(6, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame('48800000.0000', $this->glBalance($this->tenant, '1120'), '6 x 200.000 left the bank, once each');
        $numbers = $this->numbers('expenses');
        $first = (int) substr($numbers[0], -6);
        $this->assertSame(range($first, $first + 5), array_map(fn ($n) => (int) substr($n, -6), $numbers));
        $this->assertBooksSound();
    }

    // ------------------------------------------------------------------------------------------ cash transactions

    public function test_cash_transactions_post_once_and_take_unique_numbers_in_their_own_sequences(): void
    {
        $payments = $receipts = [];
        foreach (range(1, 4) as $n) {
            $payments[] = $this->http()->postJson(self::AP.'/cash-payments', ['cash_bank_account_id' => $this->bank, 'counter_account_id' => $this->account($this->tenant, '6900')->id, 'amount' => '10000',
                'transaction_date' => '2026-03-05', 'purpose' => "Biaya {$n}", 'description' => "Biaya {$n}"])->assertCreated()->json('id');
            $receipts[] = $this->http()->postJson(self::AP.'/cash-receipts', ['cash_bank_account_id' => $this->bank, 'counter_account_id' => $this->account($this->tenant, '3100')->id, 'amount' => '20000',
                'transaction_date' => '2026-03-05', 'purpose' => "Setoran {$n}", 'description' => "Setoran {$n}"])->assertCreated()->json('id');
        }
        $jobs = [];
        foreach ($payments as $id) {
            $jobs[] = $this->job('POST', self::AP."/cash-payments/{$id}/post");
        }
        foreach ($receipts as $id) {
            $jobs[] = $this->job('POST', self::AP."/cash-receipts/{$id}/post");
        }
        $jobs[] = $this->job('POST', self::AP."/cash-payments/{$payments[0]}/post"); // replays
        $jobs[] = $this->job('POST', self::AP."/cash-receipts/{$receipts[0]}/post");

        $results = (new RaceRunner)->start($jobs, delay: 5.0)->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(8, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame(['CP-FY2026-000001', 'CP-FY2026-000002', 'CP-FY2026-000003', 'CP-FY2026-000004'], $this->numbers('cash_transactions', ['kind' => 'PAYMENT']));
        $this->assertSame(['CR-FY2026-000001', 'CR-FY2026-000002', 'CR-FY2026-000003', 'CR-FY2026-000004', 'CR-FY2026-000005'], $this->numbers('cash_transactions', ['kind' => 'RECEIPT']), 'gapless in the receipt sequence');
        $this->assertSame('50040000.0000', $this->glBalance($this->tenant, '1120'), '50.000.000 - 4 x 10.000 + 4 x 20.000');
        $this->assertBooksSound();
    }

    // ------------------------------------------------------------------------------------------ bank reconciliation

    /** @return array{statement:string,items:list<string>,lines:list<string>} an OPEN statement with three deposits and three matching posted book lines */
    private function reconciliationSetup(): array
    {
        $lines = [];
        foreach ([111000, 222000, 333000] as $amount) {
            $id = $this->http()->postJson(self::AP.'/cash-receipts', ['cash_bank_account_id' => $this->bank, 'counter_account_id' => $this->account($this->tenant, '3100')->id, 'amount' => (string) $amount,
                'transaction_date' => '2026-03-05', 'purpose' => 'Setoran', 'description' => 'Setoran'])->assertCreated()->json('id');
            $this->http()->postJson(self::AP."/cash-receipts/{$id}/post")->assertOk();
            $lines[] = (string) DB::table('journal_lines')->where('journal_entry_id', DB::table('cash_transactions')->where('id', $id)->value('journal_entry_id'))
                ->where('account_id', $this->account($this->tenant, '1120')->id)->value('id');
        }
        $statement = $this->http()->postJson(self::AP.'/bank-statements', ['cash_bank_account_id' => $this->bank, 'reference' => 'BCA-03', 'statement_date' => '2026-03-31', 'closing_balance' => '50666000',
            'items' => [
                ['item_date' => '2026-03-05', 'description' => 'Setoran 1', 'amount' => '111000'],
                ['item_date' => '2026-03-05', 'description' => 'Setoran 2', 'amount' => '222000'],
                ['item_date' => '2026-03-05', 'description' => 'Setoran 3', 'amount' => '333000'],
                ['item_date' => '2026-03-05', 'description' => 'Setoran 1 lagi', 'amount' => '111000'],
            ]])->assertCreated()->json();

        return ['statement' => $statement['id'], 'items' => array_column($statement['items'], 'id'), 'lines' => $lines];
    }

    public function test_one_book_line_cannot_be_matched_to_two_statement_lines_at_once(): void
    {
        $s = $this->reconciliationSetup();
        $uri = fn ($item) => self::AP."/bank-statements/{$s['statement']}/items/{$item}/match";

        // item 0 and item 3 are both 111.000 deposits; the same book line is offered to both
        $results = (new RaceRunner)->start([$this->job('POST', $uri($s['items'][0]), ['journal_line_id' => $s['lines'][0]]), $this->job('POST', $uri($s['items'][3]), ['journal_line_id' => $s['lines'][0]])])->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['200:' => 1, '409:BANK_BOOK_LINE_ALREADY_MATCHED' => 1], $this->outcomes($results));
        $this->assertSame(1, DB::table('bank_statement_items')->where('matched_journal_line_id', $s['lines'][0])->count());
    }

    public function test_one_statement_line_matched_by_two_requests_with_different_book_lines_keeps_one_match(): void
    {
        $s = $this->reconciliationSetup();
        $other = $this->http()->postJson(self::AP.'/cash-receipts', ['cash_bank_account_id' => $this->bank, 'counter_account_id' => $this->account($this->tenant, '3100')->id, 'amount' => '111000',
            'transaction_date' => '2026-03-06', 'purpose' => 'Setoran lain', 'description' => 'Setoran lain'])->assertCreated()->json('id');
        $this->http()->postJson(self::AP."/cash-receipts/{$other}/post")->assertOk();
        $otherLine = (string) DB::table('journal_lines')->where('journal_entry_id', DB::table('cash_transactions')->where('id', $other)->value('journal_entry_id'))->where('account_id', $this->account($this->tenant, '1120')->id)->value('id');
        $uri = self::AP."/bank-statements/{$s['statement']}/items/{$s['items'][0]}/match";

        $results = (new RaceRunner)->start([$this->job('POST', $uri, ['journal_line_id' => $s['lines'][0]]), $this->job('POST', $uri, ['journal_line_id' => $otherLine])])->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['200:' => 1, '409:BANK_ITEM_MATCHED' => 1], $this->outcomes($results));
        $item = DB::table('bank_statement_items')->where('id', $s['items'][0])->first();
        $this->assertSame('MATCHED', $item->status);
        $this->assertContains($item->matched_journal_line_id, [$s['lines'][0], $otherLine]);
        $this->assertSame(1, DB::table('bank_statement_items')->whereIn('matched_journal_line_id', [$s['lines'][0], $otherLine])->count());
    }

    public function test_completing_a_reconciliation_races_matching_and_unmatching_without_losing_consistency(): void
    {
        $s = $this->reconciliationSetup();
        $uri = fn (string $path) => self::AP."/bank-statements/{$s['statement']}/{$path}";
        $match = fn (int $i) => $this->http()->postJson($uri("items/{$s['items'][$i]}/match"), ['journal_line_id' => $s['lines'][$i]])->assertOk();
        $match(0);
        $match(1);
        $match(2);
        $this->http()->postJson($uri("items/{$s['items'][3]}/exception"), ['notes' => 'Setoran ganda di bank'])->assertOk();

        // Everything is classified; now completing races the unmatching of one line, and another completion.
        $results = (new RaceRunner)->start([
            $this->job('POST', $uri('complete')),
            $this->job('POST', $uri("items/{$s['items'][2]}/unmatch")),
            $this->job('POST', $uri('complete')),
        ], delay: 3.5)->results();

        $this->assertNoServerErrors($results);
        $statement = DB::table('bank_statements')->where('id', $s['statement'])->first();
        $unmatched = DB::table('bank_statement_items')->where('bank_statement_id', $s['statement'])->where('status', 'UNMATCHED')->count();
        if ($statement->status === 'COMPLETED') {
            $this->assertSame(0, $unmatched, 'a completed reconciliation has no unclassified line');
            $this->assertNotNull($statement->unexplained_difference, 'its evidence is frozen');
            $this->assertSame(1, DB::table('audit_logs')->where('action', 'cash_bank.statement.completed')->count(), 'completed once');
        } else {
            $this->assertSame(1, $unmatched, 'the unmatched line is still waiting');
            $this->assertNull($statement->unexplained_difference);
        }
        foreach ($results as $r) {
            if ($r['status'] >= 400) {
                $this->assertContains($r['body']['code'], ['BANK_STATEMENT_HAS_UNMATCHED_ITEMS', 'BANK_STATEMENT_COMPLETED']);
            }
        }
    }
}
