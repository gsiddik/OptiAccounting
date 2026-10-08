<?php

namespace Tests\Support;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\AccountingProfileService;
use App\Domain\Accounting\Services\ChartOfAccountsService;
use App\Domain\Accounting\Services\FiscalCalendarService;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Accounting\Services\PostingEngine;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\User;
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

    /** @return list<array<string,mixed>> a balanced two-line journal body: cash (debit) against revenue (credit) unless told otherwise */
    protected function lines(Tenant $tenant, string $amount = '100000', string $debit = '1110', string $credit = '4100', array $dimensions = []): array
    {
        return [
            ['account_id' => $this->account($tenant, $debit)->id, 'debit' => $amount, 'description' => 'Debit'] + $dimensions,
            ['account_id' => $this->account($tenant, $credit)->id, 'credit' => $amount, 'description' => 'Kredit'] + $dimensions,
        ];
    }

    protected function journalBody(Tenant $tenant, array $override = []): array
    {
        return $override + [
            'document_date' => '2026-03-10', 'posting_date' => '2026-03-10', 'description' => 'Penjualan tunai', 'reference' => 'INV-001',
            'lines' => $this->lines($tenant),
        ];
    }

    /** Create a draft journal through the API as the already-authenticated client and return its JSON. */
    protected function draft(Tenant $tenant, array $override = []): array
    {
        return $this->postJson('/api/v1/app/accounting/journals', $this->journalBody($tenant, $override))->assertCreated()->json();
    }

    /** A signed-in client for a member holding exactly $permissions (null = all). */
    protected function signedIn(Tenant $tenant, ?array $permissions = null, ?User $user = null, string $scope = 'TENANT'): static
    {
        [$user] = $this->member($tenant, $permissions, user: $user, scope: $scope);

        return $this->as($this->tenantToken($user, $tenant));
    }

    /** Post a journal as system-trusted code (tests that need posted data, not the workflow itself). Returns the posted JournalEntry. */
    protected function postedJournal(Tenant $tenant, array $override = [], ?User $actor = null): JournalEntry
    {
        return $this->inTenant($tenant, function () use ($tenant, $override, $actor) {
            $actor ??= $this->member($tenant)[0];
            $service = app(JournalService::class);
            $profile = $service->profile();
            $body = $this->journalBody($tenant, $override);
            $journal = $service->newDraft($body, JournalEntry::SYSTEM, $profile, $actor->id);
            $service->writeLines($journal, $body['lines'], $profile, $actor->id, enforceScope: false);

            return app(PostingEngine::class)->post($journal, null);
        });
    }
}
