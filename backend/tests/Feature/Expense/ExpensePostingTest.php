<?php

namespace Tests\Feature\Expense;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Services\FiscalCalendarService;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA2 batch F: expenses — both financial paths post through the Posting Engine; immutability, period control, reversal and database guards. */
class ExpensePostingTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->payablesTenant('alpha', ['sod_creator_not_approver' => false]);
        $this->signedIn($this->tenant);
    }

    /** @return list<array{string,string,string}> code, debit, credit of the journal's lines in order */
    private function journalLines(string $journalId): array
    {
        return DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $journalId)->orderBy('l.line_number')
            ->get(['a.code', 'l.debit', 'l.credit'])->map(fn ($l) => [$l->code, $l->debit, $l->credit])->all();
    }

    private function closePeriod(string $code): void
    {
        $this->inTenant($this->tenant, function () use ($code) {
            $calendar = app(FiscalCalendarService::class);
            $period = AccountingPeriod::query()->where('code', $code)->firstOrFail();
            $calendar->transitionPeriod($period, AccountingPeriod::SOFT_CLOSED);
            $calendar->transitionPeriod($period, AccountingPeriod::CLOSED);
        });
    }

    public function test_a_payable_expense_reaches_the_ledger_only_when_posted_and_creates_one_payable(): void
    {
        $vendor = $this->vendor($this->tenant);
        $category = $this->expenseCategory($this->tenant, 'UTIL', ['account_id' => $this->account($this->tenant, '6300')->id]);
        $before = $this->glFigures($this->tenant);

        $id = $this->postJson(self::AP.'/expenses', $this->payableExpenseBody($category->id, $vendor))->assertCreated()
            ->assertJsonPath('status', 'DRAFT')->assertJsonPath('total_amount', '1110000.0000')->assertJsonPath('due_date', '2026-03-12')->assertJsonPath('document_number', null)->json('id');
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->postJson(self::AP."/expenses/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/expenses/{$id}/approve")->assertOk();
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'expense']));
        $this->assertSame(0, $this->rows('ap_invoices', ['origin' => 'EXPENSE']));

        $posted = $this->postJson(self::AP."/expenses/{$id}/post")->assertOk()->assertJsonPath('status', 'POSTED')->json();
        $this->assertSame('EXP-FY2026-000001', $posted['document_number']);
        $this->assertSame([['6300', '1000000.0000', '0.0000'], ['1150', '110000.0000', '0.0000'], ['2110', '0.0000', '1110000.0000']], $this->journalLines($posted['journal_entry_id']));
        $this->assertSame(1, $this->rows('accounting_events', ['source_id' => $id, 'status' => 'POSTED', 'event_type' => 'EXPENSE_RECOGNIZED']));
        $journal = DB::table('journal_entries')->where('id', $posted['journal_entry_id'])->first();
        $this->assertSame(['expense', $id, 'SYSTEM', 'POSTED'], [$journal->source_type, $journal->source_id, $journal->journal_type, $journal->status]);
        $this->assertSame('EXPENSE-PAYABLE', json_decode($journal->posting_snapshot, true)['rule']['code']);

        // The payable sits in the ordinary subledger: one POSTED row of origin EXPENSE sharing the expense's journal and number.
        $payable = DB::table('ap_invoices')->where('source_type', 'expense')->where('source_id', $id)->first();
        $this->assertSame(['EXPENSE', 'POSTED', $posted['document_number'], $posted['journal_entry_id'], $this->account($this->tenant, '2110')->id, '1110000.0000', $vendor->id],
            [$payable->origin, $payable->status, $payable->document_number, $payable->journal_entry_id, $payable->payable_account_id, $payable->total_amount, $payable->vendor_id]);
        $this->getJson(self::AP."/expenses/{$id}")->assertOk()->assertJsonPath('payable.id', $payable->id)->assertJsonPath('payable.payment_status', 'UNPAID')->assertJsonPath('payable.outstanding_amount', '1110000.0000');
        $this->getJson(self::AP.'/ap-invoices?origin=EXPENSE&open=1')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $payable->id);
        $this->getJson(self::AP.'/ap-aging?as_of=2026-03-31')->assertOk()->assertJsonPath('totals.total', '1110000.0000');
        $this->getJson(self::AP.'/reconciliation/ap?as_of=2026-03-31')->assertOk()->assertJsonPath('status', 'MATCHED')->assertJsonPath('subledger_balance', '1110000.0000');

        // A second post, or a replay, changes nothing; the payable cannot be driven as an invoice.
        $after = $this->glFigures($this->tenant);
        $this->postJson(self::AP."/expenses/{$id}/post")->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_ALREADY_POSTED');
        $this->assertEquals($after, $this->glFigures($this->tenant));
        $this->assertSame(1, $this->rows('ap_invoices', ['source_type' => 'expense', 'source_id' => $id]));
        $this->postJson(self::AP."/ap-invoices/{$payable->id}/post")->assertStatus(409)->assertJsonPath('code', 'AP_INVOICE_NOT_POSTABLE');
        $this->postJson(self::AP."/ap-invoices/{$payable->id}/reverse", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'AP_INVOICE_NOT_REVERSIBLE');
        $this->patchJson(self::AP."/ap-invoices/{$payable->id}", ['description' => 'x'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_IMMUTABLE');
    }

    public function test_the_payable_of_an_expense_is_settled_by_an_ordinary_vendor_payment_and_released_with_it(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->signedIn($this->tenant);
        $expense = $this->postedExpense($this->payableExpenseBody($this->expenseCategory($this->tenant)->id, $vendor));
        $payableId = DB::table('ap_invoices')->where('source_id', $expense['id'])->value('id');

        $payment = $this->postedPayment($vendor, $bank->id, [$payableId => '400000']);
        $this->getJson(self::AP."/expenses/{$expense['id']}")->assertJsonPath('payable.payment_status', 'PARTIALLY_PAID')->assertJsonPath('payable.outstanding_amount', '710000.0000');
        $this->assertSame('-710000.0000', $this->glBalance($this->tenant, '2110'));
        $this->assertSame('-400000.0000', $this->glBalance($this->tenant, '1120'));

        // The expense cannot be reversed while a payment settles its payable, nothing changes, and the payment's own reversal releases it.
        $figures = $this->glFigures($this->tenant);
        $this->postJson(self::AP."/expenses/{$expense['id']}/reverse", ['reason' => 'Salah catat'])->assertStatus(409)->assertJsonPath('code', 'EXPENSE_HAS_PAYMENTS');
        $this->assertEquals($figures, $this->glFigures($this->tenant));
        $this->assertSame('POSTED', DB::table('expenses')->where('id', $expense['id'])->value('status'));

        $this->postJson(self::AP."/vendor-payments/{$payment['id']}/reverse", ['reason' => 'Dibatalkan'])->assertOk();
        $this->postJson(self::AP."/expenses/{$expense['id']}/reverse", ['reason' => 'Salah catat'])->assertOk()->assertJsonPath('status', 'REVERSED');
        $this->assertSame('REVERSED', DB::table('ap_invoices')->where('id', $payableId)->value('status'));
        foreach (['2110', '6900', '1150', '1120'] as $code) {
            $this->assertSame('0.0000', $this->glBalance($this->tenant, $code), $code);
        }
        // A reversed payable cannot be paid any more.
        $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$payableId => '1000']))->assertStatus(422)->assertJsonPath('code', 'AP_INVOICE_NOT_PAYABLE');
    }

    public function test_a_directly_paid_expense_credits_the_chosen_account_and_creates_no_payable(): void
    {
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $cash = $this->cashAccount($this->tenant, 'KAS', 'CASH');
        $this->signedIn($this->tenant);
        $category = $this->expenseCategory($this->tenant, 'PARK', ['account_id' => $this->account($this->tenant, '6200')->id]);
        $before = $this->glFigures($this->tenant);

        $id = $this->postJson(self::AP.'/expenses', $this->paidExpenseBody($category->id, $cash->id, ['tax_amount' => '22000']))->assertCreated()->assertJsonPath('due_date', null)->json('id');
        $this->postJson(self::AP."/expenses/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/expenses/{$id}/approve")->assertOk();
        $this->assertEquals($before, $this->glFigures($this->tenant));

        $posted = $this->postJson(self::AP."/expenses/{$id}/post")->assertOk()->assertJsonPath('gl_account_id', $this->account($this->tenant, '1110')->id)->json();
        $this->assertSame([['6200', '200000.0000', '0.0000'], ['1150', '22000.0000', '0.0000'], ['1110', '0.0000', '222000.0000']], $this->journalLines($posted['journal_entry_id']));
        $this->assertSame(1, $this->rows('accounting_events', ['source_id' => $id, 'status' => 'POSTED', 'event_type' => 'EXPENSE_PAID']));
        $this->assertSame(0, $this->rows('ap_invoices', ['origin' => 'EXPENSE']));
        $this->assertSame('-222000.0000', $this->glBalance($this->tenant, '1110'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1120'));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '2110'));
        $this->getJson(self::AP."/cash-bank-accounts/{$cash->id}")->assertOk()->assertJsonPath('book_balance', '-222000.0000');

        // The paid-from account is part of the document: another account of the same kind is a different expense.
        $other = $this->postedExpense($this->paidExpenseBody($category->id, $bank->id));
        $this->assertSame('1120', DB::table('accounts')->where('id', $other['gl_account_id'])->value('code'));
        $this->assertSame('-200000.0000', $this->glBalance($this->tenant, '1120'));
    }

    public function test_classification_follows_the_document_then_the_category_account_then_its_role_then_the_rule(): void
    {
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->signedIn($this->tenant);
        $byAccount = $this->expenseCategory($this->tenant, 'ACC', ['account_id' => $this->account($this->tenant, '6300')->id]);
        $byRole = $this->expenseCategory($this->tenant, 'ROLE', ['account_role' => 'INVENTORY_ASSET']);
        $plain = $this->expenseCategory($this->tenant, 'PLAIN');

        $debit = fn (array $body) => $this->journalLines($this->postedExpense($this->paidExpenseBody($body['expense_category_id'], $bank->id, $body))['journal_entry_id'])[0][0];
        $this->assertSame('6200', $debit(['expense_category_id' => $byAccount->id, 'account_id' => $this->account($this->tenant, '6200')->id]));
        $this->assertSame('6300', $debit(['expense_category_id' => $byAccount->id]));
        $this->assertSame('1140', $debit(['expense_category_id' => $byRole->id]));
        $this->assertSame('6900', $debit(['expense_category_id' => $plain->id]));
        $this->assertSame(4, $this->rows('journal_entries', ['source_type' => 'expense']));

        // The classification account must be usable.
        $this->postJson(self::AP.'/expenses', $this->paidExpenseBody($plain->id, $bank->id, ['account_id' => $this->account($this->tenant, '4100')->id]))->assertStatus(422);
        $this->postJson(self::AP.'/expenses', $this->paidExpenseBody($plain->id, $bank->id, ['account_id' => $this->account($this->tenant, '2110')->id]))->assertStatus(422);
    }

    public function test_a_closed_period_stops_the_posting_and_leaves_no_trace_and_no_gap_in_the_numbers(): void
    {
        $vendor = $this->vendor($this->tenant);
        $category = $this->expenseCategory($this->tenant);
        $id = $this->approvedExpense($this->payableExpenseBody($category->id, $vendor));
        $this->closePeriod('2026-03');

        $before = $this->glFigures($this->tenant);
        $this->postJson(self::AP."/expenses/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
        $this->assertSame('APPROVED', DB::table('expenses')->where('id', $id)->value('status'));
        $this->assertNull(DB::table('expenses')->where('id', $id)->value('document_number'));
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'expense']));
        $this->assertSame(0, $this->rows('ap_invoices', ['origin' => 'EXPENSE']));
        $this->assertSame(0, $this->rows('document_sequences', ['sequence_code' => 'EXPENSE']));

        // Submitting a new one into the closed period is refused early; an open period posts and gets number 1.
        $late = $this->postJson(self::AP.'/expenses', $this->payableExpenseBody($category->id, $vendor))->assertCreated()->json('id');
        $this->postJson(self::AP."/expenses/{$late}/submit")->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
        $april = $this->postedExpense($this->payableExpenseBody($category->id, $vendor, ['expense_date' => '2026-04-05']));
        $this->assertSame('EXP-FY2026-000001', $april['document_number']);
    }

    public function test_a_posted_expense_and_its_payable_cannot_be_changed_by_the_service_or_the_database(): void
    {
        $vendor = $this->vendor($this->tenant);
        $expense = $this->postedExpense($this->payableExpenseBody($this->expenseCategory($this->tenant)->id, $vendor));
        $id = $expense['id'];
        $payableId = DB::table('ap_invoices')->where('source_id', $id)->value('id');
        $otherCategory = $this->expenseCategory($this->tenant, 'OTHER')->id;
        $otherVendor = $this->vendor($this->tenant, 'OTHER')->id;
        $this->signedIn($this->tenant);

        $this->patchJson(self::AP."/expenses/{$id}", ['description' => 'Ubah'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_IMMUTABLE');
        foreach (['cancel', 'reject'] as $action) {
            $this->postJson(self::AP."/expenses/{$id}/{$action}", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_IMMUTABLE');
        }
        foreach (['submit', 'approve', 'reopen'] as $action) {
            $this->postJson(self::AP."/expenses/{$id}/{$action}")->assertStatus(409);
        }

        foreach ([
            fn () => DB::table('expenses')->where('id', $id)->update(['net_amount' => 1, 'total_amount' => 1]),
            fn () => DB::table('expenses')->where('id', $id)->update(['description' => 'tamper']),
            fn () => DB::table('expenses')->where('id', $id)->update(['expense_category_id' => $otherCategory]),
            fn () => DB::table('expenses')->where('id', $id)->update(['vendor_id' => $otherVendor]),
            fn () => DB::table('expenses')->where('id', $id)->update(['status' => 'DRAFT']),
            fn () => DB::table('expenses')->where('id', $id)->update(['document_number' => 'EXP-X']),
            fn () => DB::table('expenses')->where('id', $id)->delete(),
            fn () => DB::table('ap_invoices')->where('id', $payableId)->update(['total_amount' => 1, 'subtotal_amount' => 1]),
            fn () => DB::table('ap_invoices')->where('id', $payableId)->update(['due_date' => '2027-01-01']),
            fn () => DB::table('ap_invoices')->where('id', $payableId)->delete(),
            fn () => DB::table('journal_entries')->where('id', $expense['journal_entry_id'])->update(['description' => 'tamper']),
            fn () => DB::table('journal_lines')->where('journal_entry_id', $expense['journal_entry_id'])->update(['debit' => 1]),
        ] as $i => $attempt) {
            $this->assertRefused($attempt, "tampering attempt #{$i}");
        }
    }

    public function test_reversal_uses_the_shared_mechanism_for_both_paths_and_keeps_history(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->signedIn($this->tenant);
        $category = $this->expenseCategory($this->tenant);
        $payable = $this->postedExpense($this->payableExpenseBody($category->id, $vendor));
        $paid = $this->postedExpense($this->paidExpenseBody($category->id, $bank->id));
        $posted = $this->glFigures($this->tenant);

        $this->postJson(self::AP."/expenses/{$paid['id']}/reverse", [])->assertStatus(422); // a reason is mandatory
        foreach ([$payable, $paid] as $expense) {
            $reversed = $this->postJson(self::AP."/expenses/{$expense['id']}/reverse", ['reason' => 'Salah catat', 'posting_date' => '2026-03-20'])->assertOk()
                ->assertJsonPath('status', 'REVERSED')->assertJsonPath('document_number', $expense['document_number'])->json();

            $original = DB::table('journal_entries')->where('id', $expense['journal_entry_id'])->first();
            $reversal = DB::table('journal_entries')->where('id', $reversed['reversal_journal_id'])->first();
            $this->assertSame('POSTED', $original->status); // history is preserved, never rewritten
            $this->assertSame(['REVERSAL', $original->id, '2026-03-20'], [$reversal->journal_type, $reversal->reverses_journal_id, substr($reversal->posting_date, 0, 10)]);
            $this->assertSame(1, $this->rows('journal_entries', ['reverses_journal_id' => $original->id]));
            $this->assertSame(['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'REVERSED'], DB::table('document_transitions')->where('document_id', $expense['id'])->orderBy('occurred_at')->pluck('to_status')->all());
            $this->postJson(self::AP."/expenses/{$expense['id']}/reverse", ['reason' => 'Lagi'])->assertStatus(409)->assertJsonPath('code', 'EXPENSE_ALREADY_REVERSED');
        }
        $payableRow = DB::table('ap_invoices')->where('source_id', $payable['id'])->first();
        $this->assertSame(['REVERSED', DB::table('expenses')->where('id', $payable['id'])->value('reversal_journal_id')], [$payableRow->status, $payableRow->reversal_journal_id]);
        $this->assertSame(2 * $posted->lines, (int) $this->glFigures($this->tenant)->lines);
        foreach (['2110', '6900', '1150', '1120'] as $code) {
            $this->assertSame('0.0000', $this->glBalance($this->tenant, $code), $code);
        }
        $this->getJson(self::AP.'/ap-aging?as_of=2026-03-31')->assertOk()->assertJsonPath('totals.total', '0.0000');
        $this->getJson(self::AP.'/reconciliation/ap?as_of=2026-03-31')->assertOk()->assertJsonPath('status', 'MATCHED');
    }

    public function test_a_reversal_into_a_closed_period_changes_nothing(): void
    {
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->signedIn($this->tenant);
        $expense = $this->postedExpense($this->paidExpenseBody($this->expenseCategory($this->tenant)->id, $bank->id, ['expense_date' => '2026-02-10']));
        $this->closePeriod('2026-02');
        $before = $this->glFigures($this->tenant);

        $this->postJson(self::AP."/expenses/{$expense['id']}/reverse", ['reason' => 'Terlambat'])->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
        $this->assertSame('POSTED', DB::table('expenses')->where('id', $expense['id'])->value('status'));
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->postJson(self::AP."/expenses/{$expense['id']}/reverse", ['reason' => 'Periode terbuka', 'posting_date' => '2026-03-05'])->assertOk()->assertJsonPath('status', 'REVERSED');
    }

    public function test_the_database_refuses_a_payable_without_its_posted_expense_and_a_malformed_financial_path(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->signedIn($this->tenant);
        $category = $this->expenseCategory($this->tenant);
        $paid = $this->postedExpense($this->paidExpenseBody($category->id, $bank->id));
        $journal = $paid['journal_entry_id'];
        $row = fn (array $override) => $override + [
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'vendor_id' => $vendor->id, 'vendor_invoice_number' => 'X-1', 'document_number' => 'EXP-FAKE', 'origin' => 'EXPENSE',
            'document_date' => '2026-03-12', 'posting_date' => '2026-03-12', 'due_date' => '2026-03-12', 'currency' => 'IDR', 'description' => 'x', 'subtotal_amount' => 200000, 'total_amount' => 200000,
            'payable_account_id' => $this->account($this->tenant, '2110')->id, 'status' => 'POSTED', 'source_type' => 'expense', 'source_id' => $paid['id'],
            'journal_entry_id' => $journal, 'posted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ];
        // A payable of origin EXPENSE needs a POSTED payable expense of the same journal and total; a paid expense does not qualify.
        $this->assertRefused(fn () => DB::table('ap_invoices')->insert($row([])), 'payable of a directly paid expense');
        $this->assertRefused(fn () => DB::table('ap_invoices')->insert($row(['source_id' => (string) Str::uuid()])), 'payable of a missing expense');
        $this->assertRefused(fn () => DB::table('ap_invoices')->insert($row(['origin' => 'INVOICE', 'source_type' => null, 'source_id' => null])), 'a vendor invoice that starts posted');

        $expense = fn (array $override) => $override + [
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'settlement' => 'PAYABLE', 'vendor_id' => $vendor->id, 'expense_category_id' => $category->id,
            'expense_date' => '2026-03-12', 'posting_date' => '2026-03-12', 'due_date' => '2026-03-12', 'currency' => 'IDR', 'description' => 'x',
            'net_amount' => 100, 'tax_amount' => 0, 'total_amount' => 100, 'status' => 'DRAFT', 'created_at' => now(), 'updated_at' => now(),
        ];
        $this->assertRefused(fn () => DB::table('expenses')->insert($expense(['vendor_id' => null])), 'payable without vendor');
        $this->assertRefused(fn () => DB::table('expenses')->insert($expense(['due_date' => null])), 'payable without due date');
        $this->assertRefused(fn () => DB::table('expenses')->insert($expense(['cash_bank_account_id' => $bank->id])), 'payable with a cash account');
        $this->assertRefused(fn () => DB::table('expenses')->insert($expense(['settlement' => 'DIRECT_PAID', 'due_date' => null])), 'paid without a cash account');
        $this->assertRefused(fn () => DB::table('expenses')->insert($expense(['settlement' => 'DIRECT_PAID', 'cash_bank_account_id' => $bank->id])), 'paid with a due date');
        $this->assertRefused(fn () => DB::table('expenses')->insert($expense(['total_amount' => 150])), 'total that is not net plus tax');
        $this->assertRefused(fn () => DB::table('expenses')->insert($expense(['net_amount' => 0, 'total_amount' => 0])), 'zero amount');
        $this->assertRefused(fn () => DB::table('expenses')->insert($expense(['status' => 'POSTED'])), 'posted without journal');
        $this->assertRefused(fn () => DB::table('expenses')->insert($expense(['settlement' => 'CREDIT'])), 'unknown path');
    }

    public function test_a_posting_that_cannot_complete_names_the_cause_and_changes_nothing(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->signedIn($this->tenant);
        $category = $this->expenseCategory($this->tenant);
        $payable = $this->approvedExpense($this->payableExpenseBody($category->id, $vendor));
        $paid = $this->approvedExpense($this->paidExpenseBody($category->id, $bank->id));
        $before = $this->glFigures($this->tenant);

        DB::table('posting_rules')->where('tenant_id', $this->tenant->id)->where('event_type', 'EXPENSE_RECOGNIZED')->update(['status' => 'ARCHIVED', 'effective_to' => '2026-01-31']);
        $this->postJson(self::AP."/expenses/{$payable}/post")->assertStatus(422)->assertJsonPath('code', 'POSTING_RULE_NOT_FOUND');
        DB::table('posting_rules')->where('tenant_id', $this->tenant->id)->where('event_type', 'EXPENSE_RECOGNIZED')->update(['status' => 'PUBLISHED', 'effective_to' => null]);

        // An inactive vendor, cash account or classification account after approval stops the posting.
        $this->postJson(self::AP."/vendors/{$vendor->id}/status", ['status' => 'INACTIVE'])->assertOk();
        $this->postJson(self::AP."/expenses/{$payable}/post")->assertStatus(422)->assertJsonPath('code', 'VENDOR_INACTIVE');
        $this->postJson(self::AP."/cash-bank-accounts/{$bank->id}/status", ['status' => 'INACTIVE'])->assertOk();
        $this->postJson(self::AP."/expenses/{$paid}/post")->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_INACTIVE');

        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'expense']));
        $this->assertSame(0, $this->rows('ap_invoices', ['origin' => 'EXPENSE']));
        $this->assertSame(['APPROVED', 'APPROVED'], DB::table('expenses')->whereIn('id', [$payable, $paid])->orderBy('id')->pluck('status')->all());

        $this->postJson(self::AP."/vendors/{$vendor->id}/status", ['status' => 'ACTIVE'])->assertOk();
        $this->postJson(self::AP."/expenses/{$payable}/post")->assertOk()->assertJsonPath('document_number', 'EXP-FY2026-000001');
    }

    public function test_drafts_are_validated_and_the_financial_path_can_change_while_it_is_a_draft(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bank = $this->cashAccount($this->tenant, 'BCA');
        $this->signedIn($this->tenant);
        $c = $this->expenseCategory($this->tenant)->id;
        $uri = self::AP.'/expenses';

        $this->postJson($uri, $this->paidExpenseBody($c, $bank->id, ['settlement' => 'LATER']))->assertStatus(422)->assertJsonValidationErrors('settlement');
        $this->postJson($uri, $this->payableExpenseBody($c, $vendor, ['vendor_id' => null]))->assertStatus(422)->assertJsonPath('code', 'VENDOR_REQUIRED');
        $this->postJson($uri, $this->paidExpenseBody($c, $bank->id, ['cash_bank_account_id' => null]))->assertStatus(422)->assertJsonPath('code', 'CASH_BANK_ACCOUNT_REQUIRED');
        $this->postJson($uri, $this->paidExpenseBody($c, $bank->id, ['net_amount' => '0']))->assertStatus(422)->assertJsonPath('code', 'EXPENSE_AMOUNT_INVALID');
        $this->postJson($uri, $this->paidExpenseBody($c, $bank->id, ['net_amount' => 10.5]))->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson($uri, $this->paidExpenseBody($c, $bank->id, ['net_amount' => '-5']))->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson($uri, $this->paidExpenseBody($c, $bank->id, ['net_amount' => '10.555']))->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson($uri, $this->paidExpenseBody($c, $bank->id, ['tax_amount' => 'abc']))->assertStatus(422)->assertJsonPath('code', 'AMOUNT_INVALID');
        $this->postJson($uri, $this->paidExpenseBody($c, $bank->id, ['currency' => 'USD']))->assertStatus(422)->assertJsonPath('code', 'CURRENCY_NOT_SUPPORTED');
        $this->postJson($uri, $this->payableExpenseBody($c, $vendor, ['due_date' => '2026-03-01']))->assertStatus(422)->assertJsonPath('code', 'DUE_DATE_INVALID');
        $this->postJson($uri, $this->paidExpenseBody($c, $bank->id, ['payment_method' => 'BARTER']))->assertStatus(422);
        $this->postJson($uri, $this->paidExpenseBody($c, $bank->id, ['expense_date' => '12-03-2026']))->assertStatus(422);
        $this->postJson($uri, $this->paidExpenseBody($c, $bank->id, ['description' => '']))->assertStatus(422);
        $this->postJson($uri, $this->paidExpenseBody((string) Str::uuid(), $bank->id))->assertStatus(422)->assertJsonPath('code', 'EXPENSE_CATEGORY_INVALID');
        $this->assertSame(0, $this->rows('expenses'));

        // Server-owned fields in the request body are ignored, never trusted.
        $id = $this->postJson($uri, $this->paidExpenseBody($c, $bank->id, [
            'status' => 'POSTED', 'document_number' => 'EXP-HACK', 'journal_entry_id' => (string) Str::uuid(), 'total_amount' => '1', 'tenant_id' => (string) Str::uuid(), 'created_by' => (string) Str::uuid(),
        ]))->assertCreated()->assertJsonPath('status', 'DRAFT')->assertJsonPath('document_number', null)->assertJsonPath('total_amount', '200000.0000')->json('id');
        $row = DB::table('expenses')->where('id', $id)->first();
        $this->assertSame([$this->tenant->id, null, null], [$row->tenant_id, $row->journal_entry_id, $row->document_number]);

        // While it is a draft the path can change; what belongs to the old path is cleared.
        $this->patchJson("{$uri}/{$id}", ['settlement' => 'PAYABLE'])->assertStatus(422)->assertJsonPath('code', 'VENDOR_REQUIRED');
        $changed = $this->patchJson("{$uri}/{$id}", ['settlement' => 'PAYABLE', 'vendor_id' => $vendor->id, 'due_date' => '2026-04-30'])->assertOk()
            ->assertJsonPath('settlement', 'PAYABLE')->assertJsonPath('cash_bank_account_id', null)->assertJsonPath('due_date', '2026-04-30')->json();
        $this->assertTrue($changed['due_date_overridden']);
        $this->patchJson("{$uri}/{$id}", ['settlement' => 'DIRECT_PAID', 'cash_bank_account_id' => $bank->id])->assertOk()
            ->assertJsonPath('due_date', null)->assertJsonPath('payment_term_id', null)->assertJsonPath('net_amount', '200000.0000');
        $this->patchJson("{$uri}/{$id}", ['net_amount' => '250000', 'tax_amount' => '27500'])->assertOk()->assertJsonPath('total_amount', '277500.0000');

        // A payable expense takes its due date from the vendor's payment term.
        $term = DB::table('payment_terms')->where('tenant_id', $this->tenant->id)->where('code', 'NET30')->value('id');
        $this->postJson($uri, $this->payableExpenseBody($c, $vendor, ['payment_term_id' => $term]))->assertCreated()->assertJsonPath('due_date', '2026-04-11')->assertJsonPath('due_date_overridden', false);
    }

    public function test_the_lifecycle_of_an_expense_is_audited_with_the_actor(): void
    {
        $vendor = $this->vendor($this->tenant);
        $expense = $this->postedExpense($this->payableExpenseBody($this->expenseCategory($this->tenant)->id, $vendor));
        $this->postJson(self::AP."/expenses/{$expense['id']}/reverse", ['reason' => 'Koreksi'])->assertOk();

        $this->assertSame(
            ['expense.expense.created', 'expense.expense.submitted', 'expense.expense.approved', 'expense.expense.posted', 'expense.expense.reversed'],
            DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('resource_id', $expense['id'])->orderBy('occurred_at')->pluck('action')->all(),
        );
        $this->assertSame(5, DB::table('audit_logs')->where('resource_id', $expense['id'])->whereNotNull('actor_user_id')->count());
        $payableId = DB::table('ap_invoices')->where('source_id', $expense['id'])->value('id');
        $this->assertSame(['payables.ap_invoice.created_from_expense', 'payables.ap_invoice.reversed'],
            DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('resource_id', $payableId)->orderBy('occurred_at')->pluck('action')->all());
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
