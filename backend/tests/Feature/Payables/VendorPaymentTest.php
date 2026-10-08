<?php

namespace Tests\Feature\Payables;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA2 batch D: vendor payments, allocation to invoices, posting through the Posting Engine, reversal and the derived outstanding amount. */
class VendorPaymentTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->payablesTenant('alpha', ['sod_creator_not_approver' => false]);
        $this->signedIn($this->tenant);
    }

    private function invoice(object $vendor, string $amount = '1000000', array $override = []): array
    {
        return $this->postedInvoice($vendor, ['lines' => [['description' => 'Jasa', 'amount' => $amount]]] + $override);
    }

    private function outstanding(string $invoiceId): string
    {
        return (string) $this->getJson(self::AP."/ap-invoices/{$invoiceId}")->assertOk()->json('outstanding_amount');
    }

    public function test_only_posting_moves_the_ledger_and_it_moves_it_exactly_once(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->invoice($vendor, '1000000');
        $before = $this->glFigures($this->tenant);

        $id = $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '400000']))->assertCreated()->assertJsonPath('status', 'DRAFT')->json('id');
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->postJson(self::AP."/vendor-payments/{$id}/submit")->assertOk();
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->postJson(self::AP."/vendor-payments/{$id}/approve")->assertOk();
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->assertSame('1000000.0000', $this->outstanding($invoice['id'])); // nothing is paid before the payment posts

        $payment = $this->postJson(self::AP."/vendor-payments/{$id}/post")->assertOk()->assertJsonPath('status', 'POSTED')->json();
        $this->assertMatchesRegularExpression('/^PAY-FY2026-\d{6}$/', $payment['document_number']);
        $this->assertSame('400000.0000', $payment['allocated_amount']);

        $after = $this->glFigures($this->tenant);
        $this->assertSame($before->lines + 2, (int) $after->lines);
        $this->assertSame('-400000.0000', $this->glBalance($this->tenant, '1120'));
        $this->assertSame('-600000.0000', $this->glBalance($this->tenant, '2110')); // 1.000.000 credited, 400.000 relieved
        $this->postJson(self::AP."/vendor-payments/{$id}/post")->assertStatus(409); // a second post finds a posted payment
        $this->assertEquals($after, $this->glFigures($this->tenant));

        $journal = DB::table('journal_entries')->where('id', $payment['journal_entry_id'])->first();
        $this->assertSame('SYSTEM', $journal->journal_type);
        $this->assertSame('vendor_payment', $journal->source_type);
        $this->assertSame($id, $journal->source_id);
    }

    public function test_partial_and_multiple_payments_reduce_the_outstanding_amount(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->invoice($vendor, '1000000');
        $this->assertSame('UNPAID', $invoice['payment_status']);

        $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '250000']);
        $shown = $this->getJson(self::AP."/ap-invoices/{$invoice['id']}")->assertOk()->json();
        $this->assertSame(['250000.0000', '750000.0000', 'PARTIALLY_PAID'], [$shown['paid_amount'], $shown['outstanding_amount'], $shown['payment_status']]);

        $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '350000']);
        $this->assertSame('400000.0000', $this->outstanding($invoice['id']));
        $this->assertCount(2, $this->getJson(self::AP."/ap-invoices/{$invoice['id']}")->json('allocations'));

        $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '400000']);
        $final = $this->getJson(self::AP."/ap-invoices/{$invoice['id']}")->assertOk()->json();
        $this->assertSame(['1000000.0000', '0.0000', 'PAID'], [$final['paid_amount'], $final['outstanding_amount'], $final['payment_status']]);
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '2110'));

        // The list filters by settlement state.
        $other = $this->invoice($vendor, '500000');
        $this->getJson(self::AP.'/ap-invoices?payment_status=PAID')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $invoice['id']);
        $this->getJson(self::AP.'/ap-invoices?payment_status=UNPAID')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $other['id']);
        $this->getJson(self::AP.'/ap-invoices?open=1')->assertOk()->assertJsonPath('total', 1);
        $this->getJson(self::AP.'/ap-invoices?payment_status=PARTIALLY_PAID')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_one_payment_settles_several_invoices_and_relieves_the_account_each_was_booked_to(): void
    {
        DB::table('accounts')->where('tenant_id', $this->tenant->id)->where('code', '2140')->update(['is_control' => true]);
        $special = $this->account($this->tenant, '2140');
        $vendor = $this->vendor($this->tenant, 'V1');
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->patchJson(self::AP."/vendors/{$vendor->id}", ['payable_account_id' => $special->id])->assertOk();

        $one = $this->invoice($vendor, '300000'); // booked to 2140 through the vendor override
        $this->assertSame($special->id, $one['payable_account_id']);
        $this->patchJson(self::AP."/vendors/{$vendor->id}", ['payable_account_id' => null])->assertOk();
        $two = $this->invoice($vendor, '200000'); // booked to the mapped 2110
        $this->assertNotSame($special->id, $two['payable_account_id']);
        $this->assertSame('-300000.0000', $this->glBalance($this->tenant, '2140'));
        $this->assertSame('-200000.0000', $this->glBalance($this->tenant, '2110'));

        $payment = $this->postedPayment($vendor, $bank->id, [$one['id'] => '300000', $two['id'] => '150000']);
        $this->assertSame('450000.0000', $payment['allocated_amount']);
        $this->assertSame('0.0000', $this->outstanding($one['id']));
        $this->assertSame('50000.0000', $this->outstanding($two['id']));

        $lines = DB::table('journal_lines')->where('journal_entry_id', $payment['journal_entry_id'])->orderBy('debit', 'desc')->get();
        $this->assertCount(3, $lines);
        $this->assertSame('-450000.0000', $this->glBalance($this->tenant, '1120'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '2140')); // each payable account is relieved by exactly what was settled on it
        $this->assertSame('-50000.0000', $this->glBalance($this->tenant, '2110'));
    }

    public function test_allocations_are_validated_against_the_payment_the_invoice_and_the_vendor(): void
    {
        $vendor = $this->vendor($this->tenant);
        $other = $this->vendor($this->tenant, 'V2');
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->invoice($vendor, '1000000');
        $foreign = $this->invoice($other, '100000');
        $draft = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor))->assertCreated()->json('id');
        $uri = self::AP.'/vendor-payments';

        // Allocation more than the payment, more than the invoice, zero, duplicate, foreign vendor, unposted invoice, unknown invoice.
        $this->postJson($uri, $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '600000'], ['amount' => '500000']))->assertStatus(422)->assertJsonPath('code', 'AP_ALLOCATION_EXCEEDS_PAYMENT');
        $this->postJson($uri, $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '1000000.01']))->assertStatus(422)->assertJsonPath('code', 'AP_ALLOCATION_EXCEEDS_OUTSTANDING');
        $this->postJson($uri, $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '0'], ['amount' => '100']))->assertStatus(422)->assertJsonPath('code', 'AP_ALLOCATION_INVALID');
        $dup = $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '10']);
        $dup['allocations'][] = ['ap_invoice_id' => $invoice['id'], 'amount' => '10'];
        $dup['amount'] = '20';
        $this->postJson($uri, $dup)->assertStatus(422)->assertJsonPath('code', 'AP_ALLOCATION_DUPLICATE');
        $this->postJson($uri, $this->paymentBody($vendor, $bank->id, [$foreign['id'] => '100000']))->assertStatus(422)->assertJsonPath('code', 'AP_ALLOCATION_VENDOR_MISMATCH');
        $this->postJson($uri, $this->paymentBody($vendor, $bank->id, [$draft => '100000']))->assertStatus(422)->assertJsonPath('code', 'AP_INVOICE_NOT_PAYABLE');
        $this->postJson($uri, $this->paymentBody($vendor, $bank->id, ['11111111-1111-4111-8111-111111111111' => '100000']))->assertStatus(422)->assertJsonPath('code', 'AP_INVOICE_NOT_FOUND');
        $this->postJson($uri, $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '100000'], ['posting_date' => '2026-03-01', 'payment_date' => '2026-03-01']))->assertStatus(422)->assertJsonPath('code', 'AP_PAYMENT_BEFORE_INVOICE');
        $this->postJson($uri, $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '100000'], ['amount' => '-5']))->assertStatus(422);
        $this->postJson($uri, $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '100000'], ['payment_method' => 'BITCOIN']))->assertStatus(422);
        $this->postJson($uri, $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '100000'], ['currency' => 'USD']))->assertStatus(422)->assertJsonPath('code', 'CURRENCY_NOT_SUPPORTED');
        $this->assertSame(0, $this->rows('vendor_payments'));

        // A payment must be allocated in full before it can leave the draft state.
        $id = $this->postJson($uri, $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '100000'], ['amount' => '150000']))->assertCreated()->assertJsonPath('unallocated_amount', '50000.0000')->json('id');
        $this->postJson($uri."/{$id}/submit")->assertStatus(422)->assertJsonPath('code', 'PAYMENT_NOT_FULLY_ALLOCATED');
        $this->patchJson($uri."/{$id}", ['amount' => '100000'])->assertOk()->assertJsonPath('unallocated_amount', '0.0000');
        $this->postJson($uri."/{$id}/submit")->assertOk();
    }

    public function test_allocation_can_be_proposed_by_the_backend_oldest_due_first(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $late = $this->invoice($vendor, '300000', ['document_date' => '2026-03-01', 'posting_date' => '2026-03-01', 'due_date' => '2026-04-30', 'payment_term_id' => $this->termId('CUSTOM')]);
        $early = $this->invoice($vendor, '200000', ['document_date' => '2026-03-02', 'posting_date' => '2026-03-02', 'due_date' => '2026-03-10', 'payment_term_id' => $this->termId('CUSTOM')]);

        $open = $this->getJson(self::AP."/vendors/{$vendor->id}/open-invoices")->assertOk()->json('data');
        $this->assertSame([$early['id'], $late['id']], array_column($open, 'id'));

        $suggested = $this->getJson(self::AP."/vendors/{$vendor->id}/allocation-suggestion?amount=350000")->assertOk()->json('data');
        $this->assertSame([[$early['id'], '200000.0000'], [$late['id'], '150000.0000']], array_map(fn ($r) => [$r['ap_invoice_id'], $r['amount']], $suggested));

        $created = $this->postJson(self::AP.'/vendor-payments', [
            'vendor_id' => $vendor->id, 'cash_bank_account_id' => $bank->id, 'payment_date' => '2026-03-20', 'amount' => '350000', 'auto_allocate' => true,
        ])->assertCreated()->json();
        $this->assertSame('0.0000', $created['unallocated_amount']);
        $this->assertCount(2, $created['allocations']);
    }

    private function termId(string $code): string
    {
        return (string) DB::table('payment_terms')->where('tenant_id', $this->tenant->id)->where('code', $code)->value('id');
    }

    public function test_reversal_restores_the_outstanding_amount_and_cannot_be_repeated(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->invoice($vendor, '1000000');
        $payment = $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '1000000']);
        $this->assertSame('0.0000', $this->outstanding($invoice['id']));
        $this->assertSame('PAID', $this->getJson(self::AP."/ap-invoices/{$invoice['id']}")->json('payment_status'));

        // The invoice cannot be reversed while a payment settles it.
        $this->postJson(self::AP."/ap-invoices/{$invoice['id']}/reverse", ['reason' => 'salah'])->assertStatus(409)->assertJsonPath('code', 'AP_INVOICE_HAS_PAYMENTS');

        $reversed = $this->postJson(self::AP."/vendor-payments/{$payment['id']}/reverse", ['reason' => 'Transfer gagal'])->assertOk()->assertJsonPath('status', 'REVERSED')->json();
        $this->assertSame('1000000.0000', $this->outstanding($invoice['id']));
        $this->assertSame('UNPAID', $this->getJson(self::AP."/ap-invoices/{$invoice['id']}")->json('payment_status'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1120'));
        $this->assertSame('-1000000.0000', $this->glBalance($this->tenant, '2110'));
        $this->assertNotNull($reversed['reversal_journal_id']);
        $this->assertSame('Transfer gagal', $reversed['reversal_reason']);
        $this->assertSame(1, DB::table('journal_entries')->where('reverses_journal_id', $payment['journal_entry_id'])->count());
        $this->assertNotNull(DB::table('ap_payment_allocations')->where('vendor_payment_id', $payment['id'])->value('released_at'));
        $this->assertSame(0, DB::table('ap_payment_allocations')->where('vendor_payment_id', $payment['id'])->where('is_effective', true)->count());

        $this->postJson(self::AP."/vendor-payments/{$payment['id']}/reverse", ['reason' => 'lagi'])->assertStatus(409)->assertJsonPath('code', 'AP_PAYMENT_ALREADY_REVERSED');
        $this->assertSame(1, DB::table('journal_entries')->where('reverses_journal_id', $payment['journal_entry_id'])->count());

        // The invoice can be paid again, and with the payment reversed it can finally be reversed itself.
        $again = $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '600000']);
        $this->assertSame('400000.0000', $this->outstanding($invoice['id']));
        $this->postJson(self::AP."/vendor-payments/{$again['id']}/reverse", ['reason' => 'x'])->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$invoice['id']}/reverse", ['reason' => 'Faktur salah'])->assertOk()->assertJsonPath('status', 'REVERSED');
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '2110'));
        $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '1000']))->assertStatus(422)->assertJsonPath('code', 'AP_INVOICE_NOT_PAYABLE');
    }

    public function test_a_closed_period_blocks_posting_and_reversal_atomically(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->invoice($vendor, '1000000');
        $id = $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '100000'], ['posting_date' => '2026-04-10', 'payment_date' => '2026-04-10']))->assertCreated()->json('id');
        $this->postJson(self::AP."/vendor-payments/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/vendor-payments/{$id}/approve")->assertOk();

        $april = $this->period($this->tenant, '2026-04');
        $this->postJson(self::AP."/periods/{$april->id}/close")->assertOk();
        $before = $this->glFigures($this->tenant);
        $this->postJson(self::AP."/vendor-payments/{$id}/post")->assertStatus(422);
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->assertSame('APPROVED', $this->getJson(self::AP."/vendor-payments/{$id}")->json('status'));
        $this->assertSame(0, DB::table('ap_payment_allocations')->where('is_effective', true)->count());
        $this->assertNull(DB::table('vendor_payments')->where('id', $id)->value('document_number'));

        // A posted payment whose reversal date falls in a closed period stays posted with its allocations.
        $posted = $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '200000']);
        $this->postJson(self::AP."/periods/{$this->period($this->tenant, '2026-03')->id}/close")->assertOk();
        $this->postJson(self::AP."/vendor-payments/{$posted['id']}/reverse", ['reason' => 'x'])->assertStatus(422);
        $this->assertSame('POSTED', DB::table('vendor_payments')->where('id', $posted['id'])->value('status'));
        $this->assertSame('800000.0000', $this->outstanding($invoice['id']));
    }

    public function test_a_posted_payment_and_its_allocations_are_immutable_in_the_service_and_in_the_database(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->invoice($vendor, '1000000');
        $cashbox = $this->cashAccount($this->tenant, 'KAS', 'CASH');
        $payment = $this->postedPayment($vendor, $bank->id, [$invoice['id'] => '400000']);
        $uri = self::AP."/vendor-payments/{$payment['id']}";

        $this->patchJson($uri, ['amount' => '1'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_IMMUTABLE');
        $this->postJson($uri.'/cancel', ['reason' => 'x'])->assertStatus(409);
        $this->postJson($uri.'/submit')->assertStatus(409);
        $this->deleteJson($uri)->assertStatus(405);

        $tamper = [
            fn () => DB::table('vendor_payments')->where('id', $payment['id'])->update(['amount' => 1]),
            fn () => DB::table('vendor_payments')->where('id', $payment['id'])->update(['cash_bank_account_id' => $cashbox->id]),
            fn () => DB::table('vendor_payments')->where('id', $payment['id'])->update(['status' => 'DRAFT']),
            fn () => DB::table('vendor_payments')->where('id', $payment['id'])->update(['document_number' => 'X-1']),
            fn () => DB::table('vendor_payments')->where('id', $payment['id'])->delete(),
            fn () => DB::table('ap_payment_allocations')->where('vendor_payment_id', $payment['id'])->update(['amount' => 1]),
            fn () => DB::table('ap_payment_allocations')->where('vendor_payment_id', $payment['id'])->update(['is_effective' => false, 'released_at' => now()]),
            fn () => DB::table('ap_payment_allocations')->where('vendor_payment_id', $payment['id'])->delete(),
            fn () => DB::table('ap_payment_allocations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'vendor_payment_id' => $payment['id'], 'ap_invoice_id' => $invoice['id'], 'amount' => 1, 'created_at' => now(), 'updated_at' => now()]),
        ];
        foreach ($tamper as $i => $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail("tamper attempt {$i} was accepted");
            } catch (QueryException $e) {
                $this->assertSame('23514', $e->errorInfo[0], "tamper attempt {$i}: ".$e->getMessage());
            }
        }
        $this->assertSame('600000.0000', $this->outstanding($invoice['id']));
    }

    public function test_the_database_alone_refuses_over_allocation_and_a_partly_allocated_posting(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $invoice = $this->invoice($vendor, '1000000');

        // Two drafts both claim 600.000 of the same invoice: each passes the draft check, only one can post.
        $first = $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '600000']))->assertCreated()->json('id');
        $second = $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '600000']))->assertCreated()->json('id');
        foreach ([$first, $second] as $id) {
            $this->postJson(self::AP."/vendor-payments/{$id}/submit")->assertOk();
            $this->postJson(self::AP."/vendor-payments/{$id}/approve")->assertOk();
        }
        $this->postJson(self::AP."/vendor-payments/{$first}/post")->assertOk();
        $this->postJson(self::AP."/vendor-payments/{$second}/post")->assertStatus(409)->assertJsonPath('code', 'AP_ALLOCATION_EXCEEDS_OUTSTANDING');
        $this->assertSame('APPROVED', DB::table('vendor_payments')->where('id', $second)->value('status'));
        $this->assertSame('400000.0000', $this->outstanding($invoice['id']));

        // The database enforces the same without the service: a not-yet-posted payment cannot make an allocation effective,
        // and a payment made POSTED by other means (guards switched off here only to build the case) still cannot exceed the invoice.
        $attempt = fn (callable $work) => DB::transaction($work);
        try {
            $attempt(fn () => DB::table('ap_payment_allocations')->where('vendor_payment_id', $second)->update(['is_effective' => true, 'effective_at' => now()]));
            $this->fail('an allocation became effective on an unposted payment');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
        }

        $journal = $this->postedJournal($this->tenant)->id;
        $glId = $this->account($this->tenant, '1120')->id;
        try {
            $attempt(function () use ($second, $journal, $glId) {
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE'); // run the deferred checks queued so far; ALTER TABLE refuses to run with events pending
                DB::statement('ALTER TABLE vendor_payments DISABLE TRIGGER USER');
                DB::table('vendor_payments')->where('id', $second)->update(['status' => 'POSTED', 'document_number' => 'PAY-RAW-1', 'posted_at' => now(), 'journal_entry_id' => $journal, 'gl_account_id' => $glId]);
                DB::statement('ALTER TABLE vendor_payments ENABLE TRIGGER USER');
                DB::table('ap_payment_allocations')->where('vendor_payment_id', $second)->update(['is_effective' => true, 'effective_at' => now()]);
            });
            $this->fail('the database accepted an allocation beyond the outstanding amount');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
            $this->assertStringContainsString('exceeds what is outstanding', $e->getMessage());
        }

        // A posted payment must be allocated in full by commit time (checked immediately here).
        try {
            $attempt(function () use ($second, $journal, $glId) {
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE'); // run the deferred checks queued so far; ALTER TABLE refuses to run with events pending
                DB::statement('ALTER TABLE vendor_payments DISABLE TRIGGER USER');
                DB::table('vendor_payments')->where('id', $second)->update(['status' => 'POSTED', 'document_number' => 'PAY-RAW-1', 'posted_at' => now(), 'journal_entry_id' => $journal, 'gl_account_id' => $glId]);
                DB::statement('ALTER TABLE vendor_payments ENABLE TRIGGER USER');
                DB::statement('SET CONSTRAINTS vendor_payments_allocation_check IMMEDIATE');
                DB::table('vendor_payments')->where('id', $second)->update(['updated_at' => now()]);
            });
            $this->fail('the database accepted a posted payment without effective allocations');
        } catch (QueryException $e) {
            $this->assertSame('23514', $e->errorInfo[0]);
            $this->assertStringContainsString('allocated in full', $e->getMessage());
        }
        $this->assertSame('400000.0000', $this->outstanding($invoice['id']));
    }

    public function test_a_cash_account_that_a_payment_uses_keeps_its_gl_mapping(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $unused = $this->cashAccount($this->tenant, 'BNI', 'BANK', '1110');
        $invoice = $this->invoice($vendor);
        $draft = $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '1000']))->assertCreated()->json('id');

        $this->patchJson(self::AP."/cash-bank-accounts/{$bank->id}", ['account_id' => $this->account($this->tenant, '1110')->id])->assertStatus(409)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_IN_USE');
        $this->deleteJson(self::AP."/cash-bank-accounts/{$bank->id}")->assertStatus(409)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_IN_USE');
        $this->getJson(self::AP."/cash-bank-accounts/{$bank->id}")->assertOk()->assertJsonPath('in_use', true);
        $this->getJson(self::AP."/cash-bank-accounts/{$unused->id}")->assertOk()->assertJsonPath('in_use', false);
        $this->patchJson(self::AP."/cash-bank-accounts/{$unused->id}", ['account_id' => $this->account($this->tenant, '1160')->id])->assertOk();

        // Deactivated, an account refuses new payments and the posting of drafts that already use it.
        $this->postJson(self::AP."/vendor-payments/{$draft}/submit")->assertOk();
        $this->postJson(self::AP."/vendor-payments/{$draft}/approve")->assertOk();
        $this->postJson(self::AP."/cash-bank-accounts/{$bank->id}/status", ['status' => 'INACTIVE'])->assertOk();
        $this->postJson(self::AP."/vendor-payments/{$draft}/post")->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_INACTIVE');
        $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '1000']))->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_INACTIVE');
    }
}
