<?php

namespace Tests\Feature\Receivables;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Services\ChartOfAccountsService;
use App\Domain\Accounting\Services\FiscalCalendarService;
use App\Domain\Identity\Models\Tenant;
use Brick\Math\BigDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\TestCase;

/** OA3 batches B-C: customer invoice posting through the Posting Engine, immutability, period control and reversal. */
class ArInvoicePostingTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, ReceivablesFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->receivablesTenant('alpha', ['sod_creator_not_approver' => false]);
        $this->signedIn($this->tenant);
    }

    /** Create, submit and approve an invoice through the API; returns its id. */
    private function approved(array $override = [], ?object $customer = null): string
    {
        $customer ??= $this->customer($this->tenant, 'C'.substr(uniqid(), -4));
        $id = $this->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer, $override))->assertCreated()->json('id');
        $this->postJson(self::AR."/ar-invoices/{$id}/submit")->assertOk();
        $this->postJson(self::AR."/ar-invoices/{$id}/approve")->assertOk();

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

        return (string) BigDecimal::of((string) DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.status', 'POSTED')->where('l.account_id', $id)
            ->selectRaw('coalesce(sum(l.debit),0) - coalesce(sum(l.credit),0) as b')->value('b'))->toScale(4);
    }

    public function test_draft_submitted_and_approved_invoices_never_reach_the_ledger_and_posting_moves_it_exactly_once(): void
    {
        $before = $this->glFigures();
        $customer = $this->customer($this->tenant);
        $id = $this->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer, ['tax_amount' => '110000']))->assertCreated()->json('id');
        $this->assertEquals($before, $this->glFigures());
        $this->postJson(self::AR."/ar-invoices/{$id}/submit")->assertOk();
        $this->assertEquals($before, $this->glFigures());
        $this->postJson(self::AR."/ar-invoices/{$id}/approve")->assertOk();
        $this->assertEquals($before, $this->glFigures());
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'ar_invoice']));

        $posted = $this->postJson(self::AR."/ar-invoices/{$id}/post")->assertOk()->assertJsonPath('status', 'POSTED')->json();
        $this->assertSame('INV-FY2026-000001', $posted['document_number']);
        $this->assertSame($this->account($this->tenant, '1130')->id, $posted['receivable_account_id']);

        $journal = DB::table('journal_entries')->where('id', $posted['journal_entry_id'])->first();
        $this->assertSame('SYSTEM', $journal->journal_type);
        $this->assertSame('POSTED', $journal->status);
        $this->assertSame('ar_invoice', $journal->source_type);
        $this->assertSame($id, $journal->source_id);
        $this->assertSame('1110000.0000', $journal->total_debit);
        $this->assertSame(1, $this->rows('accounting_events', ['source_id' => $id, 'status' => 'POSTED', 'event_type' => 'AR_INVOICE_RECOGNIZED']));

        // The classic shape: Dr accounts receivable (total), Cr revenue (net) + Cr output VAT, all accounts resolved by role mapping.
        $lines = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $journal->id)->orderBy('l.line_number')->get(['a.code', 'l.debit', 'l.credit']);
        $this->assertSame([['1130', '1110000.0000', '0.0000'], ['4100', '0.0000', '1000000.0000'], ['2120', '0.0000', '110000.0000']], $lines->map(fn ($l) => [$l->code, $l->debit, $l->credit])->all());
        $snapshot = json_decode($journal->posting_snapshot, true);
        $this->assertSame('AR-INVOICE', $snapshot['rule']['code']);
        $this->assertSame('DEFAULT', $snapshot['lines'][0]['mapping_scope']);

        $after = $this->glFigures();
        $this->assertSame(3, $after->lines - $before->lines);
        $this->assertSame('1110000.0000', $this->dec(BigDecimal::of((string) $after->debit)->minus((string) $before->debit)));
        $this->assertSame('1110000.0000', $this->balance('1130'));

        // A second post, or a replay, changes nothing.
        $this->postJson(self::AR."/ar-invoices/{$id}/post")->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_ALREADY_POSTED');
        $this->assertEquals($after, $this->glFigures());
        $this->assertSame(1, $this->rows('journal_entries', ['source_type' => 'ar_invoice', 'source_id' => $id]));
    }

    public function test_lines_are_classified_by_account_role_or_the_customer_default_and_header_amounts_are_spread_over_them(): void
    {
        $default = $this->account($this->tenant, '4200');
        $customer = $this->customer($this->tenant, 'DEF', ['default_revenue_account_id' => $default->id]);
        $id = $this->approved([
            'discount_amount' => '10000', 'other_charges_amount' => '4000', 'tax_amount' => '20000',
            'lines' => [
                ['description' => 'Jasa angkut', 'amount' => '600000', 'account_id' => $this->account($this->tenant, '4100')->id],
                ['description' => 'Jasa sewa', 'amount' => '300000', 'account_role' => 'REVENUE_ADJUSTMENT'],
                ['description' => 'Lain', 'amount' => '150000'],
            ],
        ], $customer);

        $posted = $this->postJson(self::AR."/ar-invoices/{$id}/post")->assertOk()->json();
        $rows = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $posted['journal_entry_id'])->orderBy('l.line_number')->get(['a.code', 'l.debit', 'l.credit']);

        // net = 1.050.000 - 10.000 discount + 4.000 charges = 1.044.000, spread by amount (largest remainder, exact to the cent):
        // own account, own role (contra revenue), then the customer's default revenue account for the unclassified line.
        $this->assertSame(['1130', '4100', '4150', '4200', '2120'], $rows->pluck('code')->all());
        $this->assertSame('1064000.0000', (string) $rows->first()->debit);
        $this->assertSame('1044000.0000', $this->dec(BigDecimal::of((string) $rows[1]->credit)->plus((string) $rows[2]->credit)->plus((string) $rows[3]->credit)));
        $this->assertSame('1064000.0000', $posted['total_amount']);
        $this->assertGreaterThan(0, (float) $rows[1]->credit);
        $snapshot = json_decode(DB::table('journal_entries')->where('id', $posted['journal_entry_id'])->value('posting_snapshot'), true);
        $this->assertSame(['DEFAULT', 'DOCUMENT_LINE', 'DEFAULT', 'DOCUMENT_LINE', 'DEFAULT'], array_column($snapshot['lines'], 'mapping_scope'));
    }

    public function test_a_customer_receivable_override_is_used_and_recorded(): void
    {
        $control = $this->inTenant($this->tenant, function () {
            return app(ChartOfAccountsService::class)->create([
                'code' => '1135', 'name' => 'Piutang Usaha Afiliasi', 'account_type' => 'ASSET', 'is_postable' => true, 'is_control' => true, 'parent_id' => $this->account($this->tenant, '1100')->id,
            ]);
        });
        $customer = $this->customer($this->tenant, 'AFF', ['receivable_account_id' => $control->id]);
        $id = $this->approved([], $customer);
        $posted = $this->postJson(self::AR."/ar-invoices/{$id}/post")->assertOk()->json();

        $this->assertSame($control->id, $posted['receivable_account_id']);
        $this->assertSame('1000000.0000', (string) DB::table('journal_lines')->where('journal_entry_id', $posted['journal_entry_id'])->where('account_id', $control->id)->selectRaw('sum(debit)-sum(credit) as b')->value('b'));
        $snapshot = json_decode(DB::table('journal_entries')->where('id', $posted['journal_entry_id'])->value('posting_snapshot'), true);
        $this->assertSame('DOCUMENT', collect($snapshot['lines'])->firstWhere('account_role', 'ACCOUNTS_RECEIVABLE')['mapping_scope']);
    }

    public function test_a_posted_invoice_cannot_be_changed_by_the_service_or_the_database(): void
    {
        $id = $this->approved();
        $this->postJson(self::AR."/ar-invoices/{$id}/post")->assertOk();

        $this->patchJson(self::AR."/ar-invoices/{$id}", ['description' => 'Ubah'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_IMMUTABLE');
        $this->postJson(self::AR."/ar-invoices/{$id}/cancel", ['reason' => 'x'])->assertStatus(409)->assertJsonPath('code', 'DOCUMENT_IMMUTABLE');
        $this->postJson(self::AR."/ar-invoices/{$id}/reject", ['reason' => 'x'])->assertStatus(409);
        $this->postJson(self::AR."/ar-invoices/{$id}/reopen")->assertStatus(409);

        $line = DB::table('ar_invoice_lines')->where('ar_invoice_id', $id)->value('id');
        foreach ([
            fn () => DB::table('ar_invoices')->where('id', $id)->update(['total_amount' => 1, 'subtotal_amount' => 1]),
            fn () => DB::table('ar_invoices')->where('id', $id)->update(['description' => 'tamper']),
            fn () => DB::table('ar_invoices')->where('id', $id)->update(['due_date' => '2027-01-01']),
            fn () => DB::table('ar_invoices')->where('id', $id)->update(['customer_id' => $this->customer($this->tenant, 'OTHER')->id]),
            fn () => DB::table('ar_invoices')->where('id', $id)->update(['status' => 'DRAFT']),
            fn () => DB::table('ar_invoices')->where('id', $id)->update(['document_number' => 'INV-X']),
            fn () => DB::table('ar_invoices')->where('id', $id)->delete(),
            fn () => DB::table('ar_invoice_lines')->where('id', $line)->update(['amount' => 1]),
            fn () => DB::table('ar_invoice_lines')->where('id', $line)->delete(),
            fn () => DB::table('ar_invoice_lines')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'ar_invoice_id' => $id, 'line_number' => 9, 'description' => 'x', 'amount' => 1, 'created_at' => now(), 'updated_at' => now()]),
            fn () => DB::table('document_transitions')->where('document_id', $id)->delete(),
            fn () => DB::table('document_transitions')->where('document_id', $id)->update(['to_status' => 'DRAFT']),
        ] as $i => $attempt) {
            $this->assertRefused($attempt, "tampering attempt #{$i}");
        }

        // The journal is the posted source of the invoice: it cannot be edited either.
        $journal = DB::table('ar_invoices')->where('id', $id)->value('journal_entry_id');
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
        $limited = $this->memberToken($this->tenant, ['accounting.ar_invoice.view', 'accounting.ar_invoice.post']);
        $this->as($limited)->postJson(self::AR."/ar-invoices/{$first}/post")->assertStatus(422)->assertJsonPath('code', 'PERIOD_SOFT_CLOSED');
        $this->signedIn($this->tenant);
        $this->inTenant($this->tenant, function () {
            $calendar = app(FiscalCalendarService::class);
            $period = AccountingPeriod::query()->where('code', '2026-03')->firstOrFail();
            $calendar->transitionPeriod($period, AccountingPeriod::CLOSED);
        });

        $this->postJson(self::AR."/ar-invoices/{$first}/post")->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
        $this->assertSame('APPROVED', DB::table('ar_invoices')->where('id', $first)->value('status'));
        $this->assertNull(DB::table('ar_invoices')->where('id', $first)->value('document_number'));
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'ar_invoice']));
        $this->assertSame(0, $this->rows('accounting_events', ['source_id' => $first, 'status' => 'POSTED']));
        $this->assertSame(0, $this->rows('document_sequences', ['sequence_code' => 'AR_INVOICE']));

        // An open period posts fine and gets number 1: the failed attempt consumed none.
        $april = $this->approved(['document_date' => '2026-04-05', 'posting_date' => '2026-04-05']);
        $this->postJson(self::AR."/ar-invoices/{$april}/post")->assertOk()->assertJsonPath('document_number', 'INV-FY2026-000001');
        // Submitting into a closed period is refused early too.
        $draft = $this->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($this->customer($this->tenant, 'LATE')))->assertCreated()->json('id');
        $this->postJson(self::AR."/ar-invoices/{$draft}/submit")->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
    }

    public function test_a_soft_closed_period_accepts_only_users_who_may_post_there(): void
    {
        $id = $this->approved();
        $this->inTenant($this->tenant, fn () => app(FiscalCalendarService::class)->transitionPeriod(AccountingPeriod::query()->where('code', '2026-03')->firstOrFail(), AccountingPeriod::SOFT_CLOSED));

        $this->as($this->memberToken($this->tenant, ['accounting.ar_invoice.view', 'accounting.ar_invoice.post']))
            ->postJson(self::AR."/ar-invoices/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'PERIOD_SOFT_CLOSED');
        $this->as($this->memberToken($this->tenant, ['accounting.ar_invoice.view', 'accounting.ar_invoice.post', 'accounting.journal.post_soft_closed']))
            ->postJson(self::AR."/ar-invoices/{$id}/post")->assertOk()->assertJsonPath('status', 'POSTED');
    }

    public function test_missing_rules_or_mappings_stop_the_posting_with_a_clear_error(): void
    {
        $id = $this->approved();
        DB::table('posting_rules')->where('tenant_id', $this->tenant->id)->where('event_type', 'AR_INVOICE_RECOGNIZED')->update(['status' => 'ARCHIVED', 'effective_to' => '2026-01-31']);
        $this->postJson(self::AR."/ar-invoices/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'POSTING_RULE_NOT_FOUND');
        $this->assertSame('APPROVED', DB::table('ar_invoices')->where('id', $id)->value('status'));

        DB::table('posting_rules')->where('tenant_id', $this->tenant->id)->where('event_type', 'AR_INVOICE_RECOGNIZED')->update(['status' => 'PUBLISHED', 'effective_to' => null]);
        DB::table('account_mappings')->where('tenant_id', $this->tenant->id)->where('account_role', 'ACCOUNTS_RECEIVABLE')->update(['status' => 'INACTIVE']);
        $this->postJson(self::AR."/ar-invoices/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_MAPPING_MISSING');
        $this->assertSame(0, $this->rows('journal_entries', ['source_type' => 'ar_invoice']));
    }

    public function test_an_account_deactivated_after_approval_stops_the_posting(): void
    {
        $account = $this->account($this->tenant, '4200');
        $id = $this->approved(['lines' => [['description' => 'Jasa lain', 'amount' => '500000', 'account_id' => $account->id]]]);
        $this->postJson(self::AR.'/accounts/'.$account->id.'/status', ['status' => 'INACTIVE'])->assertOk();

        $this->postJson(self::AR."/ar-invoices/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'ACCOUNT_INACTIVE');
        $this->assertSame('APPROVED', DB::table('ar_invoices')->where('id', $id)->value('status'));
    }

    public function test_reversal_uses_the_shared_mechanism_keeps_history_and_keeps_the_customer_reference_reusable(): void
    {
        $customer = $this->customer($this->tenant);
        $id = $this->approved(['customer_reference' => 'FP-777'], $customer);
        $posted = $this->postJson(self::AR."/ar-invoices/{$id}/post")->assertOk()->json();
        $afterPost = $this->glFigures();

        $this->postJson(self::AR."/ar-invoices/{$id}/reverse", [])->assertStatus(422); // a reason is mandatory
        $reversed = $this->postJson(self::AR."/ar-invoices/{$id}/reverse", ['reason' => 'Faktur salah customer', 'posting_date' => '2026-03-20'])->assertOk()
            ->assertJsonPath('status', 'REVERSED')->assertJsonPath('document_number', $posted['document_number'])->json();

        $original = DB::table('journal_entries')->where('id', $posted['journal_entry_id'])->first();
        $reversal = DB::table('journal_entries')->where('id', $reversed['reversal_journal_id'])->first();
        $this->assertSame('POSTED', $original->status); // history is preserved, never rewritten
        $this->assertSame($reversal->id, $original->reversed_by_journal_id);
        $this->assertSame('REVERSAL', $reversal->journal_type);
        $this->assertSame($original->id, $reversal->reverses_journal_id);
        $this->assertSame('2026-03-20', substr($reversed['reversal_posting_date'], 0, 10));
        $this->assertSame('0.0000', $this->balance('1130'));
        $this->assertSame('0.0000', $this->balance('4100'));
        $this->assertSame(2 * $afterPost->lines, (int) $this->glFigures()->lines);

        $this->postJson(self::AR."/ar-invoices/{$id}/reverse", ['reason' => 'Lagi'])->assertStatus(409)->assertJsonPath('code', 'AR_INVOICE_ALREADY_REVERSED');
        $this->assertSame(1, $this->rows('journal_entries', ['reverses_journal_id' => $original->id]));
        $this->assertSame(['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'REVERSED'], DB::table('document_transitions')->where('document_id', $id)->orderBy('occurred_at')->pluck('to_status')->all());

        // The customer's reference is not a unique key: the corrected invoice can carry the same one.
        $this->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer, ['customer_reference' => 'FP-777']))->assertCreated();
    }

    public function test_a_reversal_into_a_closed_period_changes_nothing(): void
    {
        $id = $this->approved(['document_date' => '2026-02-10', 'posting_date' => '2026-02-10']);
        $this->postJson(self::AR."/ar-invoices/{$id}/post")->assertOk();
        $this->inTenant($this->tenant, function () {
            $calendar = app(FiscalCalendarService::class);
            $period = AccountingPeriod::query()->where('code', '2026-02')->firstOrFail();
            $calendar->transitionPeriod($period, AccountingPeriod::SOFT_CLOSED);
            $calendar->transitionPeriod($period, AccountingPeriod::CLOSED);
        });
        $before = $this->glFigures();

        $this->postJson(self::AR."/ar-invoices/{$id}/reverse", ['reason' => 'Terlambat'])->assertStatus(422)->assertJsonPath('code', 'PERIOD_CLOSED');
        $this->assertSame('POSTED', DB::table('ar_invoices')->where('id', $id)->value('status'));
        $this->assertEquals($before, $this->glFigures());
        $this->postJson(self::AR."/ar-invoices/{$id}/reverse", ['reason' => 'Di periode terbuka', 'posting_date' => '2026-03-05'])->assertOk()->assertJsonPath('status', 'REVERSED');
    }

    public function test_posting_and_reversing_are_audited_with_the_actor(): void
    {
        $id = $this->approved();
        $this->postJson(self::AR."/ar-invoices/{$id}/post")->assertOk();
        $this->postJson(self::AR."/ar-invoices/{$id}/reverse", ['reason' => 'Koreksi'])->assertOk();

        $actions = DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('resource_id', $id)->orderBy('occurred_at')->pluck('action')->all();
        $this->assertSame(['receivables.ar_invoice.created', 'receivables.ar_invoice.submitted', 'receivables.ar_invoice.approved', 'receivables.ar_invoice.posted', 'receivables.ar_invoice.reversed'], $actions);
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
