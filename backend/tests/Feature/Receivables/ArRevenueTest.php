<?php

namespace Tests\Feature\Receivables;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\TestCase;

/** OA3 batch C (brief section 52): revenue is recognised by the Posting Engine, on the account the tenant mapped, only when the invoice is posted. */
class ArRevenueTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, ReceivablesFixtures;

    private const MAPPINGS = '/api/v1/app/accounting/account-mappings';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->receivablesTenant('alpha', ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false]);
        $this->signedIn($this->tenant);
    }

    private function credit(string $code): string
    {
        return number_format(-1 * (float) $this->glBalance($this->tenant, $code), 4, '.', '');
    }

    public function test_revenue_is_recognised_only_by_the_posting_of_the_invoice_through_the_engine(): void
    {
        $customer = $this->customer($this->tenant, 'C1');
        $draft = $this->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer))->assertCreated()->json('id');
        $this->postJson(self::AR."/ar-invoices/{$draft}/submit")->assertOk();
        $this->postJson(self::AR."/ar-invoices/{$draft}/approve")->assertOk();
        $this->assertSame('0.0000', $this->credit('4100'), 'an unposted invoice is not revenue');

        $posted = $this->postJson(self::AR."/ar-invoices/{$draft}/post")->assertOk()->json();
        $this->assertSame('1000000.0000', $this->credit('4100'));
        $journal = DB::table('journal_entries')->where('id', $posted['journal_entry_id'])->first();
        $this->assertSame(['ar_invoice', $draft, 'POSTED'], [$journal->source_type, $journal->source_id, $journal->status]);
        $snapshot = json_decode($journal->posting_snapshot, true);
        $this->assertSame('AR_INVOICE_RECOGNIZED', $snapshot['event_type'], 'the journal was produced by a posting rule, not by the controller');

        // Reversal reverses the accounting impact and leaves the original journal as it was.
        $this->postJson(self::AR."/ar-invoices/{$draft}/reverse", ['reason' => 'Faktur salah', 'posting_date' => '2026-03-20'])->assertOk();
        $this->assertSame('0.0000', $this->credit('4100'));
        $this->assertSame('POSTED', DB::table('journal_entries')->where('id', $posted['journal_entry_id'])->value('status'));
        $this->assertSame(1, DB::table('journal_entries')->where('reverses_journal_id', $posted['journal_entry_id'])->count());
    }

    public function test_the_revenue_account_comes_from_the_tenants_mapping_and_a_new_mapping_never_rewrites_posted_history(): void
    {
        $customer = $this->customer($this->tenant, 'C1');
        $first = $this->arInvoice($customer, '1000000');
        $this->assertSame('1000000.0000', $this->credit('4100'));

        $this->putJson(self::MAPPINGS, ['account_role' => 'REVENUE', 'account_id' => $this->account($this->tenant, '4200')->id])->assertOk();
        $second = $this->arInvoice($customer, '250000');

        $this->assertSame(['1000000.0000', '250000.0000'], [$this->credit('4100'), $this->credit('4200')], 'only the new invoice follows the new mapping');
        $accounts = fn (string $journal) => DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $journal)->where('l.credit', '>', 0)->pluck('a.code')->all();
        $this->assertSame(['4100'], $accounts($first['journal_entry_id']));
        $this->assertSame(['4200'], $accounts($second['journal_entry_id']));

        // The first invoice reverses on the account it was booked to, whatever the mapping says now.
        $this->postJson(self::AR."/ar-invoices/{$first['id']}/reverse", ['reason' => 'Koreksi', 'posting_date' => '2026-03-20'])->assertOk();
        $this->assertSame(['0.0000', '250000.0000'], [$this->credit('4100'), $this->credit('4200')]);
    }

    public function test_a_branch_mapping_wins_for_a_document_of_that_branch_and_the_default_serves_the_rest(): void
    {
        [$north] = $this->branches($this->tenant, 'N');
        $this->signedIn($this->tenant);
        $this->putJson(self::MAPPINGS, ['account_role' => 'REVENUE', 'account_id' => $this->account($this->tenant, '4200')->id, 'branch_id' => $north])->assertOk();
        $customer = $this->customer($this->tenant, 'C1');

        $this->arInvoice($customer, '100000', ['branch_id' => $north]);
        $this->arInvoice($customer, '300000');

        $this->assertSame(['300000.0000', '100000.0000'], [$this->credit('4100'), $this->credit('4200')]);
    }

    public function test_a_mapping_to_another_tenants_account_is_refused_and_posting_never_uses_one(): void
    {
        $other = $this->receivablesTenant('bravo');
        $foreign = $this->account($other, '4200');

        $response = $this->putJson(self::MAPPINGS, ['account_role' => 'REVENUE', 'account_id' => $foreign->id]);
        $this->assertContains($response->getStatusCode(), [404, 422], 'a foreign account cannot be mapped: '.$response->getContent());
        $this->assertSame(0, DB::table('account_mappings')->where('tenant_id', $this->tenant->id)->where('account_id', $foreign->id)->count());

        $customer = $this->customer($this->tenant, 'C1');
        $this->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer, ['lines' => [['description' => 'Jasa', 'amount' => '1000', 'account_id' => $foreign->id]]]))->assertStatus(422);
        $this->arInvoice($customer, '1000');
        $this->assertSame('0.0000', $this->glBalance($other, '4200'));
        $this->assertSame('1000.0000', $this->credit('4100'));
    }

    public function test_without_a_rule_in_force_the_invoice_stays_unposted_and_the_ledger_untouched(): void
    {
        $customer = $this->customer($this->tenant, 'C1');
        $id = $this->postJson(self::AR.'/ar-invoices', $this->arInvoiceBody($customer))->assertCreated()->json('id');
        $this->postJson(self::AR."/ar-invoices/{$id}/submit")->assertOk();
        $this->postJson(self::AR."/ar-invoices/{$id}/approve")->assertOk();
        $before = $this->glFigures($this->tenant);

        DB::table('posting_rules')->where('tenant_id', $this->tenant->id)->where('event_type', 'AR_INVOICE_RECOGNIZED')->update(['effective_from' => '2027-01-01']);
        $this->postJson(self::AR."/ar-invoices/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'POSTING_RULE_NOT_FOUND');
        $this->assertSame('APPROVED', DB::table('ar_invoices')->where('id', $id)->value('status'));
        $this->assertEquals($before, $this->glFigures($this->tenant));
        $this->assertNull(DB::table('ar_invoices')->where('id', $id)->value('document_number'), 'a refused posting consumed no number');
    }
}
