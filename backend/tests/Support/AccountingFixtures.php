<?php

namespace Tests\Support;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Services\AccountingProfileService;
use App\Domain\Accounting\Services\ChartOfAccountsService;
use App\Domain\Accounting\Services\FiscalCalendarService;
use App\Domain\Identity\Models\Tenant;
use App\Support\TenantContext;

/** Test helpers for the Accounting Core; combine with Fixtures. */
trait AccountingFixtures
{
    /** Run $work with the tenant context of $tenant (services read the tenant from the context). */
    protected function inTenant(Tenant $tenant, callable $work): mixed
    {
        return app(TenantContext::class)->runAs($tenant->id, $work);
    }

    /**
     * A tenant ready to post: profile (IDR, scale 2), one open calendar-year fiscal year with open periods,
     * the Indonesian SME chart of accounts and its role mappings, accounting activated.
     */
    protected function accountingTenant(string $code = 'alpha', string $fiscalStart = '2026-01-01', bool $activate = true, array $profile = []): Tenant
    {
        $tenant = $this->tenant($code);
        $this->inTenant($tenant, function () use ($fiscalStart, $activate, $profile) {
            app(AccountingProfileService::class)->save($profile + ['framework' => 'SAK_EP', 'functional_currency' => 'IDR', 'currency_scale' => 2]);
            $calendar = app(FiscalCalendarService::class);
            $year = $calendar->createFiscalYear(['code' => 'FY'.substr($fiscalStart, 0, 4), 'name' => 'Tahun '.substr($fiscalStart, 0, 4), 'start_date' => $fiscalStart]);
            $calendar->openFiscalYear($year);
            app(ChartOfAccountsService::class)->applyTemplate('UMUM_ID');
            if ($activate) {
                app(AccountingProfileService::class)->activate();
            }
        });

        return $tenant;
    }

    protected function account(Tenant $tenant, string $code): Account
    {
        return $this->inTenant($tenant, fn () => Account::query()->where('code', $code)->firstOrFail());
    }

    protected function period(Tenant $tenant, string $code): AccountingPeriod
    {
        return $this->inTenant($tenant, fn () => AccountingPeriod::query()->where('code', $code)->firstOrFail());
    }

    protected function fiscalYear(Tenant $tenant, string $code): FiscalYear
    {
        return $this->inTenant($tenant, fn () => FiscalYear::query()->where('code', $code)->firstOrFail());
    }
}
