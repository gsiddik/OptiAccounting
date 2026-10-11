<?php

namespace Tests\Support;

use App\Domain\Currency\Services\CurrencyService;
use App\Domain\Currency\Services\FxSetupService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Test helpers for OA4 multi-currency; combine with AccountingFixtures, Fixtures, PayablesFixtures, ReceivablesFixtures and TaxFixtures. */
trait FxFixtures
{
    protected const FX = '/api/v1/app/accounting';

    /** A tax-ready tenant (functional IDR, scale 2, no segregation limit) with the foreign settlement rules published and USD set up (2 places). */
    protected function fxTenant(string $code = 'alpha', array $profile = []): Tenant
    {
        $tenant = $this->taxTenant($code, $profile);
        $this->inTenant($tenant, function () use ($tenant) {
            app(FxSetupService::class)->applyDefaults('2026-01-01');
            app(CurrencyService::class)->create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'decimal_places' => 2], $this->adminOf($tenant));
        });

        return $tenant;
    }

    /** A user holding every permission of the tenant, for services that need an acting user. */
    protected function adminOf(Tenant $tenant): User
    {
        [$user] = $this->member($tenant, null);

        return $user;
    }

    /** A rate through the API as the signed-in user. @return array<string,mixed> */
    protected function rate(string $currency, string $rate, string $date, string $type = 'MANUAL', array $override = []): array
    {
        return $this->postJson(self::FX.'/exchange-rates', $override + ['from_currency' => $currency, 'rate' => $rate, 'effective_date' => $date, 'rate_type' => $type])->assertCreated()->json();
    }

    /** A posted vendor invoice in USD (amount in USD, single line) as the signed-in user. @return array<string,mixed> */
    protected function usdInvoice($vendor, string $amount = '1000.00', array $override = []): array
    {
        return $this->postedInvoice($vendor, $override + ['currency' => 'USD', 'lines' => [['description' => 'Jasa luar negeri', 'amount' => $amount]]]);
    }

    /** A posted customer invoice in USD as the signed-in user. @return array<string,mixed> */
    protected function usdArInvoice($customer, string $amount = '1000.00', array $override = []): array
    {
        return $this->postedArInvoice($customer, $override + ['currency' => 'USD', 'lines' => [['description' => 'Jasa ekspor', 'amount' => $amount]]]);
    }

    /** @return list<object> the lines of a journal with their foreign legs, ordered by account code then side */
    protected function journalLegs(string $journalId): array
    {
        return DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $journalId)
            ->orderBy('a.code')->orderByDesc('l.debit')->get(['a.code', 'l.debit', 'l.credit', 'l.transaction_currency', 'l.transaction_debit', 'l.transaction_credit', 'l.exchange_rate', 'l.is_fx_difference'])->all();
    }

    /** Functional debit and credit totals of a journal. @return array{string,string} */
    protected function journalTotals(string $journalId): array
    {
        $row = DB::table('journal_lines')->where('journal_entry_id', $journalId)->selectRaw('sum(debit) as d, sum(credit) as c')->first();

        return [(string) BigDecimal::of((string) $row->d)->toScale(4), (string) BigDecimal::of((string) $row->c)->toScale(4)];
    }
}
