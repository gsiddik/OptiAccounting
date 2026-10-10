<?php

namespace Tests\Feature\Tax;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/** OA4: tax on AP invoices, expenses and AR invoices: calculation, the snapshot, the journal the Posting Engine builds, and what a later configuration change may not touch. */
class TaxDocumentTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, ReceivablesFixtures, TaxFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->taxTenant();
        $this->signedIn($this->tenant);
    }

    private function journalOf(string $table, string $id): string
    {
        return (string) DB::table($table)->where('id', $id)->value('journal_entry_id');
    }

    // ------------------------------------------------------------------------------------------------ AP invoice

    public function test_an_exclusive_input_tax_is_calculated_snapshotted_and_posted_to_the_tax_receivable_account(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode(['code' => 'PPN11']);

        $draft = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $code['id']]]]))->assertCreated()->json();
        $this->assertSame(['1000000.0000', '110000.0000', '1110000.0000'], [$draft['subtotal_amount'], $draft['tax_amount'], $draft['total_amount']]);
        $this->assertSame('DRAFT', $this->taxRows('ap_invoice', $draft['id'])[0]->status);

        $this->postJson(self::AP."/ap-invoices/{$draft['id']}/submit")->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$draft['id']}/approve")->assertOk();
        $posted = $this->postJson(self::AP."/ap-invoices/{$draft['id']}/post")->assertOk()->json();

        $this->assertSame([['1150', '110000.0000', '0.0000'], ['2110', '0.0000', '1110000.0000'], ['6900', '1000000.0000', '0.0000']],
            $this->sortedJournal($this->journalOf('ap_invoices', $posted['id'])));
        $row = $this->taxRows('ap_invoice', $posted['id'])[0];
        $this->assertSame(['POSTED', 'INPUT', 'PPN11', 'INPUT_TAX', 'EXCLUSIVE', '11.000000', '1000000.0000', '1000000.0000', '110000.0000', 'TAX_RECEIVABLE', $posted['document_number'], '2026-03-10', 1],
            [$row->status, $row->direction, $row->tax_code, $row->tax_type, $row->calculation_method, $row->rate, $row->entered_amount, $row->base_amount, $row->tax_amount, $row->account_role, $row->document_number, $row->tax_date, $row->line_number]);
        $this->assertSame($this->journalOf('ap_invoices', $posted['id']), $row->journal_entry_id);
        $this->assertSame('vendor', $row->counterparty_type);
        $this->assertSame('110000.0000', $this->glBalance($this->tenant, '1150'));
    }

    public function test_an_inclusive_code_carves_the_tax_out_of_the_amount_entered(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode(['calculation_method' => 'INCLUSIVE']);

        $posted = $this->postedTaxedInvoice($vendor, $code['id'], '1110000');

        $this->assertSame(['1000000.0000', '110000.0000', '1110000.0000'], [$posted['subtotal_amount'], $posted['tax_amount'], $posted['total_amount']]);
        $line = DB::table('ap_invoice_lines')->where('ap_invoice_id', $posted['id'])->first();
        $this->assertSame(['1000000.0000', '1110000.0000'], [$line->amount, $line->entered_amount]);
        $this->assertSame([['1150', '110000.0000', '0.0000'], ['2110', '0.0000', '1110000.0000'], ['6900', '1000000.0000', '0.0000']],
            $this->sortedJournal($this->journalOf('ap_invoices', $posted['id'])));

        // saving the draft again without lines keeps what was entered, not the base
        $id = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '555000', 'tax_code_id' => $code['id']]]]))->assertCreated()->json('id');
        $again = $this->patchJson(self::AP."/ap-invoices/{$id}", ['description' => 'Ubah'])->assertOk()->json();
        $this->assertSame(['555000.0000', '55000.0000'], [$again['total_amount'], $again['tax_amount']]);
    }

    public function test_zero_rated_and_exempt_codes_record_the_transaction_with_no_tax(): void
    {
        $vendor = $this->vendor($this->tenant);
        $zero = $this->taxCode(['treatment' => 'ZERO_RATED', 'rate' => '0']);
        $exempt = $this->taxCode(['treatment' => 'EXEMPT', 'rate' => '0']);

        $posted = $this->postedInvoice($vendor, ['lines' => [
            ['description' => 'Ekspor', 'amount' => '500000', 'tax_code_id' => $zero['id']], ['description' => 'Bebas', 'amount' => '300000', 'tax_code_id' => $exempt['id']],
        ]]);

        $this->assertSame(['800000.0000', '0.0000', '800000.0000'], [$posted['subtotal_amount'], $posted['tax_amount'], $posted['total_amount']]);
        $this->assertSame(['0.0000', '0.0000'], array_map(fn ($r) => $r->tax_amount, $this->taxRows('ap_invoice', $posted['id'])));
        $this->assertSame([['2110', '0.0000', '800000.0000'], ['6900', '800000.0000', '0.0000']], $this->sortedJournal($this->journalOf('ap_invoices', $posted['id'])));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1150'));
    }

    public function test_a_non_recoverable_input_tax_becomes_part_of_the_cost_of_its_line(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode(['is_recoverable' => false]);

        $posted = $this->postedTaxedInvoice($vendor, $code['id']);

        $this->assertSame('1110000.0000', $posted['total_amount']);
        $this->assertSame([['2110', '0.0000', '1110000.0000'], ['6900', '1110000.0000', '0.0000']], $this->sortedJournal($this->journalOf('ap_invoices', $posted['id'])));
        $row = $this->taxRows('ap_invoice', $posted['id'])[0];
        $this->assertSame(['110000.0000', false], [$row->tax_amount, (bool) $row->is_recoverable]);
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1150'));
    }

    public function test_the_tax_posts_to_the_account_the_code_names_and_not_a_hardcoded_one(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode(['account_id' => $this->account($this->tenant, '1160')->id]);

        $posted = $this->postedTaxedInvoice($vendor, $code['id']);

        $this->assertSame([['1160', '110000.0000', '0.0000'], ['2110', '0.0000', '1110000.0000'], ['6900', '1000000.0000', '0.0000']],
            $this->sortedJournal($this->journalOf('ap_invoices', $posted['id'])));
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1150'));
    }

    public function test_two_codes_on_one_invoice_post_one_tax_line_per_account(): void
    {
        $vendor = $this->vendor($this->tenant);
        $a = $this->taxCode(['rate' => '11']);
        $b = $this->taxCode(['rate' => '5', 'account_id' => $this->account($this->tenant, '1160')->id]);

        $posted = $this->postedInvoice($vendor, ['lines' => [
            ['description' => 'A', 'amount' => '1000000', 'tax_code_id' => $a['id']], ['description' => 'B', 'amount' => '400000', 'tax_code_id' => $b['id']],
            ['description' => 'C tanpa pajak', 'amount' => '100000'],
        ]]);

        $this->assertSame(['1500000.0000', '130000.0000', '1630000.0000'], [$posted['subtotal_amount'], $posted['tax_amount'], $posted['total_amount']]);
        $this->assertSame([['1150', '110000.0000', '0.0000'], ['1160', '20000.0000', '0.0000'], ['2110', '0.0000', '1630000.0000'], ['6900', '1500000.0000', '0.0000']],
            $this->sortedJournal($this->journalOf('ap_invoices', $posted['id'])));
        $this->assertSame([1, 2], array_map(fn ($r) => $r->line_number, $this->taxRows('ap_invoice', $posted['id'])));
    }

    public function test_a_rate_change_never_moves_a_posted_transaction_and_the_next_document_uses_the_new_rate(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode(['rate' => '11', 'effective_from' => '2026-01-01']);

        $before = $this->postedTaxedInvoice($vendor, $code['id'], '1000000', ['document_date' => '2026-03-10', 'posting_date' => '2026-03-10']);
        $this->postJson(self::TX."/tax-codes/{$code['id']}/rates", ['rate' => '12', 'effective_from' => '2026-04-01'])->assertCreated();
        $after = $this->postedTaxedInvoice($vendor, $code['id'], '1000000', ['document_date' => '2026-04-10', 'posting_date' => '2026-04-10']);

        $this->assertSame('110000.0000', $this->taxRows('ap_invoice', $before['id'])[0]->tax_amount);
        $this->assertSame(['12.000000', '120000.0000'], [$this->taxRows('ap_invoice', $after['id'])[0]->rate, $this->taxRows('ap_invoice', $after['id'])[0]->tax_amount]);
        $this->assertSame('230000.0000', $this->glBalance($this->tenant, '1150'));
    }

    public function test_the_rate_is_the_one_in_force_on_the_document_date_not_the_posting_date(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode(['rate' => '11', 'effective_from' => '2026-01-01']);
        $this->postJson(self::TX."/tax-codes/{$code['id']}/rates", ['rate' => '12', 'effective_from' => '2026-04-01'])->assertCreated();

        $posted = $this->postedTaxedInvoice($vendor, $code['id'], '1000000', ['document_date' => '2026-03-31', 'posting_date' => '2026-04-02']);

        $this->assertSame('110000.0000', $posted['tax_amount']);
        $this->assertSame(['2026-03-31', '2026-04-02'], [$this->taxRows('ap_invoice', $posted['id'])[0]->tax_date, $this->taxRows('ap_invoice', $posted['id'])[0]->posting_date]);
    }

    public function test_a_new_rate_only_reaches_documents_dated_on_or_after_it(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode(['rate' => '11', 'effective_from' => '2026-01-01']);
        $id = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $code['id']]]]))->assertCreated()->json('id');
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();

        $this->postJson(self::TX."/tax-codes/{$code['id']}/rates", ['rate' => '12', 'effective_from' => '2026-03-11'])->assertCreated();

        // the saved draft is dated 2026-03-10: still in the 11% period, nothing changed for it
        $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();
        $this->assertSame('110000.0000', $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertOk()->json('tax_amount'));
        $later = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['document_date' => '2026-03-20', 'posting_date' => '2026-03-20', 'lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $code['id']]]]))->assertCreated()->json();
        $this->assertSame('120000.0000', $later['tax_amount']);
    }

    public function test_a_configuration_change_while_submitted_is_recalculated_by_sending_the_draft_back(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode();
        $id = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $code['id']]]]))->assertCreated()->json('id');
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();

        $this->patchJson(self::TX."/tax-codes/{$code['id']}", ['account_id' => $this->account($this->tenant, '1160')->id])->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertStatus(409)->assertJsonPath('code', 'TAX_CONFIGURATION_CHANGED');

        $this->postJson(self::AP."/ap-invoices/{$id}/reject", ['reason' => 'Hitung ulang pajak'])->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$id}/reopen")->assertOk();
        $this->patchJson(self::AP."/ap-invoices/{$id}", ['description' => 'Disimpan ulang'])->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();
        $posted = $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertOk()->json();

        $this->assertSame([['1160', '110000.0000', '0.0000'], ['2110', '0.0000', '1110000.0000'], ['6900', '1000000.0000', '0.0000']], $this->sortedJournal($this->journalOf('ap_invoices', $posted['id'])));
    }

    public function test_a_configuration_change_after_approval_blocks_posting_instead_of_posting_a_stale_tax(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode();
        $id = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $code['id']]]]))->assertCreated()->json('id');
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();
        $before = $this->glFigures($this->tenant);

        $this->patchJson(self::TX."/tax-codes/{$code['id']}", ['account_id' => $this->account($this->tenant, '1160')->id])->assertOk();

        $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertStatus(409)->assertJsonPath('code', 'TAX_CONFIGURATION_CHANGED');
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->assertSame('DRAFT', $this->taxRows('ap_invoice', $id)[0]->status);
        $this->postJson(self::AP."/ap-invoices/{$id}/cancel", ['reason' => 'Konfigurasi pajak berubah'])->assertOk();
        $this->assertSame([], $this->taxRows('ap_invoice', $id));
    }

    public function test_a_code_deactivated_after_the_draft_was_saved_blocks_posting(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode();
        $id = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $code['id']]]]))->assertCreated()->json('id');
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();
        $this->postJson(self::TX."/tax-codes/{$code['id']}/deactivate")->assertOk();

        $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertStatus(409)->assertJsonPath('code', 'TAX_CODE_INACTIVE');
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1150'));
    }

    public function test_the_document_refuses_codes_that_do_not_fit_it(): void
    {
        $vendor = $this->vendor($this->tenant);
        $input = $this->taxCode();
        $output = $this->outputCode();
        $withholding = $this->taxCode(['tax_type' => 'WITHHOLDING', 'rate' => '2']);
        $inactive = $this->taxCode();
        $this->postJson(self::TX."/tax-codes/{$inactive['id']}/deactivate")->assertOk();
        $body = fn (string $codeId) => $this->invoiceBody($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $codeId]]]);

        $this->postJson(self::AP.'/ap-invoices', $body($output['id']))->assertStatus(422)->assertJsonPath('code', 'TAX_TYPE_MISMATCH');
        $this->postJson(self::AP.'/ap-invoices', $body($withholding['id']))->assertStatus(422)->assertJsonPath('code', 'TAX_WITHHOLDING_UNSUPPORTED');
        $this->postJson(self::AP.'/ap-invoices', $body($inactive['id']))->assertStatus(422)->assertJsonPath('code', 'TAX_CODE_INACTIVE');
        $this->postJson(self::AP.'/ap-invoices', $body('0198c0de-0000-7000-8000-000000000000'))->assertStatus(422)->assertJsonPath('code', 'TAX_CODE_NOT_FOUND');
        $this->postJson(self::AP.'/ap-invoices', $body($input['id']) + ['discount_amount' => '1000'])->assertStatus(422)->assertJsonPath('code', 'TAX_HEADER_ADJUSTMENT_UNSUPPORTED');
        $this->postJson(self::AP.'/ap-invoices', $body($input['id']) + ['tax_amount' => '999'])->assertStatus(422)->assertJsonPath('code', 'TAX_AMOUNT_CONFLICT');
        $this->postJson(self::AP.'/ap-invoices', $body($input['id']) + ['tax_amount' => '110000'])->assertCreated()->assertJsonPath('tax_amount', '110000.0000');

        // a code with no rate on the document date
        $late = $this->taxCode(['effective_from' => '2026-06-01']);
        $this->postJson(self::AP.'/ap-invoices', $body($late['id']))->assertStatus(422)->assertJsonPath('code', 'TAX_RATE_NOT_FOUND');
    }

    public function test_a_tax_code_of_another_tenant_cannot_be_used(): void
    {
        $vendor = $this->vendor($this->tenant);
        $bravo = $this->taxTenant('bravo');
        $this->signedIn($bravo);
        $foreign = $this->taxCode();
        $this->signedIn($this->tenant);

        $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $foreign['id']]]]))
            ->assertStatus(422)->assertJsonPath('code', 'TAX_CODE_NOT_FOUND');
        $this->assertSame(0, DB::table('ap_invoice_lines')->where('tax_code_id', $foreign['id'])->count());
        $this->assertSame(0, DB::table('tax_transactions')->where('tax_code_id', $foreign['id'])->count());
    }

    public function test_a_document_without_a_tax_code_keeps_the_manual_header_tax_exactly_as_before(): void
    {
        $vendor = $this->vendor($this->tenant);

        $posted = $this->postedInvoice($vendor, ['tax_amount' => '110000']);

        $this->assertSame('1110000.0000', $posted['total_amount']);
        $this->assertSame([['1150', '110000.0000', '0.0000'], ['2110', '0.0000', '1110000.0000'], ['6900', '1000000.0000', '0.0000']], $this->sortedJournal($this->journalOf('ap_invoices', $posted['id'])));
        $this->assertSame([], $this->taxRows('ap_invoice', $posted['id']));
        $this->assertSame(0, DB::table('tax_transactions')->count());
    }

    public function test_taking_the_tax_code_off_a_draft_removes_its_tax(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode();
        $id = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $code['id']]]]))->assertCreated()->json('id');

        $plain = $this->patchJson(self::AP."/ap-invoices/{$id}", ['lines' => [['description' => 'Jasa', 'amount' => '1000000']]])->assertOk()->json();

        $this->assertSame(['0.0000', '1000000.0000'], [$plain['tax_amount'], $plain['total_amount']]);
        $this->assertSame([], $this->taxRows('ap_invoice', $id));
    }

    public function test_cancel_discards_the_draft_tax_and_reversal_marks_the_posted_tax_reversed(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode();
        $draft = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $code['id']]]]))->assertCreated()->json('id');
        $this->postJson(self::AP."/ap-invoices/{$draft}/cancel", ['reason' => 'Salah input'])->assertOk();
        $this->assertSame([], $this->taxRows('ap_invoice', $draft));

        $posted = $this->postedTaxedInvoice($vendor, $code['id']);
        $this->postJson(self::AP."/ap-invoices/{$posted['id']}/reverse", ['reason' => 'Dibatalkan'])->assertOk();

        $row = $this->taxRows('ap_invoice', $posted['id'])[0];
        $this->assertSame('REVERSED', $row->status);
        $this->assertNotNull($row->reversal_journal_id);
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1150'));
    }

    public function test_a_closed_period_blocks_posting_and_leaves_the_tax_in_draft(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode();
        $id = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $code['id']]]]))->assertCreated()->json('id');
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();
        $this->postJson(self::TX.'/periods/'.$this->period($this->tenant, '2026-03')->id.'/close')->assertOk();

        $this->postJson(self::AP."/ap-invoices/{$id}/post")->assertStatus(422);

        $this->assertSame('DRAFT', $this->taxRows('ap_invoice', $id)[0]->status);
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '1150'));
    }

    public function test_the_database_refuses_to_change_a_posted_tax_transaction_or_post_one_without_its_journal(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode();
        $posted = $this->postedTaxedInvoice($vendor, $code['id']);
        $row = $this->taxRows('ap_invoice', $posted['id'])[0];

        foreach ([['tax_amount' => '1.0000'], ['rate' => '5'], ['tax_code' => 'X'], ['base_amount' => '1.0000']] as $change) {
            try {
                DB::transaction(fn () => DB::table('tax_transactions')->where('id', $row->id)->update($change));
                $this->fail('A posted tax transaction was changed: '.json_encode($change));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            DB::transaction(fn () => DB::table('tax_transactions')->where('id', $row->id)->delete());
            $this->fail('A posted tax transaction was deleted.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    // ------------------------------------------------------------------------------------------------ expense

    public function test_a_directly_paid_expense_with_an_inclusive_tax_code(): void
    {
        $bank = $this->cashAccount($this->tenant);
        $category = $this->expenseCategory($this->tenant);
        $code = $this->taxCode(['calculation_method' => 'INCLUSIVE']);

        $posted = $this->postedExpense($this->paidExpenseBody($category->id, $bank->id, ['net_amount' => '222000', 'tax_code_id' => $code['id']]));

        $this->assertSame(['200000.0000', '22000.0000', '222000.0000', '222000.0000'], [$posted['net_amount'], $posted['tax_amount'], $posted['total_amount'], $posted['entered_amount']]);
        $this->assertSame([['1120', '0.0000', '222000.0000'], ['1150', '22000.0000', '0.0000'], ['6900', '200000.0000', '0.0000']], $this->sortedJournal($this->journalOf('expenses', $posted['id'])));
        $row = $this->taxRows('expense', $posted['id'])[0];
        $this->assertSame([0, 'POSTED', '200000.0000', '22000.0000'], [$row->line_number, $row->status, $row->base_amount, $row->tax_amount]);
    }

    public function test_a_payable_expense_passes_its_tax_to_the_payable_it_creates(): void
    {
        $vendor = $this->vendor($this->tenant);
        $category = $this->expenseCategory($this->tenant);
        $code = $this->taxCode();

        $posted = $this->postedExpense($this->payableExpenseBody($category->id, $vendor, ['tax_code_id' => $code['id'], 'tax_amount' => null]));

        $this->assertSame(['1000000.0000', '110000.0000', '1110000.0000'], [$posted['net_amount'], $posted['tax_amount'], $posted['total_amount']]);
        $payable = DB::table('ap_invoices')->where('source_type', 'expense')->where('source_id', $posted['id'])->first();
        $this->assertSame(['1110000.0000', '110000.0000'], [$payable->total_amount, $payable->tax_amount]);
        $this->assertSame([['1150', '110000.0000', '0.0000'], ['2110', '0.0000', '1110000.0000'], ['6900', '1000000.0000', '0.0000']], $this->sortedJournal($this->journalOf('expenses', $posted['id'])));
    }

    public function test_a_non_recoverable_expense_tax_is_folded_into_the_expense(): void
    {
        $bank = $this->cashAccount($this->tenant);
        $category = $this->expenseCategory($this->tenant);
        $code = $this->taxCode(['is_recoverable' => false]);

        $posted = $this->postedExpense($this->paidExpenseBody($category->id, $bank->id, ['net_amount' => '200000', 'tax_code_id' => $code['id']]));

        $this->assertSame([['1120', '0.0000', '222000.0000'], ['6900', '222000.0000', '0.0000']], $this->sortedJournal($this->journalOf('expenses', $posted['id'])));
    }

    // ------------------------------------------------------------------------------------------------ AR invoice

    public function test_an_output_tax_is_posted_to_the_tax_payable_account(): void
    {
        $customer = $this->customer($this->tenant);
        $code = $this->outputCode();

        $posted = $this->postedArInvoice($customer, ['lines' => [['description' => 'Jasa angkut', 'amount' => '1000000', 'tax_code_id' => $code['id']]]]);

        $this->assertSame(['1000000.0000', '110000.0000', '1110000.0000'], [$posted['subtotal_amount'], $posted['tax_amount'], $posted['total_amount']]);
        $this->assertSame([['1130', '1110000.0000', '0.0000'], ['2120', '0.0000', '110000.0000'], ['4100', '0.0000', '1000000.0000']],
            $this->sortedJournal($this->journalOf('ar_invoices', $posted['id'])));
        $row = $this->taxRows('ar_invoice', $posted['id'])[0];
        $this->assertSame(['OUTPUT', 'POSTED', 'customer', 'TAX_PAYABLE'], [$row->direction, $row->status, $row->counterparty_type, $row->account_role]);
        $this->assertSame('-110000.0000', $this->glBalance($this->tenant, '2120'));
    }

    public function test_an_input_code_cannot_tax_a_sales_invoice_and_an_output_code_cannot_tax_a_purchase(): void
    {
        $customer = $this->customer($this->tenant);
        $input = $this->taxCode();

        $this->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer, ['lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $input['id']]]]))
            ->assertStatus(422)->assertJsonPath('code', 'TAX_TYPE_MISMATCH');
    }

    public function test_an_inclusive_output_tax_and_a_reversal_that_takes_the_tax_back(): void
    {
        $customer = $this->customer($this->tenant);
        $code = $this->outputCode(['calculation_method' => 'INCLUSIVE']);

        $posted = $this->postedArInvoice($customer, ['lines' => [['description' => 'Jasa', 'amount' => '1110000', 'tax_code_id' => $code['id']]]]);
        $this->assertSame(['1000000.0000', '110000.0000', '1110000.0000'], [$posted['subtotal_amount'], $posted['tax_amount'], $posted['total_amount']]);

        $this->postJson(self::AR."/ar-invoices/{$posted['id']}/reverse", ['reason' => 'Salah tagih'])->assertOk();
        $this->assertSame('REVERSED', $this->taxRows('ar_invoice', $posted['id'])[0]->status);
        $this->assertSame('0.0000', $this->glBalance($this->tenant, '2120'));
    }
}
