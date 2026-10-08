<?php

namespace Tests\Feature\Accounting;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Services\AccountingProfileService;
use App\Domain\Accounting\Services\FiscalCalendarService;
use App\Domain\Accounting\Services\PeriodGuard;
use App\Domain\Accounting\Services\ReadinessService;
use App\Domain\Shared\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AccountingFixtures;
use Tests\Support\Fixtures;
use Tests\TestCase;

/** OA1 batches A-B: accounting profile, readiness, fiscal years, periods and the period guard. */
class ProfileAndCalendarTest extends TestCase
{
    use AccountingFixtures, Fixtures;

    public function test_a_tenant_without_a_profile_is_not_configured_and_cannot_activate(): void
    {
        $tenant = $this->tenant();
        $this->asMember($tenant)->getJson('/api/v1/app/accounting/readiness')->assertOk()
            ->assertJsonPath('status', 'NOT_CONFIGURED')->assertJsonPath('ready', false)->assertJsonPath('can_activate', false);

        $this->inTenant($tenant, function () {
            $this->expectException(DomainException::class);
            app(AccountingProfileService::class)->activate();
        });
    }

    public function test_profile_is_saved_through_the_api_and_validated(): void
    {
        $tenant = $this->tenant();
        $client = $this->asMember($tenant);

        $client->putJson('/api/v1/app/accounting/profile', ['framework' => 'IFRS_FULL', 'functional_currency' => 'IDR'])->assertStatus(422);
        $client->putJson('/api/v1/app/accounting/profile', ['framework' => 'SAK_EMKM', 'functional_currency' => 'idr'])->assertStatus(422);
        $client->putJson('/api/v1/app/accounting/profile', ['framework' => 'SAK_EMKM', 'functional_currency' => 'IDR', 'tenant_id' => (string) Str::uuid(), 'status' => 'READY'])
            ->assertCreated()->assertJsonPath('framework', 'SAK_EMKM')->assertJsonPath('status', 'CONFIGURING')->assertJsonPath('tenant_id', $tenant->id);

        $client->putJson('/api/v1/app/accounting/profile', ['approval_required' => false])->assertOk()->assertJsonPath('approval_required', false);
        $this->assertSame(1, $this->rows('accounting_profiles'));
        $this->assertGreaterThan(0, $this->rows('audit_logs', ['tenant_id' => $tenant->id, 'action' => 'accounting.profile.created']));
    }

    public function test_readiness_names_the_missing_steps_and_activation_needs_all_of_them(): void
    {
        $tenant = $this->accountingTenant('alpha', activate: false);
        $this->inTenant($tenant, function () {
            $report = app(ReadinessService::class)->report();
            $this->assertTrue($report['can_activate']);
            $this->assertFalse($report['ready']);
        });

        $bare = $this->tenant('beta');
        $this->inTenant($bare, function () {
            app(AccountingProfileService::class)->save(['framework' => 'CUSTOM', 'functional_currency' => 'IDR']);
            $codes = collect(app(ReadinessService::class)->blockingChecks())->pluck('code')->all();
            $this->assertEqualsCanonicalizing(['FISCAL_YEAR', 'PERIOD', 'COA'], $codes);

            try {
                app(AccountingProfileService::class)->activate();
                $this->fail('activation must be refused');
            } catch (DomainException $e) {
                $this->assertSame('ACCOUNTING_SETUP_INCOMPLETE', $e->errorCode);
            }
        });

        $this->inTenant($tenant, function () {
            $this->assertSame(AccountingProfile::READY, app(AccountingProfileService::class)->activate()->status);
        });
    }

    public function test_a_non_calendar_fiscal_year_gets_monthly_periods(): void
    {
        $tenant = $this->tenant();
        $client = $this->asMember($tenant);

        $response = $client->postJson('/api/v1/app/accounting/fiscal-years', ['code' => 'FY2526', 'name' => 'Juli 2025 - Juni 2026', 'start_date' => '2025-07-01'])->assertCreated();
        $response->assertJsonPath('start_date', '2025-07-01')->assertJsonPath('end_date', '2026-06-30')->assertJsonPath('status', 'DRAFT');
        $this->assertCount(12, $response->json('periods'));
        $this->assertSame(['2025-07', '2026-06'], [$response->json('periods.0.code'), $response->json('periods.11.code')]);
        $this->assertSame('Februari 2026', $response->json('periods.7.name'));
        $this->assertSame('2026-02-28', $response->json('periods.7.end_date'));

        $client->postJson('/api/v1/app/accounting/fiscal-years', ['code' => 'MID', 'name' => 'x', 'start_date' => '2025-07-15'])->assertStatus(422)->assertJsonPath('code', 'FISCAL_YEAR_INVALID_START');
    }

