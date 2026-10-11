<?php

namespace Tests\Feature\Currency;

use App\Domain\Currency\Services\CurrencyService;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\Support\FxFixtures;
use Tests\Support\PayablesFixtures;
use Tests\Support\ReceivablesFixtures;
use Tests\Support\TaxFixtures;
use Tests\TestCase;

/** OA4: the currency and exchange rate masters: validation, immutability of a rate, withdrawal, tenant isolation, the functional currency lock and the audit trail. */
class CurrencyAndRateTest extends TestCase
{
    use AccountingFixtures, Fixtures, FxFixtures, PayablesFixtures, ReceivablesFixtures, TaxFixtures;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->fxTenant();
        $this->signedIn($this->tenant);
    }

    /** The database refuses the change with the given SQLSTATE (23514 check/trigger, 23503 foreign key); the attempt runs in a savepoint so the test can go on. */
    private function refuses(callable $attempt, string $state, string $what): void
    {
        try {
            DB::transaction($attempt);
            $this->fail("{$what} was accepted");
        } catch (QueryException $e) {
            $this->assertSame($state, $e->errorInfo[0], "{$what}: ".$e->getMessage());
        }
    }

    private function usdId(): string
    {
        return (string) DB::table('currencies')->where('tenant_id', $this->tenant->id)->where('code', 'USD')->value('id');
    }

    // ------------------------------------------------------------------------------------------------ currencies

    public function test_a_currency_is_added_edited_deactivated_and_deleted_while_unused(): void
    {
        $eur = $this->postJson(self::FX.'/currencies', ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'decimal_places' => 2])->assertCreated()->assertJsonPath('status', 'ACTIVE')->json();
        $this->assertSame(['EUR', 2, false], [$eur['code'], $eur['decimal_places'], $eur['in_use']]);

        $this->patchJson(self::FX."/currencies/{$eur['id']}", ['name' => 'Euro (EUR)', 'decimal_places' => 3])->assertOk()->assertJsonPath('decimal_places', 3);
        $this->postJson(self::FX."/currencies/{$eur['id']}/deactivate")->assertOk()->assertJsonPath('status', 'INACTIVE');
        $this->postJson(self::FX."/currencies/{$eur['id']}/activate")->assertOk()->assertJsonPath('status', 'ACTIVE');
        $this->deleteJson(self::FX."/currencies/{$eur['id']}")->assertNoContent();
        $this->getJson(self::FX."/currencies/{$eur['id']}")->assertNotFound();
    }

    public function test_a_currency_code_is_validated_and_unique_and_the_functional_currency_is_not_a_row(): void
    {
        $this->postJson(self::FX.'/currencies', ['code' => 'USD', 'name' => 'Duplicate'])->assertStatus(409)->assertJsonPath('code', 'CURRENCY_TAKEN');
        $this->postJson(self::FX.'/currencies', ['code' => 'IDR', 'name' => 'Rupiah'])->assertStatus(422)->assertJsonPath('code', 'CURRENCY_IS_FUNCTIONAL');
        $this->postJson(self::FX.'/currencies', ['code' => 'U$D', 'name' => 'Bad'])->assertStatus(422)->assertJsonPath('code', 'CURRENCY_CODE_INVALID');
        $this->postJson(self::FX.'/currencies', ['code' => 'JPY', 'name' => 'Yen', 'decimal_places' => 5])->assertStatus(422)->assertJsonPath('code', 'CURRENCY_PRECISION_INVALID');
        $this->postJson(self::FX.'/currencies', ['code' => 'JPY', 'name' => 'Yen', 'decimal_places' => 'x'])->assertStatus(422)->assertJsonPath('code', 'CURRENCY_PRECISION_INVALID');
        $this->postJson(self::FX.'/currencies', ['code' => 'JPY'])->assertStatus(422);
        $this->postJson(self::FX.'/currencies', ['code' => 'jpy', 'name' => 'Yen', 'decimal_places' => 0])->assertCreated()->assertJsonPath('code', 'JPY');
        $this->patchJson(self::FX.'/currencies/'.$this->usdId(), ['code' => 'XXX'])->assertStatus(422); // the code is identity
    }

    public function test_a_currency_that_rates_or_documents_use_keeps_its_precision_and_is_never_deleted(): void
    {
        $this->rate('USD', '15500', '2026-03-01');

        $this->patchJson(self::FX.'/currencies/'.$this->usdId(), ['decimal_places' => 3])->assertStatus(409)->assertJsonPath('code', 'CURRENCY_IN_USE');
        $this->patchJson(self::FX.'/currencies/'.$this->usdId(), ['name' => 'Dolar AS'])->assertOk();
        $this->deleteJson(self::FX.'/currencies/'.$this->usdId())->assertStatus(409)->assertJsonPath('code', 'CURRENCY_IN_USE');
        $this->getJson(self::FX.'/currencies/'.$this->usdId())->assertOk()->assertJsonPath('in_use', true);
    }

    public function test_the_functional_currency_cannot_change_underneath_the_currencies_and_rates(): void
    {
        $this->putJson(self::FX.'/profile', ['functional_currency' => 'USD'])->assertStatus(409)->assertJsonPath('code', 'FUNCTIONAL_CURRENCY_HAS_RATES');
    }

    public function test_the_functional_currency_is_frozen_after_the_first_posting(): void
    {
        $plain = $this->accountingTenant('plain');
        $this->signedIn($plain);
        $this->postedJournal($plain);

        $this->putJson(self::FX.'/profile', ['functional_currency' => 'USD'])->assertStatus(409)->assertJsonPath('code', 'ACCOUNTING_PROFILE_LOCKED');
        $this->putJson(self::FX.'/profile', ['currency_scale' => 0])->assertStatus(409)->assertJsonPath('code', 'ACCOUNTING_PROFILE_LOCKED');
    }

    // ------------------------------------------------------------------------------------------------ rates

    public function test_a_rate_is_entered_as_a_fact_in_the_functional_currency(): void
    {
        $rate = $this->postJson(self::FX.'/exchange-rates', ['from_currency' => 'USD', 'rate' => '15523.4567891234', 'effective_date' => '2026-03-02', 'source' => 'BI', 'notes' => 'kurs tengah'])
            ->assertCreated()->json();

        $this->assertSame(['USD', 'IDR', '15523.4567891234', '2026-03-02', 'MANUAL', 'ACTIVE', false], [$rate['from_currency'], $rate['to_currency'], (string) $rate['rate'], substr($rate['effective_date'], 0, 10), $rate['rate_type'], $rate['status'], $rate['in_use']]);
        $this->assertSame(1, DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('action', 'exchange_rate.created')->count());
    }

    public function test_a_rate_must_be_a_positive_decimal_of_a_known_active_currency_and_a_real_date(): void
    {
        $post = fn (array $override) => $this->postJson(self::FX.'/exchange-rates', $override + ['from_currency' => 'USD', 'rate' => '15500', 'effective_date' => '2026-03-01']);

        foreach (['0', '-5', 'abc', '1.12345678901', '1e3', '1000000000', ' '] as $bad) {
            $post(['rate' => $bad])->assertStatus(422)->assertJsonPath('code', 'EXCHANGE_RATE_INVALID');
        }
        $post(['rate' => 15500.5])->assertStatus(422)->assertJsonPath('code', 'EXCHANGE_RATE_INVALID'); // a float is never money
        $post(['from_currency' => 'EUR'])->assertStatus(422)->assertJsonPath('code', 'CURRENCY_NOT_FOUND');
        $post(['from_currency' => 'IDR'])->assertStatus(422)->assertJsonPath('code', 'CURRENCY_NOT_FOUND'); // the functional currency has no rate to itself
        $post(['effective_date' => '2026-02-30'])->assertStatus(422);
        $post(['rate_type' => 'WEEKLY'])->assertStatus(422);
        $this->assertSame(0, DB::table('exchange_rates')->where('tenant_id', $this->tenant->id)->count());

        $this->postJson(self::FX.'/currencies/'.$this->usdId().'/deactivate')->assertOk();
        $post([])->assertStatus(422)->assertJsonPath('code', 'CURRENCY_INACTIVE');
    }

    public function test_only_one_live_rate_per_currency_date_and_type_and_a_correction_withdraws_the_old_one(): void
    {
        $first = $this->rate('USD', '15500', '2026-03-01');
        $this->postJson(self::FX.'/exchange-rates', ['from_currency' => 'USD', 'rate' => '15600', 'effective_date' => '2026-03-01'])->assertStatus(409)->assertJsonPath('code', 'EXCHANGE_RATE_DUPLICATE');
        $this->rate('USD', '15450', '2026-03-01', 'SPOT'); // another type on the same date is a different rate

        $this->postJson(self::FX."/exchange-rates/{$first['id']}/deactivate")->assertOk()->assertJsonPath('status', 'INACTIVE');
        $second = $this->rate('USD', '15600', '2026-03-01');
        $this->postJson(self::FX."/exchange-rates/{$first['id']}/activate")->assertStatus(409)->assertJsonPath('code', 'EXCHANGE_RATE_DUPLICATE');

        $this->assertSame($second['id'], $this->getJson(self::FX.'/exchange-rates/lookup?currency=USD&date=2026-03-01')->assertOk()->json('rate_id'));
    }

    public function test_a_rate_value_date_and_type_can_never_be_edited_only_its_notes(): void
    {
        $rate = $this->rate('USD', '15500', '2026-03-01');

        foreach (['rate' => '15600', 'effective_date' => '2026-03-02', 'rate_type' => 'SPOT', 'from_currency' => 'EUR'] as $field => $value) {
            $this->patchJson(self::FX."/exchange-rates/{$rate['id']}", [$field => $value])->assertStatus(409)->assertJsonPath('code', 'EXCHANGE_RATE_IMMUTABLE');
        }
        $this->patchJson(self::FX."/exchange-rates/{$rate['id']}", ['notes' => 'dikoreksi', 'source' => 'BI'])->assertOk()->assertJsonPath('notes', 'dikoreksi');

        // the database repeats it
        $this->refuses(fn () => DB::table('exchange_rates')->where('id', $rate['id'])->update(['rate' => '1']), '23514', 'editing the value of a rate');
        $this->refuses(fn () => DB::table('exchange_rates')->where('id', $rate['id'])->update(['effective_date' => '2026-03-02']), '23514', 'editing the date of a rate');
        $this->refuses(fn () => DB::table('currencies')->where('id', $this->usdId())->update(['code' => 'EUR']), '23514', 'renaming the code of a currency');
    }

    public function test_a_rate_that_a_document_cites_is_withdrawn_not_deleted(): void
    {
        $unused = $this->rate('USD', '15400', '2026-02-01');
        $used = $this->rate('USD', '15500', '2026-03-01');
        $vendor = $this->vendor($this->tenant);
        $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['currency' => 'USD']))->assertCreated(); // a draft already cites the rate

        $this->deleteJson(self::FX."/exchange-rates/{$used['id']}")->assertStatus(409)->assertJsonPath('code', 'EXCHANGE_RATE_IN_USE');
        $this->getJson(self::FX."/exchange-rates/{$used['id']}")->assertOk()->assertJsonPath('in_use', true);
        $listed = collect($this->getJson(self::FX.'/exchange-rates')->assertOk()->json('data'))->pluck('in_use', 'id');
        $this->assertSame([true, false], [$listed[$used['id']], $listed[$unused['id']]], 'the list tells which rates a document cites');
        $this->deleteJson(self::FX."/exchange-rates/{$unused['id']}")->assertNoContent();
        $this->assertSame(1, DB::table('audit_logs')->where('tenant_id', $this->tenant->id)->where('action', 'exchange_rate.deleted')->count());
    }

    public function test_the_lookup_gives_the_rate_a_document_would_use_or_the_reason_there_is_none(): void
    {
        $this->rate('USD', '15000', '2026-03-01');
        $this->rate('USD', '15400', '2026-03-08', 'SPOT');
        $lookup = fn (string $query) => $this->getJson(self::FX.'/exchange-rates/lookup?'.$query);

        $lookup('currency=USD&date=2026-03-10')->assertOk()->assertJsonPath('rate', '15400.0000000000')->assertJsonPath('rate_type', 'SPOT');
        $lookup('currency=USD&date=2026-03-10&rate_type=MANUAL')->assertOk()->assertJsonPath('rate', '15000.0000000000');
        $lookup('currency=USD&date=2026-02-27')->assertStatus(422)->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');
        $lookup('currency=USD&date=2026-05-01')->assertStatus(422)->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND'); // older than the allowed age
        $lookup('currency=IDR&date=2026-03-10')->assertOk()->assertJsonPath('rate', '1.0000000000'); // the functional currency is always 1
        $lookup('currency=USD')->assertStatus(422);
    }

    // ------------------------------------------------------------------------------------------------ isolation, rules

    public function test_currencies_and_rates_of_another_tenant_are_invisible_and_unusable(): void
    {
        $bravo = $this->fxTenant('bravo');
        $this->as($this->memberToken($bravo));
        $other = $this->rate('USD', '16000', '2026-03-01');
        $this->postJson(self::FX.'/currencies', ['code' => 'GBP', 'name' => 'Pound'])->assertCreated();

        $this->as($this->memberToken($this->tenant));
        $this->assertSame(0, $this->getJson(self::FX.'/exchange-rates')->assertOk()->json('total'));
        $this->getJson(self::FX."/exchange-rates/{$other['id']}")->assertNotFound();
        $this->postJson(self::FX."/exchange-rates/{$other['id']}/deactivate")->assertNotFound();
        $this->deleteJson(self::FX."/exchange-rates/{$other['id']}")->assertNotFound();
        $this->assertSame(['USD'], array_column($this->getJson(self::FX.'/currencies')->assertOk()->json('data'), 'code'));

        // a document cannot use the other tenant's currency or rate: it simply does not exist here
        $vendor = $this->vendor($this->tenant);
        $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['currency' => 'GBP']))->assertStatus(422)->assertJsonPath('code', 'CURRENCY_NOT_FOUND');
        $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['currency' => 'USD']))->assertStatus(422)->assertJsonPath('code', 'EXCHANGE_RATE_NOT_FOUND');

        // and the database refuses a document, a rate or a payment that points into another tenant
        $this->rate('USD', '15500', '2026-03-01');
        $draft = $this->postJson(self::AP.'/ap-invoices', $this->invoiceBody($vendor, ['currency' => 'USD']))->assertCreated()->json('id');
        $this->refuses(fn () => DB::table('ap_invoices')->where('id', $draft)->update(['exchange_rate_id' => $other['id']]), '23503', 'a document citing the rate of another tenant');
        $this->refuses(fn () => DB::table('exchange_rates')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'from_currency' => 'GBP', 'to_currency' => 'IDR', 'rate' => '1', 'effective_date' => '2026-03-01',
            'rate_type' => 'MANUAL', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]), '23503', 'a rate for a currency the tenant does not have');
    }

    public function test_the_foreign_settlement_rules_are_applied_once_and_a_tenant_without_them_cannot_post_a_foreign_payment(): void
    {
        $status = $this->getJson(self::FX.'/fx-rules')->assertOk()->json();
        $this->assertSame([true, true], array_column($status['events'], 'ready'));
        $this->assertSame([], $status['unmapped_roles']);
        $again = $this->postJson(self::FX.'/fx-rules/defaults')->assertCreated()->json();
        $this->assertSame([[], ['ALREADY_PUBLISHED', 'ALREADY_PUBLISHED']], [$again['created'], array_column($again['skipped'], 'reason')]);

        // a tenant that never applied them: the payment is refused and nothing is posted
        $bare = $this->taxTenant('bare');
        $this->inTenant($bare, fn () => app(CurrencyService::class)->create(['code' => 'USD', 'name' => 'US Dollar'], $this->adminOf($bare)));
        $this->signedIn($bare);
        $this->rate('USD', '15500', '2026-03-01');
        $vendor = $this->vendor($bare);
        $bank = $this->cashAccount($bare, 'BCA');
        $invoice = $this->usdInvoice($vendor, '1000.00');
        $id = $this->postJson(self::AP.'/vendor-payments', $this->paymentBody($vendor, $bank->id, [$invoice['id'] => '1000.00'], ['currency' => 'USD']))->assertCreated()->json('id');
        $this->postJson(self::AP."/vendor-payments/{$id}/submit")->assertOk();
        $this->postJson(self::AP."/vendor-payments/{$id}/approve")->assertOk();
        $before = $this->glFigures($bare);

        $this->postJson(self::AP."/vendor-payments/{$id}/post")->assertStatus(422)->assertJsonPath('code', 'POSTING_RULE_NOT_FOUND');
        $this->assertEquals($before, $this->glFigures($bare));
        $this->assertSame('1000.0000', $this->getJson(self::AP."/ap-invoices/{$invoice['id']}")->assertOk()->json('outstanding_amount'));
    }
}
