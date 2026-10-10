<?php

namespace Tests\Feature\Receivables;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\TestCase;

/** OA3 batch E: credit notes reduce a posted invoice's receivable in a controlled way and never touch the invoice itself. */
class ArCreditNoteTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, ReceivablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->receivablesTenant('alpha', ['sod_creator_not_approver' => false]);
        $this->signedIn($this->tenant);
    }

    private function approvedNote(string $invoiceId, array $override = []): string
    {
        $id = $this->postJson(self::AR.'/ar-credit-notes', $this->creditNoteBody($invoiceId, $override))->assertCreated()->json('id');
        $this->postJson(self::AR."/ar-credit-notes/{$id}/submit")->assertOk();
        $this->postJson(self::AR."/ar-credit-notes/{$id}/approve")->assertOk();

        return $id;
    }

    public function test_a_credit_note_posts_through_the_engine_reduces_the_receivable_and_leaves_the_invoice_untouched(): void
    {
        $customer = $this->customer($this->tenant);
        $invoice = $this->arInvoice($customer, '1000000', ['tax_amount' => '110000']);
        $row = DB::table('ar_invoices')->where('id', $invoice['id'])->first();
        $before = $this->glFigures($this->tenant);

        $id = $this->postJson(self::AR.'/ar-credit-notes', $this->creditNoteBody($invoice['id'], ['tax_amount' => '11000']))->assertCreated()
            ->assertJsonPath('status', 'DRAFT')->assertJsonPath('total_amount', '111000.0000')->assertJsonPath('customer_id', $customer->id)->json('id');
        $this->postJson(self::AR."/ar-credit-notes/{$id}/submit")->assertOk();
        $this->postJson(self::AR."/ar-credit-notes/{$id}/approve")->assertOk();
        $this->assertEquals($before, $this->glFigures($this->tenant)); // nothing reaches the ledger before posting
        $this->assertSame('1110000.0000', $this->arOutstanding($invoice['id']));

        $note = $this->postJson(self::AR."/ar-credit-notes/{$id}/post")->assertOk()->assertJsonPath('status', 'POSTED')->json();
        $this->assertMatchesRegularExpression('/^CN-FY2026-\d{6}$/', $note['document_number']);
        $this->assertSame($invoice['receivable_account_id'], $note['receivable_account_id']);

        // Dr revenue adjustment (net) + Dr output VAT, Cr accounts receivable (total).
        $lines = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $note['journal_entry_id'])->orderBy('l.line_number')->get(['a.code', 'l.debit', 'l.credit']);
        $this->assertSame([['4150', '100000.0000', '0.0000'], ['2120', '11000.0000', '0.0000'], ['1130', '0.0000', '111000.0000']], $lines->map(fn ($l) => [$l->code, $l->debit, $l->credit])->all());
        $journal = DB::table('journal_entries')->where('id', $note['journal_entry_id'])->first();
        $this->assertSame('ar_credit_note', $journal->source_type);
        $this->assertSame($id, $journal->source_id);
        $this->assertSame('AR-CREDIT-NOTE', json_decode($journal->posting_snapshot, true)['rule']['code']);
        $this->assertSame(3, (int) $this->glFigures($this->tenant)->lines - (int) $before->lines);
        $this->assertSame('999000.0000', $this->glBalance($this->tenant, '1130'));

        // The invoice is exactly as it was; only its derived outstanding moved.
        $this->assertEquals($row, DB::table('ar_invoices')->where('id', $invoice['id'])->first());
        $shown = $this->getJson(self::AR."/ar-invoices/{$invoice['id']}")->assertOk()->json();
        $this->assertSame(['0.0000', '111000.0000', '999000.0000', 'PARTIALLY_PAID'], [$shown['received_amount'], $shown['credited_amount'], $shown['outstanding_amount'], $shown['payment_status']]);
        $this->assertSame([$note['id']], array_column($shown['credit_notes'], 'id'));

        $this->postJson(self::AR."/ar-credit-notes/{$id}/post")->assertStatus(409); // a replay changes nothing
        $this->assertSame(3, (int) $this->glFigures($this->tenant)->lines - (int) $before->lines);
    }

    public function test_a_note_that_credits_the_whole_invoice_settles_it(): void
    {
        $customer = $this->customer($this->tenant);
        $invoice = $this->arInvoice($customer, '400000');
        $this->postedCreditNote($invoice['id'], ['lines' => [['description' => 'Pembatalan', 'amount' => '400000']]]);

        $shown = $this->getJson(self::AR."/ar-invoices/{$invoice['id']}")->assertOk()->json();
        $this->assertSame(['0.0000', 'PAID'], [$shown['outstanding_amount'], $shown['payment_status']]);
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1130'));
        $this->getJson(self::AR.'/ar-invoices?open=1')->assertOk()->assertJsonPath('total', 0);
        $this->getJson(self::AR."/customers/{$customer->id}/open-invoices")->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_a_note_cannot_take_more_than_is_outstanding_or_more_than_the_invoice_recognised(): void
    {
        $customer = $this->customer($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->arInvoice($customer, '1000000', ['tax_amount' => '110000']);
        $uri = self::AR.'/ar-credit-notes';

        $this->postJson($uri, $this->creditNoteBody($invoice['id'], ['lines' => [['description' => 'x', 'amount' => '1200000']]]))->assertStatus(409)->assertJsonPath('code', 'AR_CREDIT_NOTE_EXCEEDS_OUTSTANDING');
        $this->postJson($uri, $this->creditNoteBody($invoice['id'], ['lines' => [['description' => 'x', 'amount' => '1000000.01']], 'tax_amount' => '0']))->assertStatus(409)->assertJsonPath('code', 'AR_CREDIT_NOTE_EXCEEDS_INVOICE'); // more revenue than recognised
        $this->postJson($uri, $this->creditNoteBody($invoice['id'], ['tax_amount' => '110000.01']))->assertStatus(409)->assertJsonPath('code', 'AR_CREDIT_NOTE_EXCEEDS_INVOICE'); // more tax than recognised
        $this->assertSame(0, DB::table('ar_credit_notes')->count());

        // What a receipt took is no longer available to a note.
        $this->postedReceipt($customer, $bank->id, [$invoice['id'] => '1000000']);
        $this->postJson($uri, $this->creditNoteBody($invoice['id'], ['lines' => [['description' => 'x', 'amount' => '200000']]]))->assertStatus(409)->assertJsonPath('code', 'AR_CREDIT_NOTE_EXCEEDS_OUTSTANDING');
        $this->postJson($uri, $this->creditNoteBody($invoice['id'], ['lines' => [['description' => 'x', 'amount' => '110000']], 'tax_amount' => '0']))->assertCreated();
    }

    public function test_a_note_and_a_receipt_cannot_both_take_the_last_of_an_invoice(): void
    {
        $customer = $this->customer($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->arInvoice($customer, '1000000');

        // Both pass the draft check; whichever posts second is refused with nothing written.
        $note = $this->approvedNote($invoice['id'], ['lines' => [['description' => 'Retur', 'amount' => '600000']]]);
        $receiptId = $this->postJson(self::AR.'/customer-receipts', $this->receiptBody($customer, $bank->id, [$invoice['id'] => '600000']))->assertCreated()->json('id');
        $this->postJson(self::AR."/customer-receipts/{$receiptId}/submit")->assertOk();
        $this->postJson(self::AR."/customer-receipts/{$receiptId}/approve")->assertOk();

        $this->postJson(self::AR."/ar-credit-notes/{$note}/post")->assertOk();
        $figures = $this->glFigures($this->tenant);
        $this->postJson(self::AR."/customer-receipts/{$receiptId}/post")->assertStatus(409)->assertJsonPath('code', 'AR_ALLOCATION_EXCEEDS_OUTSTANDING');
        $this->assertEquals($figures, $this->glFigures($this->tenant));
        $this->assertSame('APPROVED', DB::table('customer_receipts')->where('id', $receiptId)->value('status'));
        $this->assertSame('400000.0000', $this->arOutstanding($invoice['id']));

        // The other order: the receipt first, then the note finds only what is left.
        $second = $this->arInvoice($customer, '500000');
        $note2 = $this->approvedNote($second['id'], ['lines' => [['description' => 'Retur', 'amount' => '300000']]]);
        $this->postedReceipt($customer, $bank->id, [$second['id'] => '300000']);
        $this->postJson(self::AR."/ar-credit-notes/{$note2}/post")->assertStatus(409)->assertJsonPath('code', 'AR_CREDIT_NOTE_EXCEEDS_OUTSTANDING');
        $this->assertSame('APPROVED', DB::table('ar_credit_notes')->where('id', $note2)->value('status'));
        $this->assertSame('200000.0000', $this->arOutstanding($second['id']));
    }

    public function test_reversing_a_note_restores_the_receivable_and_the_invoice_can_only_be_reversed_without_notes(): void
    {
        $customer = $this->customer($this->tenant);
        $invoice = $this->arInvoice($customer, '1000000');
        $note = $this->postedCreditNote($invoice['id']);

        $this->postJson(self::AR."/ar-invoices/{$invoice['id']}/reverse", ['reason' => 'salah'])->assertStatus(409)->assertJsonPath('code', 'AR_INVOICE_HAS_CREDIT_NOTES');

        $reversed = $this->postJson(self::AR."/ar-credit-notes/{$note['id']}/reverse", ['reason' => 'Retur dibatalkan'])->assertOk()->assertJsonPath('status', 'REVERSED')->json();
        $this->assertSame('1000000.0000', $this->arOutstanding($invoice['id']));
        $this->assertSame('1000000.0000', $this->glBalance($this->tenant, '1130'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '4150'));
        $this->assertNotNull($reversed['reversal_journal_id']);
        $this->assertSame(1, DB::table('journal_entries')->where('reverses_journal_id', $note['journal_entry_id'])->count());
        $this->assertSame([], $this->getJson(self::AR."/ar-invoices/{$invoice['id']}")->json('credit_notes'));
        $this->postJson(self::AR."/ar-credit-notes/{$note['id']}/reverse", ['reason' => 'lagi'])->assertStatus(409)->assertJsonPath('code', 'AR_CREDIT_NOTE_ALREADY_REVERSED');

        $this->postJson(self::AR."/ar-invoices/{$invoice['id']}/reverse", ['reason' => 'Faktur salah'])->assertOk()->assertJsonPath('status', 'REVERSED');
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1130'));
        $this->postJson(self::AR.'/ar-credit-notes', $this->creditNoteBody($invoice['id']))->assertStatus(409)->assertJsonPath('code', 'AR_INVOICE_NOT_CREDITABLE');
    }

    public function test_the_invoice_the_customer_and_the_dates_are_validated(): void
    {
        $customer = $this->customer($this->tenant);
        $invoice = $this->arInvoice($customer, '1000000');
        $draft = $this->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer))->assertCreated()->json('id');
        $beta = $this->receivablesTenant('beta');
        $foreignInvoice = $this->inTenant($beta, fn () => (string) Str::uuid());
        $uri = self::AR.'/ar-credit-notes';

        $this->postJson($uri, $this->creditNoteBody($draft))->assertStatus(409)->assertJsonPath('code', 'AR_INVOICE_NOT_CREDITABLE');
        $this->postJson($uri, $this->creditNoteBody($foreignInvoice))->assertStatus(422)->assertJsonPath('code', 'AR_INVOICE_NOT_FOUND');
        $this->postJson($uri, $this->creditNoteBody('11111111-1111-4111-8111-111111111111'))->assertStatus(422)->assertJsonPath('code', 'AR_INVOICE_NOT_FOUND');
        $this->postJson($uri, $this->creditNoteBody($invoice['id'], ['posting_date' => '2026-03-01', 'document_date' => '2026-03-01']))->assertStatus(422)->assertJsonPath('code', 'AR_CREDIT_NOTE_BEFORE_INVOICE');
        $this->postJson($uri, $this->creditNoteBody($invoice['id'], ['reason' => '  ']))->assertStatus(422);
        $this->postJson($uri, $this->creditNoteBody($invoice['id'], ['lines' => []]))->assertStatus(422);
        $this->postJson($uri, $this->creditNoteBody($invoice['id'], ['lines' => [['description' => 'x', 'amount' => '0']]]))->assertStatus(422)->assertJsonPath('code', 'LINE_AMOUNT_INVALID');
        $this->postJson($uri, $this->creditNoteBody($invoice['id'], ['lines' => [['description' => 'x', 'amount' => 100.5]]]))->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson($uri, $this->creditNoteBody($invoice['id'], ['currency' => 'USD']))->assertStatus(422)->assertJsonPath('code', 'CURRENCY_NOT_SUPPORTED');
        $this->postJson($uri, $this->creditNoteBody($invoice['id'], ['lines' => [['description' => 'x', 'amount' => '1000', 'account_id' => $this->account($this->tenant, '6900')->id]]]))->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_TYPE_INVALID');
        $this->assertSame(0, DB::table('ar_credit_notes')->count());

        // A draft keeps its invoice for life, and the customer is always the invoice's own.
        $id = $this->postJson($uri, $this->creditNoteBody($invoice['id']))->assertCreated()->json('id');
        $other = $this->arInvoice($customer, '300000');
        $this->patchJson($uri."/{$id}", ['ar_invoice_id' => $other['id']])->assertStatus(422)->assertJsonPath('code', 'AR_CREDIT_NOTE_INVOICE_LOCKED');
        $this->patchJson($uri."/{$id}", ['customer_id' => $this->customer($this->tenant, 'C2')->id, 'reason' => 'Ubah'])->assertOk()->assertJsonPath('customer_id', $customer->id);
    }

    public function test_a_posted_note_is_immutable_in_the_service_and_in_the_database(): void
    {
        $customer = $this->customer($this->tenant);
        $invoice = $this->arInvoice($customer, '1000000');
        $note = $this->postedCreditNote($invoice['id']);
        $uri = self::AR."/ar-credit-notes/{$note['id']}";

        $this->patchJson($uri, ['reason' => 'Ubah'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_IMMUTABLE');
        $this->postJson($uri.'/cancel', ['reason' => 'x'])->assertStatus(409);
        $this->postJson($uri.'/reopen')->assertStatus(409);
        $line = DB::table('ar_credit_note_lines')->where('ar_credit_note_id', $note['id'])->value('id');

        foreach ([
            fn () => DB::table('ar_credit_notes')->where('id', $note['id'])->update(['total_amount' => 1, 'subtotal_amount' => 1]),
            fn () => DB::table('ar_credit_notes')->where('id', $note['id'])->update(['ar_invoice_id' => $this->arInvoice($customer, '5000')['id']]),
            fn () => DB::table('ar_credit_notes')->where('id', $note['id'])->update(['status' => 'DRAFT']),
            fn () => DB::table('ar_credit_notes')->where('id', $note['id'])->update(['document_number' => 'CN-X']),
            fn () => DB::table('ar_credit_notes')->where('id', $note['id'])->delete(),
            fn () => DB::table('ar_credit_note_lines')->where('id', $line)->update(['amount' => 1]),
            fn () => DB::table('ar_credit_note_lines')->where('id', $line)->delete(),
        ] as $i => $attempt) {
            $this->assertRefused($attempt, "tampering attempt #{$i}");
        }
        $this->assertSame('900000.0000', $this->arOutstanding($invoice['id']));
    }

    public function test_the_database_refuses_a_note_beyond_the_invoice_or_for_another_customer(): void
    {
        $customer = $this->customer($this->tenant);
        $other = $this->customer($this->tenant, 'C2');
        $invoice = $this->arInvoice($customer, '1000000');
        $draft = $this->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer))->assertCreated()->json('id');
        $raw = fn (string $customerId, string $invoiceId, string $total) => [
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'customer_id' => $customerId, 'ar_invoice_id' => $invoiceId, 'document_date' => '2026-03-25',
            'posting_date' => '2026-03-25', 'currency' => 'IDR', 'reason' => 'raw', 'subtotal_amount' => $total, 'tax_amount' => 0, 'total_amount' => $total,
            'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now(),
        ];

        $this->assertRefused(fn () => DB::table('ar_credit_notes')->insert($raw($other->id, $invoice['id'], '100'))); // customer must be the invoice's
        $this->assertRefused(fn () => DB::table('ar_credit_notes')->insert(array_merge($raw($customer->id, $invoice['id'], '100'), ['total_amount' => 99]))); // total = subtotal + tax
        $this->assertRefused(fn () => DB::table('ar_credit_notes')->insert(array_merge($raw($customer->id, $invoice['id'], '100'), ['status' => 'POSTED']))); // must start as a draft
        $this->assertSame(0, DB::table('ar_credit_notes')->count());

        // A note on an unposted invoice cannot be made POSTED, and a posted one cannot exceed the invoice: the post guard checks both at the database.
        $approvedBeyond = $this->approvedNote($invoice['id'], ['lines' => [['description' => 'x', 'amount' => '900000']]]);
        $this->postedReceipt($customer, $this->cashAccount($this->tenant, 'BCA')->id, [$invoice['id'] => '500000']);
        $this->postJson(self::AR."/ar-credit-notes/{$approvedBeyond}/post")->assertStatus(409);
        $this->assertSame('500000.0000', $this->arOutstanding($invoice['id']));
        $this->assertSame('DRAFT', DB::table('ar_invoices')->where('id', $draft)->value('status'));
    }

    public function test_a_closed_period_blocks_posting_and_reversing_a_note(): void
    {
        $customer = $this->customer($this->tenant);
        $invoice = $this->arInvoice($customer, '1000000');
        $id = $this->approvedNote($invoice['id'], ['posting_date' => '2026-04-10', 'document_date' => '2026-04-10']);
        $this->postJson(self::AR."/periods/{$this->period($this->tenant, '2026-04')->id}/close")->assertOk();
        $before = $this->glFigures($this->tenant);

        $this->postJson(self::AR."/ar-credit-notes/{$id}/post")->assertStatus(422);
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->assertSame('APPROVED', DB::table('ar_credit_notes')->where('id', $id)->value('status'));
        $this->assertNull(DB::table('ar_credit_notes')->where('id', $id)->value('document_number'));

        $posted = $this->postedCreditNote($invoice['id']);
        $this->postJson(self::AR."/periods/{$this->period($this->tenant, '2026-03')->id}/close")->assertOk();
        $this->postJson(self::AR."/ar-credit-notes/{$posted['id']}/reverse", ['reason' => 'x'])->assertStatus(422);
        $this->assertSame('POSTED', DB::table('ar_credit_notes')->where('id', $posted['id'])->value('status'));
        $this->assertSame('900000.0000', $this->arOutstanding($invoice['id']));
    }

    public function test_aging_and_the_reconciliation_follow_receipts_and_notes_by_posting_date(): void
    {
        $customer = $this->customer($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->arInvoice($customer, '1000000', ['document_date' => '2026-03-01', 'posting_date' => '2026-03-01']);
        $this->postedReceipt($customer, $bank->id, [$invoice['id'] => '300000'], ['posting_date' => '2026-03-10', 'receipt_date' => '2026-03-10']);
        $note = $this->postedCreditNote($invoice['id'], ['posting_date' => '2026-03-15', 'document_date' => '2026-03-15', 'lines' => [['description' => 'Retur', 'amount' => '200000']]]);

        $total = fn (string $asOf) => $this->getJson(self::AR."/ar-aging?as_of={$asOf}")->assertOk()->json('totals.total');
        $this->assertSame('1000000.0000', $total('2026-03-05'));
        $this->assertSame('700000.0000', $total('2026-03-12'));
        $this->assertSame('500000.0000', $total('2026-03-20'));
        $detail = $this->getJson(self::AR.'/ar-aging?as_of=2026-03-20&detail=1')->assertOk()->json('invoices.0');
        $this->assertSame(['300000.0000', '200000.0000', '500000.0000'], [$detail['received_amount'], $detail['credited_amount'], $detail['outstanding_amount']]);

        foreach (['2026-03-05' => '1000000.0000', '2026-03-12' => '700000.0000', '2026-03-20' => '500000.0000'] as $asOf => $expected) {
            $report = $this->getJson(self::AR."/reconciliation/ar?as_of={$asOf}")->assertOk()->json();
            $this->assertSame(['MATCHED', $expected, $expected], [$report['status'], $report['gl_balance'], $report['subledger_balance']], $asOf);
        }

        // Reversed on 25 March, the note stops counting from that date but still counts before it.
        $this->postJson(self::AR."/ar-credit-notes/{$note['id']}/reverse", ['reason' => 'x', 'posting_date' => '2026-03-25'])->assertOk();
        $this->assertSame('500000.0000', $total('2026-03-20'));
        $this->assertSame('700000.0000', $total('2026-03-26'));
        $this->assertSame('MATCHED', $this->getJson(self::AR.'/reconciliation/ar?as_of=2026-03-26')->json('status'));
        $this->assertSame('MATCHED', $this->getJson(self::AR.'/reconciliation/ar?as_of=2026-03-20')->json('status'));
    }

    public function test_notes_are_listed_filtered_exported_and_audited(): void
    {
        $customer = $this->customer($this->tenant);
        $invoice = $this->arInvoice($customer, '1000000');
        $note = $this->postedCreditNote($invoice['id'], ['reason' => 'Retur barang rusak']);
        $draft = $this->postJson(self::AR.'/ar-credit-notes', $this->creditNoteBody($invoice['id'], ['reason' => 'Diskon']))->assertCreated()->json('id');

        $this->getJson(self::AR.'/ar-credit-notes')->assertOk()->assertJsonPath('total', 2);
        $this->getJson(self::AR.'/ar-credit-notes?status=POSTED')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $note['id']);
        $this->getJson(self::AR."/ar-credit-notes?ar_invoice_id={$invoice['id']}")->assertOk()->assertJsonPath('total', 2);
        $this->getJson(self::AR.'/ar-credit-notes?q=rusak')->assertOk()->assertJsonPath('total', 1);
        $this->getJson(self::AR."/ar-credit-notes?customer_id={$customer->id}&per_page=1")->assertOk()->assertJsonCount(1, 'data');

        $csv = $this->get(self::AR.'/ar-credit-notes/export?status=POSTED')->assertOk()->streamedContent();
        $this->assertStringContainsString($note['document_number'], $csv);
        $this->assertStringNotContainsString('Diskon', $csv);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'receivables.report.exported')->count());

        $this->postJson(self::AR."/ar-credit-notes/{$note['id']}/reverse", ['reason' => 'Koreksi'])->assertOk();
        $this->postJson(self::AR."/ar-credit-notes/{$draft}/cancel", ['reason' => 'Batal'])->assertOk();
        $actions = DB::table('audit_logs')->where('resource_id', $note['id'])->orderBy('occurred_at')->pluck('action')->all();
        $this->assertSame(['receivables.ar_credit_note.created', 'receivables.ar_credit_note.submitted', 'receivables.ar_credit_note.approved', 'receivables.ar_credit_note.posted', 'receivables.ar_credit_note.reversed'], $actions);
    }

    public function test_segregation_of_duties_scope_and_tenants_apply_to_notes(): void
    {
        $tenant = $this->receivablesTenant('sod');
        $customer = $this->customer($tenant);
        $invoice = $this->as($this->memberToken($tenant))->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer))->assertCreated()->json('id');
        // The invoice must be posted before a note; do it with a pair of users so the default policy (creator != approver) is respected.
        $maker = $this->memberToken($tenant);
        $checker = $this->memberToken($tenant);
        $this->as($maker)->postJson(self::AR."/ar-invoices/{$invoice}/submit")->assertOk();
        $this->as($checker)->postJson(self::AR."/ar-invoices/{$invoice}/approve")->assertOk();
        $this->as($checker)->postJson(self::AR."/ar-invoices/{$invoice}/post")->assertOk();

        $permissions = ['accounting.ar_credit_note.view', 'accounting.ar_credit_note.create', 'accounting.ar_credit_note.submit', 'accounting.ar_credit_note.approve', 'accounting.ar_credit_note.post'];
        $preparer = $this->memberToken($tenant, $permissions);
        $id = $this->as($preparer)->postJson(self::AR.'/ar-credit-notes', $this->creditNoteBody($invoice))->assertCreated()->json('id');
        $this->as($preparer)->postJson(self::AR."/ar-credit-notes/{$id}/submit")->assertOk();
        $this->as($preparer)->postJson(self::AR."/ar-credit-notes/{$id}/approve")->assertStatus(403)->assertJsonPath('code', 'SOD_VIOLATION');
        $this->as($this->memberToken($tenant, $permissions))->postJson(self::AR."/ar-credit-notes/{$id}/approve")->assertOk();
        $this->assertFalse($this->as($preparer)->getJson(self::AR."/ar-credit-notes/{$id}")->assertOk()->json('sod.approve'));

        // Another tenant sees and drives nothing.
        $b = $this->signedIn($this->receivablesTenant('beta'));
        $b->getJson(self::AR.'/ar-credit-notes')->assertOk()->assertJsonPath('total', 0);
        foreach (['', '/submit', '/approve', '/reject', '/reopen', '/cancel', '/post', '/reverse'] as $suffix) {
            $b->{$suffix === '' ? 'getJson' : 'postJson'}(self::AR."/ar-credit-notes/{$id}{$suffix}", ['reason' => 'x'])->assertNotFound();
        }
        $b->patchJson(self::AR."/ar-credit-notes/{$id}", ['reason' => 'x'])->assertNotFound();
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
}
