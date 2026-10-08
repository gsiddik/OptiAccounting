<?php

namespace Tests\Feature\Payables;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Services\ChartOfAccountsService;
use App\Domain\Accounting\Services\FiscalCalendarService;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Identity\Models\Tenant;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA2 batch C: AP invoice posting through the Posting Engine, immutability, period control and reversal. */
class ApInvoicePostingTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->payablesTenant('alpha', ['sod_creator_not_approver' => false]);
        $this->signedIn($this->tenant);
    }

    /** Create, submit and approve an invoice through the API; returns its id. */
    private function approved(array $override = [], ?object $vendor = null): string
    {
        $vendor ??= $this->vendor($this->tenant, 'V'.substr(uniqid(), -4));
        $id = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, $override))->assertCreated()->json('id');
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();

        return $id;
    }

    private function glFigures(): object
    {
        return DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.tenant_id', $this->tenant->id)->where('j.status', 'POSTED')
            ->selectRaw('count(*) as lines, coalesce(sum(l.debit),0) as debit, coalesce(sum(l.credit),0) as credit')->first();
    }

    private function dec(BigDecimal $value): string
    {
        return (string) $value->toScale(4);
    }

    private function balance(string $code): string
    {
        $id = $this->account($this->tenant, $code)->id;

        return (string) DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.status', 'POSTED')->where('l.account_id', $id)
            ->selectRaw('coalesce(sum(l.debit),0) - coalesce(sum(l.credit),0) as b')->value('b');
    }

    public function test_draft_submitted_and_approved_invoices_never_reach_the_ledger_and_posting_moves_it_exactly_once(): void
    {
        $before = $this->glFigures();
        $vendor = $this->vendor($this->tenant);
        $id = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['tax_amount' => '110000']))->assertCreated()->json('id');
        $this->assertEquals($before, $this->glFigures());
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
        $this->assertEquals($before, $this->glFigures());
        $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();
        $this->assertEquals($before, $this->glFigures());
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'ap_invoice']));

        $posted = $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertOk()->assertJsonPath('status', 'POSTED')->json();
        $this->assertSame('AP-FY2026-000001', $posted['document_number']);
        $this->assertSame($this->account($this->tenant, '2110')->id, $posted['payable_account_id']);

        $journal = DB::table('journal_entries')->where('id', $posted['journal_entry_id'])->first();
        $this->assertSame('SYSTEM', $journal->journal_type);
        $this->assertSame('POSTED', $journal->status);
        $this->assertSame('ap_invoice', $journal->source_type);
        $this->assertSame($id, $journal->source_id);
        $this->assertSame('1110000.0000', $journal->total_debit);
        $this->assertSame(1, $this->rows('accounting_events', ['source_id' => $id, 'status' => 'POSTED', 'event_type' => 'AP_INVOICE_RECOGNIZED']));

        // The classic shape: Dr expense (net) + Dr input VAT, Cr accounts payable (total), all accounts resolved by role mapping.
        $lines = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $journal->id)->orderBy('l.line_number')->get(['a.code', 'l.debit', 'l.credit']);
        $this->assertSame([['6900', '1000000.0000', '0.0000'], ['1150', '110000.0000', '0.0000'], ['2110', '0.0000', '1110000.0000']], $lines->map(fn ($l) => [$l->code, $l->debit, $l->credit])->all());
        $snapshot = json_decode($journal->posting_snapshot, true);
        $this->assertSame('AP-INVOICE', $snapshot['rule']['code']);
        $this->assertSame('DEFAULT', $snapshot['lines'][0]['mapping_scope']);

        $after = $this->glFigures();
        $this->assertSame(3, $after->lines - $before->lines);
        $this->assertSame('1110000.0000', $this->dec(BigDecimal::of((string) $after->debit)->minus((string) $before->debit)));
        $this->assertSame('-1110000.0000', $this->balance('2110'));

        // A second post, or a replay, changes nothing.
        $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_ALREADY_POSTED');
        $this->assertEquals($after, $this->glFigures());
        $this->assertSame(1, $this->rows('journal_entries', ['source_type' => 'ap_invoice', 'source_id' => $id]));
    }

    public function test_lines_are_classified_by_account_category_or_role_and_header_amounts_are_spread_over_them(): void
    {
        $vendor = $this->vendor($this->tenant);
        $category = $this->inTenant($this->tenant, function () {
            $c = new ExpenseCategory(['code' => 'UTIL', 'name' => 'Utilitas']);
            $c->forceFill(['account_id' => $this->account($this->tenant, '6300')->id, 'status' => 'ACTIVE'])->save();

            return $c;
        });
        $id = $this->approved([
            'discount_amount' => '10000', 'other_charges_amount' => '4000', 'tax_amount' => '20000',
            'lines' => [
                ['description' => 'Sewa gedung', 'amount' => '600000', 'account_id' => $this->account($this->tenant, '6200')->id],
                ['description' => 'Listrik', 'amount' => '300000', 'expense_category_id' => $category->id],
                ['description' => 'Bahan', 'amount' => '100000', 'account_role' => 'INVENTORY_ASSET'],
                ['description' => 'Lain', 'amount' => '50000'],
            ],
        ], $vendor);

        $posted = $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertOk()->json();
        $rows = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $posted['journal_entry_id'])->orderBy('l.line_number')->get(['a.code', 'l.debit', 'l.credit']);

        // net = 1.050.000 - 10.000 discount + 4.000 charges = 1.044.000, spread by amount (largest remainder, exact to the rupiah cent)
        $this->assertSame(['6200', '6300', '1140', '6900', '1150', '2110'], $rows->pluck('code')->all());
        $this->assertSame('1044000.0000', $this->dec(BigDecimal::of((string) $rows[0]->debit)->plus((string) $rows[1]->debit)->plus((string) $rows[2]->debit)->plus((string) $rows[3]->debit)));
        $this->assertSame('1064000.0000', (string) $rows->last()->credit);
        $this->assertSame('1064000.0000', $posted['total_amount']);
        $this->assertGreaterThan(0, (float) $rows[0]->debit);
        $snapshot = json_decode(DB::table('journal_entries')->where('id', $posted['journal_entry_id'])->value('posting_snapshot'), true);
        $this->assertSame(['DOCUMENT_LINE', 'DOCUMENT_LINE', 'DEFAULT', 'DEFAULT', 'DEFAULT', 'DEFAULT'], array_column($snapshot['lines'], 'mapping_scope'));
    }

    public function test_a_vendor_payable_override_is_used_and_recorded(): void
    {
        $control = $this->inTenant($this->tenant, function () {
            return app(ChartOfAccountsService::class)->create([
                'code' => '2115', 'name' => 'Utang Usaha Afiliasi', 'account_type' => 'LIABILITY', 'is_postable' => true, 'is_control' => true, 'parent_id' => $this->account($this->tenant, '2100')->id,
            ]);
        });
        $vendor = $this->vendor($this->tenant, 'AFF', ['payable_account_id' => $control->id]);
        $id = $this->approved([], $vendor);
        $posted = $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertOk()->json();

        $this->assertSame($control->id, $posted['payable_account_id']);
        $this->assertSame('-1000000.0000', (string) DB::table('journal_lines')->where('journal_entry_id', $posted['journal_entry_id'])->where('account_id', $control->id)->selectRaw('sum(debit)-sum(credit) as b')->value('b'));
        $snapshot = json_decode(DB::table('journal_entries')->where('id', $posted['journal_entry_id'])->value('posting_snapshot'), true);
        $this->assertSame('DOCUMENT', collect($snapshot['lines'])->firstWhere('account_role', 'ACCOUNTS_PAYABLE')['mapping_scope']);
    }

    public function test_a_posted_invoice_cannot_be_changed_by_the_service_or_the_database(): void
    {
        $id = $this->approved();
        $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertOk();

        $this->patchJson(self::AP."/ap-invoices/{$id}", ['description' => 'Ubah'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_IMMUTABLE');
        $this->postJson(self::AP."/ap-invoices/{$id}/cancel", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_IMMUTABLE');
        $this->postJson(self::AP."/ap-invoices/{$id}/reject", ['reason' => 'x'])->assertStatus(409);
        $this->postJson(self::AP."/ap-invoices/{$id}/reopen")->assertStatus(409);

        $line = DB::table('ap_invoice_lines')->where('ap_invoice_id', $id)->value('id');
        foreach ([
            fn () => DB::table('ap_invoices')->where('id', $id)->update(['total_amount' => 1, 'subtotal_amount' => 1]),
            fn () => DB::table('ap_invoices')->where('id', $id)->update(['description' => 'tamper']),
            fn () => DB::table('ap_invoices')->where('id', $id)->update(['due_date' => '2027-01-01']),
            fn () => DB::table('ap_invoices')->where('id', $id)->update(['vendor_id' => $this->vendor($this->tenant, 'OTHER')->id]),
            fn () => DB::table('ap_invoices')->where('id', $id)->update(['status' => 'DRAFT']),
            fn () => DB::table('ap_invoices')->where('id', $id)->update(['document_number' => 'AP-X']),
            fn () => DB::table('ap_invoices')->where('id', $id)->delete(),
            fn () => DB::table('ap_invoice_lines')->where('id', $line)->update(['amount' => 1]),
            fn () => DB::table('ap_invoice_lines')->where('id', $line)->delete(),
            fn () => DB::table('ap_invoice_lines')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'ap_invoice_id' => $id, 'line_number' => 9, 'description' => 'x', 'amount' => 1, 'created_at' => now(), 'updated_at' => now()]),
            fn () => DB::table('document_transitions')->where('document_id', $id)->delete(),
            fn () => DB::table('document_transitions')->where('document_id', $id)->update(['to_status' => 'DRAFT']),
        ] as $i => $attempt) {
            $this->assertRefused($attempt, "tampering attempt #{$i}");
        }

        // The journal is the posted source of the invoice: it cannot be edited either.
        $journal = DB::table('ap_invoices')->where('id', $id)->value('journal_entry_id');
        $this->assertRefused(fn () => DB::table('journal_entries')->where('id', $journal)->update(['description' => 'tamper']));
        $this->assertRefused(fn () => DB::table('journal_lines')->where('journal_entry_id', $journal)->update(['debit' => 1]));
    }

    public function test_a_posting_that_cannot_complete_leaves_no_trace_and_no_gap_in_the_numbers(): void
    {
        $first = $this->approved();
        // Close March: the invoice is dated in it.
        $this->inTenant($this->tenant, function () {
            $calendar = app(FiscalCalendarService::class);
            $period = AccountingPeriod::query()->where('code', '2026-03')->firstOrFail();
            $calendar->transitionPeriod($period, AccountingPeriod::SOFT_CLOSED);
        });
        $limited = $this->memberToken($this->tenant, ['accounting.ap_invoice.view', 'accounting.ap_invoice.post']);
        $this->as($limited)->postJson(self::AP."/ap-invoices/{$first}/post")->assertStatus(422)->assertJsonPath('code', 'PERIOD_SOFT_CLOSED');
        $this->signedIn($this->tenant);
        $this->inTenant($this->tenant, function () {
            $calendar = app(FiscalCalendarService::class);
            $period = AccountingPeriod::query()->where('code', '2026-03')->firstOrFail();
            $calendar->transitionPeriod($period, AccountingPeriod::CLOSED);
        });

        $this->postJson(self::AP."/ap-invoices/{$first}/post")->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
        $this->assertSame('APPROVED', DB::table('ap_invoices')->where('id', $first)->value('status'));
        $this->assertNull(DB::table('ap_invoices')->where('id', $first)->value('document_number'));
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'ap_invoice']));
        $this->assertSame(0, $this->rows('accounting_events', ['source_id' => $first, 'status' => 'POSTED']));
        $this->assertSame(0, $this->rows('document_sequences', ['sequence_code' => 'AP_INVOICE']));

        // An open period posts fine and gets number 1: the failed attempt consumed none.
        $april = $this->approved(['document_date' => '2026-04-05', 'posting_date' => '2026-04-05']);
        $this->postJson(self::AP."/ap-invoices/{$april}/post")->assertOk()->assertJsonPath('document_number', 'AP-FY2026-000001');
        // Submitting into a closed period is refused early too.
        $draft = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($this->vendor($this->tenant, 'LATE')))->assertCreated()->json('id');
        $this->postJson(self::AP."/ap-invoices/{$draft}/submit")->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
    }

    public function test_a_soft_closed_period_accepts_only_users_who_may_post_there(): void
    {
        $id = $this->approved();
        $this->inTenant($this->tenant, fn () => app(FiscalCalendarService::class)->transitionPeriod(AccountingPeriod::query()->where('code', '2026-03')->firstOrFail(), AccountingPeriod::SOFT_CLOSED));

        $this->as($this->memberToken($this->tenant, ['accounting.ap_invoice.view', 'accounting.ap_invoice.post']))
            ->postJson(self::AP."/ap-invoices/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'PERIOD_SOFT_CLOSED');
        $this->as($this->memberToken($this->tenant, ['accounting.ap_invoice.view', 'accounting.ap_invoice.post', 'accounting.journal.post_soft_closed']))
            ->postJson(self::AP."/ap-invoices/{$id}/post")->assertOk()->assertJsonPath('status', 'POSTED');
    }

    public function test_missing_rules_or_mappings_stop_the_posting_with_a_clear_error(): void
    {
        $id = $this->approved();
        DB::table('posting_rules')->where('tenant_id', $this->tenant->id)->where('event_type', 'AP_INVOICE_RECOGNIZED')->update(['status' => 'ARCHIVED', 'effective_to' => '2026-01-31']);
        $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'POSTING_RULE_NOT_FOUND');
        $this->assertSame('APPROVED', DB::table('ap_invoices')->where('id', $id)->value('status'));

        DB::table('posting_rules')->where('tenant_id', $this->tenant->id)->where('event_type', 'AP_INVOICE_RECOGNIZED')->update(['status' => 'PUBLISHED', 'effective_to' => null]);
        DB::table('account_mappings')->where('tenant_id', $this->tenant->id)->where('account_role', 'ACCOUNTS_PAYABLE')->update(['status' => 'INACTIVE']);
        $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_MAPPING_MISSING');
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'ap_invoice']));
    }

    public function test_an_account_deactivated_after_approval_stops_the_posting(): void
    {
        $account = $this->account($this->tenant, '6200');
        $id = $this->approved(['lines' => [['description' => 'Sewa', 'amount' => '500000', 'account_id' => $account->id]]]);
        $this->postJson(self::AP.'/accounts/'.$account->id.'/status', ['status' => 'INACTIVE'])->assertOk();

        $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_INACTIVE');
        $this->assertSame('APPROVED', DB::table('ap_invoices')->where('id', $id)->value('status'));
    }

    public function test_reversal_uses_the_shared_mechanism_keeps_history_and_frees_the_vendor_number(): void
    {
        $vendor = $this->vendor($this->tenant);
        $id = $this->approved(['vendor_invoice_number' => 'FP-777'], $vendor);
        $posted = $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertOk()->json();
        $afterPost = $this->glFigures();

        $this->postJson(self::AP."/ap-invoices/{$id}/reverse", [])->assertStatus(422); // a reason is mandatory
        $reversed = $this->postJson(self::AP."/ap-invoices/{$id}/reverse", ['reason' => 'Faktur salah vendor', 'posting_date' => '2026-03-20'])->assertOk()
            ->assertJsonPath('status', 'REVERSED')->assertJsonPath('document_number', $posted['document_number'])->json();

        $original = DB::table('journal_entries')->where('id', $posted['journal_entry_id'])->first();
        $reversal = DB::table('journal_entries')->where('id', $reversed['reversal_journal_id'])->first();
        $this->assertSame('POSTED', $original->status); // history is preserved, never rewritten
        $this->assertSame($reversal->id, $original->reversed_by_journal_id);
        $this->assertSame('REVERSAL', $reversal->journal_type);
        $this->assertSame($original->id, $reversal->reverses_journal_id);
        $this->assertSame('2026-03-20', substr($reversed['reversal_posting_date'], 0, 10));
        $this->assertSame('0.0000', $this->balance('2110'));
        $this->assertSame('0.0000', $this->balance('6900'));
        $this->assertSame(2 * $afterPost->lines, (int) $this->glFigures()->lines);

        $this->postJson(self::AP."/ap-invoices/{$id}/reverse", ['reason' => 'Lagi'])->assertStatus(409)->assertJsonPath('code', 'AP_INVOICE_ALREADY_REVERSED');
        $this->assertSame(1, $this->rows('journal_entries', ['reverses_journal_id' => $original->id]));
        $this->assertSame(['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'REVERSED'], DB::table('document_transitions')->where('document_id', $id)->orderBy('occurred_at')->pluck('to_status')->all());

        // The vendor can record the corrected invoice under the same number.
        $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['vendor_invoice_number' => 'FP-777']))->assertCreated();
    }

    public function test_a_reversal_into_a_closed_period_changes_nothing(): void
    {
        $id = $this->approved(['document_date' => '2026-02-10', 'posting_date' => '2026-02-10']);
        $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertOk();
        $this->inTenant($this->tenant, function () {
            $calendar = app(FiscalCalendarService::class);
            $period = AccountingPeriod::query()->where('code', '2026-02')->firstOrFail();
            $calendar->transitionPeriod($period, AccountingPeriod::SOFT_CLOSED);
            $calendar->transitionPeriod($period, AccountingPeriod::CLOSED);
        });
        $before = $this->glFigures();

        $this->postJson(self::AP."/ap-invoices/{$id}/reverse", ['reason' => 'Terlambat'])->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
        $this->assertSame('POSTED', DB::table('ap_invoices')->where('id', $id)->value('status'));
        $this->assertEquals($before, $this->glFigures());
        $this->postJson(self::AP."/ap-invoices/{$id}/reverse", ['reason' => 'Di periode terbuka', 'posting_date' => '2026-03-05'])->assertOk()->assertJsonPath('status', 'REVERSED');
    }

    public function test_posting_and_reversing_are_audited_with_the_actor(): void
    {
        $id = $this->approved();
        $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$id}/reverse", ['reason' => 'Koreksi'])->assertOk();

        $actions = DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('resource_id', $id)->orderBy('occurred_at')->pluck('action')->all();
        $this->assertSame(['payables.ap_invoice.created', 'payables.ap_invoice.submitted', 'payables.ap_invoice.approved', 'payables.ap_invoice.posted', 'payables.ap_invoice.reversed'], $actions);
        $this->assertSame(5, DB::table('audit_logs')->where('resource_id', $id)->whereNotNull('actor_user_id')->count());
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
