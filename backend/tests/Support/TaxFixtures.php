<?php

namespace Tests\Support;

use App\Domain\Identity\Models\Tenant;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Test helpers for OA4 tax; combine with AccountingFixtures, Fixtures, PayablesFixtures and ReceivablesFixtures. */
trait TaxFixtures
{
    protected const TX = '/api/v1/app/accounting';

    /** A tenant with the operational posting rules and no segregation-of-duties limit, so one signed-in user can take a document through every step. */
    protected function taxTenant(string $code = 'alpha', array $profile = []): Tenant
    {
        return $this->payablesTenant($code, $profile + ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false, 'sod_approver_not_poster' => false]);
    }

    /** @return array<string,mixed> a tax code created through the API as the signed-in user (input PPN 11% exclusive unless told otherwise) */
    protected function taxCode(array $override = []): array
    {
        return $this->postJson(self::TX.'/tax-codes', $override + [
            'code' => 'PPN'.substr(uniqid(), -5), 'name' => 'PPN Masukan 11%', 'tax_type' => 'INPUT_TAX', 'rate' => '11', 'effective_from' => '2026-01-01',
        ])->assertCreated()->json();
    }

    /** @return array<string,mixed> an output tax code (PPN keluaran 11%) */
    protected function outputCode(array $override = []): array
    {
        return $this->taxCode($override + ['name' => 'PPN Keluaran 11%', 'tax_type' => 'OUTPUT_TAX']);
    }

    /** Create, submit, approve and post a vendor invoice whose single line carries a tax code. @return array<string,mixed> */
    protected function postedTaxedInvoice($vendor, string $taxCodeId, string $amount = '1000000', array $override = []): array
    {
        return $this->postedInvoice($vendor, $override + ['lines' => [['description' => 'Jasa kena pajak', 'amount' => $amount, 'tax_code_id' => $taxCodeId]]]);
    }

    /** The tax transactions of a document, ordered by line. @return list<object> */
    protected function taxRows(string $sourceType, string $sourceId): array
    {
        return DB::table('tax_transactions')->where('source_type', $sourceType)->where('source_id', $sourceId)->orderBy('line_number')->get()->all();
    }

    /** @return list<array{string,string,string}> a journal as [account code, debit, credit], one row per account (lines of the same account added up), sorted by account */
    protected function sortedJournal(string $journalId): array
    {
        $by = [];
        foreach (DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $journalId)->get(['a.code', 'l.debit', 'l.credit']) as $r) {
            $by[$r->code] ??= [BigDecimal::zero(), BigDecimal::zero()];
            $by[$r->code][0] = $by[$r->code][0]->plus($r->debit);
            $by[$r->code][1] = $by[$r->code][1]->plus($r->credit);
        }
        ksort($by);

        return array_map(fn ($code, $v) => [(string) $code, (string) $v[0]->toScale(4), (string) $v[1]->toScale(4)], array_keys($by), array_values($by));
    }
}
