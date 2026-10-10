<?php

namespace Tests\Concurrency;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Receivables\Models\Customer;
use Illuminate\Support\Facades\DB;
use Tests\ConcurrencyTestCase;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\RaceHelpers;
use Tests\Support\RaceRunner;
use Tests\Support\ReceivablesFixtures;

/**
 * OA3 release gate (brief section 62): the receivables invariants under genuinely concurrent requests. Workers are separate PHP processes
 * hitting the real application, so the database sees real parallel transactions. After every race the books are checked as a whole:
 * journals balance, the AR control account equals the subledger, no invoice is over-settled or over-credited, no document number is issued
 * twice or skipped, nothing was posted or reversed twice.
 */
class ReceivablesConcurrencyTest extends ConcurrencyTestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, RaceHelpers, ReceivablesFixtures;

    private Tenant $tenant;

    private string $token;

    private Customer $customer;

    private string $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->receivablesTenant('alpha', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false, 'sod_approver_not_poster' => false]);
        [$user] = $this->member($this->tenant);
        $this->token = $this->tenantToken($user, $this->tenant);
        $this->customer = $this->customer($this->tenant, 'C1');
        $this->as($this->token);
        $this->bank = $this->cashAccount($this->tenant, 'BCA')->id;
        $this->as($this->token);
    }

    private function http()
    {
        return $this->as($this->token);
    }

    private function approvedInvoice(string $amount = '1000000'): string
    {
        $id = $this->http()->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($this->customer, ['lines' => [['description' => 'Jasa', 'amount' => $amount]]]))->assertCreated()->json('id');
        $this->http()->postJson(self::AR."/ar-invoices/{$id}/submit")->assertOk();
        $this->http()->postJson(self::AR."/ar-invoices/{$id}/approve")->assertOk();

        return $id;
    }

    private function postedInv(string $amount = '1000000'): string
    {
        $id = $this->approvedInvoice($amount);
        $this->http()->postJson(self::AR."/ar-invoices/{$id}/post")->assertOk();

        return $id;
    }

    private function approvedReceipt(array $allocations): string
    {
        $id = $this->http()->postJson(self::AR.'/customer-receipts', $this->receiptBody($this->customer, $this->bank, $allocations))->assertCreated()->json('id');
        $this->http()->postJson(self::AR."/customer-receipts/{$id}/submit")->assertOk();
        $this->http()->postJson(self::AR."/customer-receipts/{$id}/approve")->assertOk();

        return $id;
    }

    private function postedRct(array $allocations): string
    {
        $id = $this->approvedReceipt($allocations);
        $this->http()->postJson(self::AR."/customer-receipts/{$id}/post")->assertOk();

        return $id;
    }

    private function approvedNote(string $invoice, string $amount): string
    {
        $id = $this->http()->postJson(self::AR.'/ar-credit-notes', $this->creditNoteBody($invoice, ['lines' => [['description' => 'Retur', 'amount' => $amount]]]))->assertCreated()->json('id');
        $this->http()->postJson(self::AR."/ar-credit-notes/{$id}/submit")->assertOk();
        $this->http()->postJson(self::AR."/ar-credit-notes/{$id}/approve")->assertOk();

        return $id;
    }

    private function postedNote(string $invoice, string $amount): string
    {
        $id = $this->approvedNote($invoice, $amount);
        $this->http()->postJson(self::AR."/ar-credit-notes/{$id}/post")->assertOk();

        return $id;
    }

    private const EFFECTIVE = "select coalesce(sum(a.amount),0) from ar_receipt_allocations a join customer_receipts r on r.id = a.customer_receipt_id where a.ar_invoice_id = i.id and a.is_effective and r.status = 'POSTED'";

    private const CREDITED = "select coalesce(sum(c.total_amount),0) from ar_credit_notes c where c.ar_invoice_id = i.id and c.status = 'POSTED'";

    /** The whole book is still sound after a race. */
    private function assertBooksSound(): void
    {
        $tenant = $this->tenant->id;
        $unbalanced = DB::table('journal_entries as j')->join('journal_lines as l', 'l.journal_entry_id', '=', 'j.id')->where('j.tenant_id', $tenant)->where('j.status', 'POSTED')
            ->groupBy('j.id')->havingRaw('sum(l.debit) <> sum(l.credit)')->select('j.id')->get()->count();
        $this->assertSame(0, $unbalanced, 'every posted journal balances');

        // The receivables control account equals what the subledger says is owed, and no invoice is settled or credited for more than it is worth.
        $owed = DB::selectOne('select coalesce(sum(i.total_amount - ('.self::EFFECTIVE.') - ('.self::CREDITED.")), 0) as owed from ar_invoices i where i.tenant_id = ? and i.status = 'POSTED'", [$tenant])->owed;
        $this->assertSame(number_format((float) $owed, 4, '.', ''), number_format((float) $this->glBalance($this->tenant, '1130'), 4, '.', ''), 'AR control account equals the subledger');
        $over = DB::selectOne('select count(*) as n from ar_invoices i where i.tenant_id = ? and i.total_amount < (('.self::EFFECTIVE.') + ('.self::CREDITED.'))', [$tenant])->n;
        $this->assertSame(0, (int) $over, 'no invoice is settled or credited for more than its total');

        foreach (['ar_invoices', 'customer_receipts', 'ar_credit_notes'] as $table) {
            $this->assertSame(0, DB::table($table)->where('tenant_id', $tenant)->whereNotNull('journal_entry_id')->groupBy('journal_entry_id')->havingRaw('count(*) > 1')->select('journal_entry_id')->get()->count(), "{$table}: one journal per document");
        }
    }

    /** @return list<string> the numbers issued for a document type, in order */
    private function numbers(string $table): array
    {
        return DB::table($table)->where('tenant_id', $this->tenant->id)->whereNotNull('document_number')->orderBy('document_number')->pluck('document_number')->all();
    }

    private function settled(string $invoice): string
    {
        return number_format((float) DB::table('ar_invoices')->where('id', $invoice)->value('total_amount')
            - (float) DB::table('ar_receipt_allocations as a')->join('customer_receipts as r', 'r.id', '=', 'a.customer_receipt_id')->where('a.ar_invoice_id', $invoice)->where('a.is_effective', true)->where('r.status', 'POSTED')->sum('a.amount')
            - (float) DB::table('ar_credit_notes')->where('ar_invoice_id', $invoice)->where('status', 'POSTED')->sum('total_amount'), 4, '.', '');
    }

    // ------------------------------------------------------------------------------------------ invoices

    public function test_the_same_approved_invoice_posted_by_several_requests_at_once_is_posted_exactly_once(): void
    {
        $id = $this->approvedInvoice();

        $results = (new RaceRunner)->start(array_fill(0, 6, $this->job('POST', self::AR."/ar-invoices/{$id}/post")))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, 'exactly one request posts: '.json_encode($outcomes));
        $this->assertSame(5, array_sum($outcomes) - 1);
        $this->assertSame('POSTED', DB::table('ar_invoices')->where('id', $id)->value('status'));
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'ar_invoice')->where('source_id', $id)->count());
        $this->assertSame(1, DB::table('document_transitions')->where('document_id', $id)->where('to_status', 'POSTED')->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'receivables.ar_invoice.posted')->count());
        $this->assertSame('1000000.0000', $this->glBalance($this->tenant, '1130'), 'the receivable was recognised once');
        $this->assertSame('-1000000.0000', $this->glBalance($this->tenant, '4100'), 'and so was the revenue');
        $this->assertBooksSound();
    }

    public function test_invoices_posted_in_parallel_get_unique_gapless_numbers(): void
    {
        $ids = array_map(fn () => $this->approvedInvoice('100000'), range(1, 8));

        $results = (new RaceRunner)->start(array_map(fn ($id) => $this->job('POST', self::AR."/ar-invoices/{$id}/post"), $ids), delay: 4.0)->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['200:' => 8], $this->outcomes($results));
        $numbers = $this->numbers('ar_invoices');
        $this->assertCount(8, array_unique($numbers));
        $first = (int) substr($numbers[0], -6);
        $this->assertSame(range($first, $first + 7), array_map(fn ($n) => (int) substr($n, -6), $numbers), 'no gap and no duplicate');
        $this->assertBooksSound();
    }

    public function test_a_posted_invoice_reversed_by_several_requests_is_reversed_once(): void
    {
        $id = $this->postedInv();

        $results = (new RaceRunner)->start(array_map(fn ($n) => $this->job('POST', self::AR."/ar-invoices/{$id}/reverse", ['reason' => "Koreksi {$n}", 'posting_date' => '2026-03-20']), range(1, 5)))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame('REVERSED', DB::table('ar_invoices')->where('id', $id)->value('status'));
        $this->assertSame(1, DB::table('journal_entries')->where('reverses_journal_id', DB::table('ar_invoices')->where('id', $id)->value('journal_entry_id'))->count());
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1130'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '4100'));
        $this->assertBooksSound();
    }

    // ------------------------------------------------------------------------------------------ customer receipts

    public function test_two_receipts_cannot_settle_the_same_remaining_invoice_balance(): void
    {
        $invoice = $this->postedInv('1000000');
        $a = $this->approvedReceipt([$invoice => '700000']);
        $b = $this->approvedReceipt([$invoice => '700000']);

        $results = (new RaceRunner)->start([$this->job('POST', self::AR."/customer-receipts/{$a}/post"), $this->job('POST', self::AR."/customer-receipts/{$b}/post")])->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame(['AR_ALLOCATION_EXCEEDS_OUTSTANDING'], array_values(array_filter(array_map(fn ($r) => $r['body']['code'] ?? null, $results))));
        $this->assertSame(1, DB::table('customer_receipts')->whereIn('id', [$a, $b])->where('status', 'POSTED')->count());
        $this->assertSame('300000.0000', $this->settled($invoice));
        $this->assertBooksSound();
    }

    public function test_many_receipts_never_settle_an_invoice_for_more_than_it_is_worth(): void
    {
        $invoice = $this->postedInv('1000000');
        $receipts = array_map(fn () => $this->approvedReceipt([$invoice => '200000']), range(1, 7));

        $results = (new RaceRunner)->start(array_map(fn ($id) => $this->job('POST', self::AR."/customer-receipts/{$id}/post"), $receipts), delay: 4.0)->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['200:' => 5, '409:AR_ALLOCATION_EXCEEDS_OUTSTANDING' => 2], $this->outcomes($results));
        $this->assertSame('0.0000', $this->settled($invoice));
        $this->assertSame(['RCT-FY2026-000001', 'RCT-FY2026-000002', 'RCT-FY2026-000003', 'RCT-FY2026-000004', 'RCT-FY2026-000005'], $this->numbers('customer_receipts'), 'only posted receipts consumed a number, without gaps');
        $this->assertSame('1000000.0000', $this->glBalance($this->tenant, '1120'));
        $this->assertBooksSound();
    }

    public function test_the_same_approved_receipt_posted_several_times_at_once_collects_once(): void
    {
        $invoice = $this->postedInv('1000000');
        $receipt = $this->approvedReceipt([$invoice => '400000']);

        $results = (new RaceRunner)->start(array_fill(0, 6, $this->job('POST', self::AR."/customer-receipts/{$receipt}/post")))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'customer_receipt')->where('source_id', $receipt)->count());
        $this->assertSame(1, DB::table('ar_receipt_allocations')->where('customer_receipt_id', $receipt)->where('is_effective', true)->count());
        $this->assertSame('600000.0000', $this->glBalance($this->tenant, '1130'));
        $this->assertSame('400000.0000', $this->glBalance($this->tenant, '1120'));
        $this->assertBooksSound();
    }

    public function test_a_receipt_reversed_by_several_requests_releases_its_allocations_once(): void
    {
        $invoice = $this->postedInv('1000000');
        $receipt = $this->postedRct([$invoice => '400000']);

        $results = (new RaceRunner)->start(array_map(fn ($n) => $this->job('POST', self::AR."/customer-receipts/{$receipt}/reverse", ['reason' => "Dobel {$n}", 'posting_date' => '2026-03-25']), range(1, 5)))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame('REVERSED', DB::table('customer_receipts')->where('id', $receipt)->value('status'));
        $this->assertSame(0, DB::table('ar_receipt_allocations')->where('customer_receipt_id', $receipt)->where('is_effective', true)->count());
        $this->assertSame('1000000.0000', $this->glBalance($this->tenant, '1130'), 'the invoice is owed in full again, once');
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1120'));
        $this->assertBooksSound();
    }

    public function test_reversing_a_receipt_while_the_same_invoice_is_credited_keeps_the_invoice_within_its_total(): void
    {
        $invoice = $this->postedInv('1000000');
        $receipt = $this->postedRct([$invoice => '600000']);
        $note = $this->approvedNote($invoice, '400000');

        $results = (new RaceRunner)->start([
            $this->job('POST', self::AR."/customer-receipts/{$receipt}/reverse", ['reason' => 'Salah bayar', 'posting_date' => '2026-03-25']),
            $this->job('POST', self::AR."/ar-credit-notes/{$note}/post"),
        ], delay: 3.0)->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['200:' => 2], $this->outcomes($results), 'the note fits the outstanding either way');
        $this->assertSame('600000.0000', $this->settled($invoice), 'the receipt released 600.000, the note took 400.000');
        $this->assertBooksSound();
    }

    // ------------------------------------------------------------------------------------------ credit notes

    public function test_a_receipt_and_a_credit_note_cannot_both_take_the_same_outstanding(): void
    {
        $invoice = $this->postedInv('1000000');
        $receipt = $this->approvedReceipt([$invoice => '700000']);
        $note = $this->approvedNote($invoice, '700000');

        $results = (new RaceRunner)->start([
            $this->job('POST', self::AR."/customer-receipts/{$receipt}/post"),
            $this->job('POST', self::AR."/ar-credit-notes/{$note}/post"),
        ])->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertContains(array_values(array_diff(array_keys($outcomes), ['200:']))[0] ?? null, ['409:AR_ALLOCATION_EXCEEDS_OUTSTANDING', '409:AR_CREDIT_NOTE_EXCEEDS_OUTSTANDING']);
        $this->assertSame('300000.0000', $this->settled($invoice));
        $this->assertBooksSound();
    }

    public function test_many_credit_notes_never_credit_an_invoice_for_more_than_it_is_worth(): void
    {
        $invoice = $this->postedInv('1000000');
        $notes = array_map(fn () => $this->approvedNote($invoice, '300000'), range(1, 5));

        $results = (new RaceRunner)->start(array_map(fn ($id) => $this->job('POST', self::AR."/ar-credit-notes/{$id}/post"), $notes), delay: 4.0)->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['200:' => 3, '409:AR_CREDIT_NOTE_EXCEEDS_OUTSTANDING' => 2], $this->outcomes($results));
        $this->assertSame('100000.0000', $this->settled($invoice));
        $this->assertSame(['CN-FY2026-000001', 'CN-FY2026-000002', 'CN-FY2026-000003'], $this->numbers('ar_credit_notes'), 'refused notes consumed no number');
        $this->assertBooksSound();
    }

    public function test_the_same_approved_credit_note_posted_several_times_at_once_credits_once(): void
    {
        $invoice = $this->postedInv('1000000');
        $note = $this->approvedNote($invoice, '250000');

        $results = (new RaceRunner)->start(array_fill(0, 6, $this->job('POST', self::AR."/ar-credit-notes/{$note}/post")))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame(1, DB::table('journal_entries')->where('source_type', 'ar_credit_note')->where('source_id', $note)->count());
        $this->assertSame('750000.0000', $this->glBalance($this->tenant, '1130'));
        $this->assertSame('250000.0000', $this->glBalance($this->tenant, '4150'));
        $this->assertBooksSound();
    }

    public function test_a_credit_note_reversed_by_several_requests_is_reversed_once(): void
    {
        $invoice = $this->postedInv('1000000');
        $note = $this->postedNote($invoice, '250000');

        $results = (new RaceRunner)->start(array_map(fn ($n) => $this->job('POST', self::AR."/ar-credit-notes/{$note}/reverse", ['reason' => "Batal {$n}", 'posting_date' => '2026-03-28']), range(1, 5)))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame('REVERSED', DB::table('ar_credit_notes')->where('id', $note)->value('status'));
        $this->assertSame('1000000.0000', $this->glBalance($this->tenant, '1130'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '4150'));
        $this->assertBooksSound();
    }

    // ------------------------------------------------------------------------------------------ invoice reversal against its dependants

    public function test_reversing_an_invoice_while_a_receipt_is_being_posted_never_leaves_a_receipt_without_its_invoice(): void
    {
        $pairs = [];
        $jobs = [];
        foreach (range(1, 4) as $n) {
            $invoice = $this->postedInv('500000');
            $receipt = $this->approvedReceipt([$invoice => '500000']);
            $pairs[] = [$invoice, $receipt];
            $jobs[] = $this->job('POST', self::AR."/customer-receipts/{$receipt}/post");
            $jobs[] = $this->job('POST', self::AR."/ar-invoices/{$invoice}/reverse", ['reason' => 'Salah faktur', 'posting_date' => '2026-03-25']);
        }

        $results = (new RaceRunner)->start($jobs, delay: 4.0)->results();

        $this->assertNoServerErrors($results);
        foreach ($pairs as $i => [$invoice, $receipt]) {
            [$pay, $reverse] = [$results[$i * 2], $results[$i * 2 + 1]];
            $collected = DB::table('customer_receipts')->where('id', $receipt)->value('status') === 'POSTED';
            $reversed = DB::table('ar_invoices')->where('id', $invoice)->value('status') === 'REVERSED';
            $this->assertNotSame($collected, $reversed, 'exactly one of the two wins (pair '.$i.')');
            if ($collected) {
                $this->assertSame(200, $pay['status'], json_encode([$pay, $reverse]));
                $this->assertSame('AR_INVOICE_HAS_RECEIPTS', $reverse['body']['code'], 'the invoice refuses to be reversed under a receipt');
            } else {
                $this->assertSame(200, $reverse['status']);
                $this->assertContains($pay['body']['code'], ['AR_INVOICE_NOT_PAYABLE', 'AR_ALLOCATION_INVALID', 'AR_INVOICE_NOT_FOUND'], 'the receipt refuses an invoice that is no longer open');
            }
        }
        $this->assertBooksSound();
    }

    public function test_reversing_an_invoice_while_a_credit_note_is_being_posted_never_leaves_a_note_without_its_invoice(): void
    {
        $pairs = [];
        $jobs = [];
        foreach (range(1, 4) as $n) {
            $invoice = $this->postedInv('500000');
            $note = $this->approvedNote($invoice, '100000');
            $pairs[] = [$invoice, $note];
            $jobs[] = $this->job('POST', self::AR."/ar-credit-notes/{$note}/post");
            $jobs[] = $this->job('POST', self::AR."/ar-invoices/{$invoice}/reverse", ['reason' => 'Salah faktur', 'posting_date' => '2026-03-25']);
        }

        $results = (new RaceRunner)->start($jobs, delay: 4.0)->results();

        $this->assertNoServerErrors($results);
        foreach ($pairs as $i => [$invoice, $note]) {
            [$credit, $reverse] = [$results[$i * 2], $results[$i * 2 + 1]];
            $credited = DB::table('ar_credit_notes')->where('id', $note)->value('status') === 'POSTED';
            $reversed = DB::table('ar_invoices')->where('id', $invoice)->value('status') === 'REVERSED';
            $this->assertNotSame($credited, $reversed, 'exactly one of the two wins (pair '.$i.')');
            if ($credited) {
                $this->assertSame('AR_INVOICE_HAS_CREDIT_NOTES', $reverse['body']['code']);
            } else {
                $this->assertSame(200, $reverse['status'], json_encode([$credit, $reverse]));
                $this->assertContains($credit['body']['code'], ['AR_INVOICE_NOT_CREDITABLE', 'AR_CREDIT_NOTE_INVOICE_LOCKED', 'AR_INVOICE_NOT_FOUND']);
            }
        }
        $this->assertBooksSound();
    }

    // ------------------------------------------------------------------------------------------ numbering across documents

    public function test_receipts_credit_notes_and_invoices_number_in_their_own_sequences_when_posted_together(): void
    {
        $invoices = array_map(fn () => $this->postedInv('1000000'), range(1, 3));
        $receipts = array_map(fn ($i) => $this->approvedReceipt([$invoices[$i] => '100000']), range(0, 2));
        $notes = array_map(fn ($i) => $this->approvedNote($invoices[$i], '50000'), range(0, 2));
        $fresh = array_map(fn () => $this->approvedInvoice('100000'), range(1, 3));

        $jobs = [];
        foreach ([...$receipts, ...$notes, ...$fresh] as $k => $id) {
            $path = $k < 3 ? 'customer-receipts' : ($k < 6 ? 'ar-credit-notes' : 'ar-invoices');
            $jobs[] = $this->job('POST', self::AR."/{$path}/{$id}/post");
        }

        $results = (new RaceRunner)->start($jobs, delay: 5.0)->results();

        $this->assertNoServerErrors($results);
        $this->assertSame(['200:' => 9], $this->outcomes($results));
        $this->assertSame(['RCT-FY2026-000001', 'RCT-FY2026-000002', 'RCT-FY2026-000003'], $this->numbers('customer_receipts'));
        $this->assertSame(['CN-FY2026-000001', 'CN-FY2026-000002', 'CN-FY2026-000003'], $this->numbers('ar_credit_notes'));
        $this->assertSame(range(1, 6), array_map(fn ($n) => (int) substr($n, -6), $this->numbers('ar_invoices')), 'six invoices, no gap');
        $this->assertBooksSound();
    }
}