    public function test_fiscal_years_cannot_overlap_or_repeat_a_code(): void
    {
        $tenant = $this->tenant();
        $client = $this->asMember($tenant);
        $client->postJson('/api/v1/app/accounting/fiscal-years', ['code' => 'FY2026', 'name' => 'a', 'start_date' => '2026-01-01'])->assertCreated();

        $client->postJson('/api/v1/app/accounting/fiscal-years', ['code' => 'FY2026B', 'name' => 'b', 'start_date' => '2026-07-01'])
            ->assertStatus(422)->assertJsonPath('code', 'FISCAL_YEAR_OVERLAP');
        $client->postJson('/api/v1/app/accounting/fiscal-years', ['code' => 'FY2026', 'name' => 'c', 'start_date' => '2027-01-01'])
            ->assertStatus(422)->assertJsonPath('code', 'FISCAL_YEAR_CODE_TAKEN');
        $this->assertSame(1, $this->rows('fiscal_years'));
        $this->assertSame(12, $this->rows('accounting_periods'));
    }

    public function test_the_database_refuses_overlapping_periods_and_periods_outside_their_year(): void
    {
        $tenant = $this->tenant();
        $year = $this->inTenant($tenant, fn () => app(FiscalCalendarService::class)->createFiscalYear(['code' => 'FY2026', 'name' => 'a', 'start_date' => '2026-01-01']));

        $this->expectException(QueryException::class);
        DB::table('accounting_periods')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'fiscal_year_id' => $year->id, 'number' => 13, 'code' => 'X', 'name' => 'x',
            'start_date' => '2026-01-10', 'end_date' => '2026-01-20', 'status' => 'FUTURE', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_period_lifecycle_open_soft_close_close_and_closed_is_final(): void
    {
        $tenant = $this->tenant();
        $client = $this->asMember($tenant);
        $year = $client->postJson('/api/v1/app/accounting/fiscal-years', ['code' => 'FY2026', 'name' => 'a', 'start_date' => '2026-01-01'])->assertCreated();
        $january = $year->json('periods.0.id');

        // A period cannot open before its fiscal year.
        $client->postJson("/api/v1/app/accounting/periods/{$january}/open")->assertStatus(409)->assertJsonPath('code', 'FISCAL_YEAR_NOT_OPEN');

        $client->postJson('/api/v1/app/accounting/fiscal-years/'.$year->json('id').'/open', ['open_periods' => false])->assertOk();
        $client->postJson("/api/v1/app/accounting/periods/{$january}/close")->assertStatus(409)->assertJsonPath('code', 'PERIOD_INVALID_TRANSITION'); // FUTURE -> CLOSED
        $client->postJson("/api/v1/app/accounting/periods/{$january}/open")->assertOk()->assertJsonPath('status', 'OPEN');
        $client->postJson("/api/v1/app/accounting/periods/{$january}/soft-close")->assertOk()->assertJsonPath('status', 'SOFT_CLOSED');
        $client->postJson("/api/v1/app/accounting/periods/{$january}/open")->assertOk()->assertJsonPath('status', 'OPEN');
        $client->postJson("/api/v1/app/accounting/periods/{$january}/close")->assertOk()->assertJsonPath('status', 'CLOSED')->assertJsonPath('closed_by', fn ($id) => $id !== null);
        $client->postJson("/api/v1/app/accounting/periods/{$january}/open")->assertStatus(409);

        $this->assertSame(['accounting.period.status_changed' => 4], DB::table('audit_logs')->where('action', 'accounting.period.status_changed')->selectRaw('action, count(*) c')->groupBy('action')->pluck('c', 'action')->all());
    }

    public function test_closing_a_period_needs_the_close_permission_and_a_fiscal_year_needs_all_periods_closed(): void
    {
        $tenant = $this->accountingTenant();
        $period = $this->period($tenant, '2026-01');
        $manager = $this->asMember($tenant, ['accounting.period.manage']);
        $manager->postJson("/api/v1/app/accounting/periods/{$period->id}/soft-close")->assertOk();
        $manager->postJson("/api/v1/app/accounting/periods/{$period->id}/close")->assertStatus(403);

        $closer = $this->asMember($tenant, ['accounting.period.close', 'accounting.period.manage']);
        $year = $this->fiscalYear($tenant, 'FY2026');
        $closer->postJson("/api/v1/app/accounting/fiscal-years/{$year->id}/close")->assertStatus(409)->assertJsonPath('code', 'FISCAL_YEAR_HAS_OPEN_PERIODS');
        $closer->deleteJson("/api/v1/app/accounting/fiscal-years/{$year->id}")->assertStatus(409)->assertJsonPath('code', 'FISCAL_YEAR_NOT_DRAFT');
    }

    public function test_period_guard_resolves_the_period_from_the_posting_date_and_enforces_status(): void
    {
        $tenant = $this->accountingTenant();
        $this->inTenant($tenant, function () {
            $guard = app(PeriodGuard::class);
            $calendar = app(FiscalCalendarService::class);

            $this->assertSame('2026-03', DB::transaction(fn () => $guard->resolveForPosting('2026-03-31'))->code);

            $this->assertCode('PERIOD_NOT_FOUND', fn () => DB::transaction(fn () => $guard->resolveForPosting('2027-01-01')));

            $march = AccountingPeriod::query()->where('code', '2026-03')->first();
            $calendar->transitionPeriod($march, AccountingPeriod::SOFT_CLOSED);
            $this->assertCode('PERIOD_SOFT_CLOSED', fn () => DB::transaction(fn () => $guard->resolveForPosting('2026-03-15')));
            $this->assertSame('2026-03', DB::transaction(fn () => $guard->resolveForPosting('2026-03-15', allowSoftClosed: true))->code);

            $calendar->transitionPeriod($march->refresh(), AccountingPeriod::CLOSED);
            $this->assertCode('PERIOD_CLOSED', fn () => DB::transaction(fn () => $guard->resolveForPosting('2026-03-15', allowSoftClosed: true)));

            $calendar->createFiscalYear(['code' => 'FY2027', 'name' => 'n', 'start_date' => '2027-01-01']);
            $this->assertCode('FISCAL_YEAR_NOT_OPEN', fn () => DB::transaction(fn () => $guard->resolveForPosting('2027-01-10')));
        });
    }

    public function test_a_closed_period_cannot_be_changed_at_the_database_level(): void
    {
        $tenant = $this->accountingTenant();
        $period = $this->inTenant($tenant, fn () => app(FiscalCalendarService::class)->transitionPeriod($this->period($tenant, '2026-01'), AccountingPeriod::CLOSED));

        $this->expectException(QueryException::class);
        DB::table('accounting_periods')->where('id', $period->id)->update(['status' => 'OPEN']);
    }

    public function test_profile_currency_is_frozen_after_the_first_posting_lock(): void
    {
        $tenant = $this->accountingTenant();
        $this->inTenant($tenant, function () use ($tenant) {
            app(AccountingProfileService::class)->lockOnFirstPosting($tenant->id);
            $this->assertCode('ACCOUNTING_PROFILE_LOCKED', fn () => app(AccountingProfileService::class)->save(['functional_currency' => 'USD']));
            $this->assertCode('ACCOUNTING_PROFILE_LOCKED', fn () => app(AccountingProfileService::class)->save(['currency_scale' => 0]));
            $this->assertSame('SAK_GENERAL', app(AccountingProfileService::class)->save(['framework' => 'SAK_GENERAL'])->framework);
        });
    }

    public function test_calendar_and_profile_are_isolated_between_tenants(): void
    {
        $alpha = $this->accountingTenant('alpha');
        $beta = $this->accountingTenant('beta');
        $period = $this->period($alpha, '2026-02');
        $year = $this->fiscalYear($alpha, 'FY2026');

        $client = $this->asMember($beta);
        $client->postJson("/api/v1/app/accounting/periods/{$period->id}/soft-close")->assertNotFound();
        $client->postJson("/api/v1/app/accounting/fiscal-years/{$year->id}/close")->assertNotFound();
        $this->assertCount(1, $client->getJson('/api/v1/app/accounting/fiscal-years')->json('data'));
        $this->assertSame($beta->id, $client->getJson('/api/v1/app/accounting/profile')->json('data.tenant_id'));
    }

    private function assertCode(string $code, callable $work): void
    {
        try {
            $work();
            $this->fail("expected {$code}");
        } catch (DomainException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }
}
