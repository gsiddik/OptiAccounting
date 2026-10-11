<?php

namespace Tests\Support;

use App\Domain\FixedAsset\Services\AssetSetupService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Reconciliation\Services\ReconciliationEngine;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Test helpers for the OA5 reconciliation framework. Combine with AccountingFixtures, Fixtures, PayablesFixtures, ReceivablesFixtures,
 * FixedAssetFixtures, TaxFixtures and FxFixtures (a test class lists the traits it needs, like the OA4 tests do). All HTTP helpers act as the
 * already-authenticated client.
 */
trait ReconciliationFixtures
{
    protected const RC = '/api/v1/app/accounting';

    /** A tenant with every reconciled subledger prepared: AP, AR, cash/bank, assets, tax codes' roles and USD with the foreign settlement rules (no segregation limit). */
    protected function reconTenant(string $code = 'alpha'): Tenant
    {
        $tenant = $this->fxTenant($code);
        $this->inTenant($tenant, fn () => app(AssetSetupService::class)->applyDefaults('2026-01-01'));

        return $tenant;
    }

    /** The engine as the given member of the tenant would run it (the data scope comes from the member). */
    protected function engineFor(Tenant $tenant): ReconciliationEngine
    {
        $context = app(TenantContext::class);
        $context->setTenant($tenant->id);
        $context->setUser($this->member($tenant)[0]);

        return app(ReconciliationEngine::class);
    }

    /** A posted journal that moves $amount on one account against a neutral one: the stray line no document explains. */
    protected function strayLine(Tenant $tenant, string $accountCode, string $side, string $amount = '5000', string $date = '2026-03-20', string $counter = '6900'): void
    {
        $lines = $side === 'CREDIT' ? $this->lines($tenant, $amount, $counter, $accountCode) : $this->lines($tenant, $amount, $accountCode, $counter);
        $this->postedJournal($tenant, ['lines' => $lines, 'posting_date' => $date, 'document_date' => $date]);
    }

    /** The tenant's closing settings with a reconciliation tolerance (the closing area owns the row; tests write it directly). */
    protected function setTolerance(Tenant $tenant, string $tolerance): void
    {
        DB::table('closing_settings')->updateOrInsert(['tenant_id' => $tenant->id], [
            'id' => DB::table('closing_settings')->where('tenant_id', $tenant->id)->value('id') ?? (string) Str::uuid(),
            'reconciliation_tolerance' => $tolerance, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** One result of the overview / detail response by type. @return array<string,mixed> */
    protected function reconResult(string $slug, string $asOf): array
    {
        return $this->getJson(self::RC."/reconciliations/{$slug}?as_of={$asOf}")->assertOk()->json();
    }

    /** The component of a result by code. @return array<string,mixed> */
    protected function componentOf(array $result, string $code): array
    {
        $found = collect($result['components'])->firstWhere('code', $code);
        $this->assertNotNull($found, "component {$code} missing in ".json_encode(array_column($result['components'], 'code')));

        return $found;
    }

    protected function dec(string $value): string
    {
        return (string) BigDecimal::of($value)->toScale(4);
    }
}
