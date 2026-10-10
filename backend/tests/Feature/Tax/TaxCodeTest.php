<?php

namespace Tests\Feature\Tax;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/** OA4: tax codes, their effective-dated rates and the account they post to. Nothing about a rate or an account number is hardcoded. */
class TaxCodeTest extends TestCase
{
    use AccountingFixtures, Fixtures, PayablesFixtures, TaxFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->taxTenant();
        $this->signedIn($this->tenant);
    }

    public function test_a_code_is_created_with_its_first_rate_and_the_default_account_role_of_its_type(): void
    {
        $code = $this->taxCode(['code' => 'ppn-in', 'rate' => '11']);

        $this->assertSame(['PPN-IN', 'INPUT_TAX', 'EXCLUSIVE', 'STANDARD', true, 'TAX_RECEIVABLE', null, 'ACTIVE', '11.000000'],
            [$code['code'], $code['tax_type'], $code['calculation_method'], $code['treatment'], $code['is_recoverable'], $code['account_role'], $code['account_id'], $code['status'], $code['current_rate']]);
        $out = $this->outputCode(['code' => 'PPN-OUT']);
        $this->assertSame('TAX_PAYABLE', $out['account_role']);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'tax.code.created')->where('resource_id', $code['id'])->count());
    }

    public function test_an_explicit_account_replaces_the_role_and_is_validated_by_type(): void
    {
        $account = $this->account($this->tenant, '1150');
        $code = $this->taxCode(['account_id' => $account->id]);
        $this->assertSame([$account->id, null], [$code['account_id'], $code['account_role']]);

        $this->postJson(self::TX.'/tax-codes', ['code' => 'BAD', 'name' => 'Salah', 'tax_type' => 'OUTPUT_TAX', 'rate' => '11', 'effective_from' => '2026-01-01', 'account_id' => $this->account($this->tenant, '6100')->id])
            ->assertStatus(422);
    }

    public function test_a_code_and_its_values_are_validated(): void
    {
        $base = ['code' => 'X1', 'name' => 'X', 'tax_type' => 'INPUT_TAX', 'rate' => '11', 'effective_from' => '2026-01-01'];

        $this->postJson(self::TX.'/tax-codes', ['code' => 'a b'] + $base)->assertStatus(422)->assertJsonPath('code', 'TAX_CODE_INVALID');
        $this->postJson(self::TX.'/tax-codes', ['rate' => '101'] + $base)->assertStatus(422)->assertJsonPath('code', 'TAX_RATE_INVALID');
        $this->postJson(self::TX.'/tax-codes', ['rate' => '-1'] + $base)->assertStatus(422)->assertJsonPath('code', 'TAX_RATE_INVALID');
        $this->postJson(self::TX.'/tax-codes', ['rate' => '1.1234567'] + $base)->assertStatus(422)->assertJsonPath('code', 'TAX_RATE_INVALID');
        $this->postJson(self::TX.'/tax-codes', ['rate' => 'abc'] + $base)->assertStatus(422)->assertJsonPath('code', 'TAX_RATE_INVALID');
        $this->postJson(self::TX.'/tax-codes', ['treatment' => 'EXEMPT'] + $base)->assertStatus(422)->assertJsonPath('code', 'TAX_RATE_INVALID'); // exempt means rate zero
        $this->postJson(self::TX.'/tax-codes', ['tax_type' => 'OUTPUT_TAX', 'is_recoverable' => false] + $base)->assertStatus(422)->assertJsonPath('code', 'TAX_RECOVERABLE_INVALID');
        $this->postJson(self::TX.'/tax-codes', ['tax_type' => 'OTHER'] + $base)->assertStatus(422)->assertJsonPath('code', 'TAX_ACCOUNT_REQUIRED');
        $this->postJson(self::TX.'/tax-codes', ['tax_type' => 'NOPE'] + $base)->assertStatus(422);

        $this->postJson(self::TX.'/tax-codes', $base)->assertCreated();
        $this->postJson(self::TX.'/tax-codes', $base)->assertStatus(409)->assertJsonPath('code', 'TAX_CODE_TAKEN');
    }

    public function test_the_rate_timeline_only_moves_forward_and_closes_the_previous_rate_the_day_before(): void
    {
        $code = $this->taxCode(['rate' => '10', 'effective_from' => '2026-01-01']);

        $after = $this->postJson(self::TX."/tax-codes/{$code['id']}/rates", ['rate' => '11', 'effective_from' => '2026-04-01'])->assertCreated()->json();

        $rates = collect($after['rates'])->sortBy('effective_from')->values();
        $this->assertSame(['10.000000', '2026-01-01', '2026-03-31'], [$rates[0]['rate'], substr($rates[0]['effective_from'], 0, 10), substr($rates[0]['effective_until'], 0, 10)]);
        $this->assertSame(['11.000000', '2026-04-01', null], [$rates[1]['rate'], substr($rates[1]['effective_from'], 0, 10), $rates[1]['effective_until']]);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'tax.rate.added')->where('resource_id', $code['id'])->count());

        $this->postJson(self::TX."/tax-codes/{$code['id']}/rates", ['rate' => '12', 'effective_from' => '2026-04-01'])->assertStatus(409)->assertJsonPath('code', 'TAX_RATE_OVERLAP');
        $this->postJson(self::TX."/tax-codes/{$code['id']}/rates", ['rate' => '12', 'effective_from' => '2026-02-01'])->assertStatus(409)->assertJsonPath('code', 'TAX_RATE_OVERLAP');
    }

    public function test_the_database_refuses_overlapping_rates_and_a_rate_that_is_edited_in_place(): void
    {
        $code = $this->taxCode();
        $rate = DB::table('tax_rates')->where('tax_code_id', $code['id'])->first();

        $this->assertDbRefuses(fn () => DB::table('tax_rates')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'tax_code_id' => $code['id'], 'rate' => '5', 'effective_from' => '2026-06-01',
            'effective_until' => null, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertDbRefuses(fn () => DB::table('tax_rates')->where('id', $rate->id)->update(['rate' => '12']));
        $this->assertDbRefuses(fn () => DB::table('tax_rates')->where('id', $rate->id)->update(['effective_from' => '2025-01-01']));
    }

    public function test_a_used_code_keeps_its_structure_is_deactivated_not_deleted_and_an_unused_one_can_go(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode();
        $unused = $this->taxCode();

        $this->postedTaxedInvoice($vendor, $code['id']);

        $this->patchJson(self::TX."/tax-codes/{$code['id']}", ['calculation_method' => 'INCLUSIVE'])->assertStatus(409)->assertJsonPath('code', 'TAX_CODE_IN_USE');
        $this->patchJson(self::TX."/tax-codes/{$code['id']}", ['is_recoverable' => false])->assertStatus(409)->assertJsonPath('code', 'TAX_CODE_IN_USE');
        $this->patchJson(self::TX."/tax-codes/{$code['id']}", ['name' => 'PPN masukan baru'])->assertOk()->assertJsonPath('name', 'PPN masukan baru');
        $this->deleteJson(self::TX."/tax-codes/{$code['id']}")->assertStatus(409)->assertJsonPath('code', 'TAX_CODE_IN_USE');
        $this->postJson(self::TX."/tax-codes/{$code['id']}/deactivate")->assertOk()->assertJsonPath('status', 'INACTIVE');
        $this->assertTrue($this->getJson(self::TX."/tax-codes/{$code['id']}")->assertOk()->json('in_use'));

        $this->deleteJson(self::TX."/tax-codes/{$unused['id']}")->assertNoContent();
        $this->assertSame(0, DB::table('tax_rates')->where('tax_code_id', $unused['id'])->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'tax.code.deleted')->count());
    }

    public function test_a_rate_cannot_be_added_back_to_a_date_a_posted_transaction_already_used(): void
    {
        $vendor = $this->vendor($this->tenant);
        $code = $this->taxCode(['effective_from' => '2026-01-01']);
        $this->postedTaxedInvoice($vendor, $code['id'], '1000000', ['document_date' => '2026-03-10', 'posting_date' => '2026-03-10']);

        // 2026-03-10 is already taxed at the first rate, so a rate from 2026-03-05 would rewrite history; a rate from after it is fine.
        $this->assertDbRefuses(fn () => DB::table('tax_rates')->where('tax_code_id', $code['id'])->update(['effective_until' => '2026-03-01']));
        $this->postJson(self::TX."/tax-codes/{$code['id']}/rates", ['rate' => '12', 'effective_from' => '2026-03-11'])->assertCreated();
    }

    public function test_the_preview_uses_the_rate_in_force_on_the_date(): void
    {
        $code = $this->taxCode(['rate' => '10', 'effective_from' => '2026-01-01', 'calculation_method' => 'INCLUSIVE']);
        $this->postJson(self::TX."/tax-codes/{$code['id']}/rates", ['rate' => '11', 'effective_from' => '2026-04-01'])->assertCreated();

        $this->postJson(self::TX."/tax-codes/{$code['id']}/preview", ['amount' => '1110000', 'date' => '2026-05-01'])->assertOk()
            ->assertJsonPath('rate', '11.000000')->assertJsonPath('base_amount', '1000000.0000')->assertJsonPath('tax_amount', '110000.0000')->assertJsonPath('total_amount', '1110000.0000');
        $this->postJson(self::TX."/tax-codes/{$code['id']}/preview", ['amount' => '1100000', 'date' => '2026-02-01'])->assertOk()
            ->assertJsonPath('rate', '10.000000')->assertJsonPath('tax_amount', '100000.0000');
        $this->postJson(self::TX."/tax-codes/{$code['id']}/preview", ['amount' => '1000', 'date' => '2025-12-31'])->assertStatus(422)->assertJsonPath('code', 'TAX_RATE_NOT_FOUND');
    }

    public function test_codes_are_isolated_per_tenant(): void
    {
        $code = $this->taxCode();
        $bravo = $this->taxTenant('bravo');
        $this->signedIn($bravo);

        $this->getJson(self::TX."/tax-codes/{$code['id']}")->assertNotFound();
        $this->patchJson(self::TX."/tax-codes/{$code['id']}", ['name' => 'x'])->assertNotFound();
        $this->postJson(self::TX."/tax-codes/{$code['id']}/rates", ['rate' => '1', 'effective_from' => '2026-06-01'])->assertNotFound();
        $this->assertSame(0, $this->getJson(self::TX.'/tax-codes')->assertOk()->json('total'));

        $this->taxCode(['code' => $code['code']]); // the same code is free in another tenant
    }

    private function assertDbRefuses(callable $statement): void
    {
        try {
            DB::transaction($statement);
            $this->fail('The database accepted a change it must refuse.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}
