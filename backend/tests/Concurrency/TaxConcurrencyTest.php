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
use Tests\Support\TaxFixtures;

/**
 * OA4 release gate: tax configuration against concurrent postings. Workers are separate PHP processes hitting the real application. The
 * invariant after every race: whatever order the requests ran in, a posted document's tax is booked to exactly the account its frozen
 * snapshot names, with exactly the amount it names, and the books balance.
 */
class TaxConcurrencyTest extends ConcurrencyTestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, RaceHelpers, TaxFixtures;

    private Tenant $tenant;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->taxTenant();
        [$user] = $this->member($this->tenant);
        $this->token = $this->tenantToken($user, $this->tenant);
        $this->as($this->token);
    }

    private function http()
    {
        return $this->as($this->token);
    }

    private function assertBooksSound(): void
    {
        $unbalanced = DB::table('journal_entries as j')->join('journal_lines as l', 'l.journal_entry_id', '=', 'j.id')->where('j.tenant_id', $this->tenant->id)->where('j.status', 'POSTED')
            ->groupBy('j.id')->havingRaw('sum(l.debit) <> sum(l.credit)')->select('j.id')->get()->count();
        $this->assertSame(0, $unbalanced, 'every posted journal balances');
        $this->assertSame(0, DB::table('tax_transactions')->where('tenant_id', $this->tenant->id)->where('status', 'POSTED')->whereNull('journal_entry_id')->count(), 'a posted tax has its journal');
        $this->assertSame(0, DB::table('tax_transactions')->where('tenant_id', $this->tenant->id)->groupBy('source_type', 'source_id', 'line_number')->havingRaw('count(*) > 1')->select('source_id')->get()->count(), 'one tax row per document line');
    }

    public function test_a_posting_racing_a_mapping_change_books_the_tax_to_the_account_its_snapshot_names(): void
    {
        $rounds = ['post-first' => 0, 'change-first' => 0];
        $this->http();
        $vendor = $this->vendor($this->tenant);
        $alternative = $this->account($this->tenant, '1160')->id;

        for ($i = 0; $i < 6; $i++) {
            $code = $this->http()->postJson(self::TX.'/tax-codes', ['code' => "RACE{$i}", 'name' => 'PPN', 'tax_type' => 'INPUT_TAX', 'rate' => '11', 'effective_from' => '2026-01-01'])->assertCreated()->json();
            $id = $this->http()->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $code['id']]]]))->assertCreated()->json('id');
            $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
            $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();

            $results = (new RaceRunner)->start([
                $this->job('POST', self::AP."/ap-invoices/{$id}/post"),
                $this->job('PATCH', self::TX."/tax-codes/{$code['id']}", ['account_id' => $alternative]),
            ])->results();

            $this->assertNoServerErrors($results);
            $posting = $results[0];
            $row = $this->taxRows('ap_invoice', $id)[0];
            if ($posting['status'] === 200) {
                $this->assertSame('POSTED', $row->status);
                $journal = DB::table('ap_invoices')->where('id', $id)->value('journal_entry_id');
                $taxAccount = DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $journal)->where('l.debit', '110000.0000')->value('a.code');
                // the snapshot is what the posting used: the role (1150) if the change came later, or never the half-way state
                $this->assertSame($row->account_id === null ? '1150' : '1160', $taxAccount, 'the journal follows the snapshot');
                $row->account_id === null ? $rounds['post-first']++ : $rounds['change-first']++;
            } else {
                $this->assertSame(409, $posting['status']);
                $this->assertSame('TAX_CONFIGURATION_CHANGED', $posting['body']['code']);
                $this->assertSame('DRAFT', $row->status);
                $this->assertSame(0, DB::table('journal_entries')->where('id', DB::table('ap_invoices')->where('id', $id)->value('journal_entry_id'))->count());
                $rounds['change-first']++;
            }
            $this->assertBooksSound();
        }

        $this->assertSame(6, array_sum($rounds), json_encode($rounds));
    }

    public function test_the_same_taxed_invoice_posted_by_several_requests_is_taxed_once(): void
    {
        $this->http();
        $vendor = $this->vendor($this->tenant);
        $code = $this->http()->postJson(self::TX.'/tax-codes', ['code' => 'PPN', 'name' => 'PPN', 'tax_type' => 'INPUT_TAX', 'rate' => '11', 'effective_from' => '2026-01-01'])->assertCreated()->json();
        $id = $this->http()->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $code['id']]]]))->assertCreated()->json('id');
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();

        $results = (new RaceRunner)->start(array_fill(0, 5, $this->job('POST', self::AP."/ap-invoices/{$id}/post")))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['200:'] ?? 0, json_encode($outcomes));
        $this->assertSame('110000.0000', $this->glBalance($this->tenant, '1150'));
        $this->assertCount(1, $this->taxRows('ap_invoice', $id));
        $this->assertSame('POSTED', $this->taxRows('ap_invoice', $id)[0]->status);
        $this->assertBooksSound();
    }

    public function test_concurrent_rates_from_the_same_date_leave_exactly_one_and_a_clean_timeline(): void
    {
        $code = $this->http()->postJson(self::TX.'/tax-codes', ['code' => 'PPN', 'name' => 'PPN', 'tax_type' => 'INPUT_TAX', 'rate' => '10', 'effective_from' => '2026-01-01'])->assertCreated()->json();

        $results = (new RaceRunner)->start(array_map(
            fn ($rate) => $this->job('POST', self::TX."/tax-codes/{$code['id']}/rates", ['rate' => (string) $rate, 'effective_from' => '2026-04-01']), [11, 12, 13, 14],
        ))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['201:PPN'] ?? 0, json_encode($outcomes));
        $this->assertSame(3, $outcomes['409:TAX_RATE_OVERLAP'] ?? 0, json_encode($outcomes));
        $rates = DB::table('tax_rates')->where('tax_code_id', $code['id'])->orderBy('effective_from')->get();
        $this->assertCount(2, $rates);
        $this->assertSame('2026-03-31', $rates[0]->effective_until);
        $this->assertNull($rates[1]->effective_until);
    }

    public function test_concurrent_creation_of_the_same_code_creates_one(): void
    {
        $this->http();
        $body = ['code' => 'PPN', 'name' => 'PPN', 'tax_type' => 'INPUT_TAX', 'rate' => '11', 'effective_from' => '2026-01-01'];

        $results = (new RaceRunner)->start(array_fill(0, 4, $this->job('POST', self::TX.'/tax-codes', $body)))->results();

        $this->assertNoServerErrors($results);
        $outcomes = $this->outcomes($results);
        $this->assertSame(1, $outcomes['201:PPN'] ?? 0, json_encode($outcomes));
        $this->assertSame(3, $outcomes['409:TAX_CODE_TAKEN'] ?? 0, json_encode($outcomes));
        $this->assertSame(1, DB::table('tax_codes')->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_a_rate_change_racing_the_first_use_of_a_code_never_rewrites_a_posted_date(): void
    {
        $this->http();
        $vendor = $this->vendor($this->tenant);
        $code = $this->http()->postJson(self::TX.'/tax-codes', ['code' => 'PPN', 'name' => 'PPN', 'tax_type' => 'INPUT_TAX', 'rate' => '11', 'effective_from' => '2026-01-01'])->assertCreated()->json();
        $id = $this->http()->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['lines' => [['description' => 'Jasa', 'amount' => '1000000', 'tax_code_id' => $code['id']]]]))->assertCreated()->json('id');
        $this->postJson(self::AP."/ap-invoices/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/ap-invoices/{$id}/approve")->assertOk();

        // a rate that would start on the invoice's own date (2026-03-10) races its posting
        $results = (new RaceRunner)->start([
            $this->job('POST', self::AP."/ap-invoices/{$id}/post"),
            $this->job('POST', self::TX."/tax-codes/{$code['id']}/rates", ['rate' => '12', 'effective_from' => '2026-03-10']),
        ])->results();

        $this->assertNoServerErrors($results);
        $row = $this->taxRows('ap_invoice', $id)[0];
        $rates = DB::table('tax_rates')->where('tax_code_id', $code['id'])->orderBy('effective_from')->get();
        if ($results[0]['status'] === 200) {
            // posted at 11%: the new rate may not start on or before the date it used
            $this->assertSame(['POSTED', '110000.0000'], [$row->status, $row->tax_amount]);
            $this->assertSame(409, $results[1]['status']);
            $this->assertSame('TAX_RATE_RETROACTIVE_CONFLICT', $results[1]['body']['code']);
            $this->assertCount(1, $rates);
        } else {
            // the new rate won: the draft must be recalculated, never posted at 11%
            $this->assertSame(201, $results[1]['status']);
            $this->assertSame('TAX_CONFIGURATION_CHANGED', $results[0]['body']['code']);
            $this->assertSame('DRAFT', $row->status);
        }
        $this->assertBooksSound();
    }
}
