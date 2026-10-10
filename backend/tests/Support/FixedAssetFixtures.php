<?php

namespace Tests\Support;

use App\Domain\FixedAsset\Services\AssetSetupService;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** Test helpers for the OA4 fixed asset module; combine with AccountingFixtures, Fixtures and PayablesFixtures. All helpers act as the already-authenticated client. */
trait FixedAssetFixtures
{
    protected const FA = '/api/v1/app/accounting';

    /** An accounting tenant with the OA2/OA3 rules and the asset rules published from the start of its fiscal year; one person may prepare, approve and post. */
    protected function assetTenant(string $code = 'alpha', array $profile = []): Tenant
    {
        $tenant = $this->payablesTenant($code, $profile + ['sod_creator_not_approver' => false, 'sod_creator_not_poster' => false, 'sod_approver_not_poster' => false]);
        $this->inTenant($tenant, fn () => app(AssetSetupService::class)->applyDefaults('2026-01-01'));

        return $tenant;
    }

    /** @return array<string,mixed> a category (12 month straight line by default; accounts come from the role mapping) */
    protected function newCategory(array $override = []): array
    {
        return $this->postJson(self::FA.'/asset-categories', $override + ['code' => 'EQP'.substr(uniqid(), -4), 'name' => 'Peralatan', 'default_useful_life_months' => 12])->assertCreated()->json();
    }

    /** @return array<string,mixed> */
    protected function assetBody(Tenant $tenant, array $category, array $override = []): array
    {
        return $override + [
            'asset_category_id' => $category['id'], 'name' => 'Mesin Pencetak', 'acquisition_date' => '2026-03-15', 'capitalization_date' => '2026-03-15',
            'acquisition_cost' => '12000000', 'useful_life_months' => 12, 'source_account_id' => $this->account($tenant, '2140')->id,
        ];
    }

    /** @return array<string,mixed> a draft asset */
    protected function newAsset(Tenant $tenant, array $category, array $override = []): array
    {
        return $this->postJson(self::FA.'/assets', $this->assetBody($tenant, $category, $override))->assertCreated()->json();
    }

    /** @return array<string,mixed> a capitalized asset */
    protected function capitalizedAsset(Tenant $tenant, array $category, array $override = []): array
    {
        $asset = $this->newAsset($tenant, $category, $override);

        return $this->postJson(self::FA."/assets/{$asset['id']}/capitalize")->assertOk()->json();
    }

    /** @return array<string,mixed> a calculated (draft) depreciation run up to the end of $month ('2026-03') */
    protected function newRun(Tenant $tenant, string $month, array $override = []): array
    {
        return $this->postJson(self::FA.'/depreciation-runs', $override + ['accounting_period_id' => $this->period($tenant, $month)->id])->assertCreated()->json();
    }

    /** @return array<string,mixed> a posted depreciation run */
    protected function postedRun(Tenant $tenant, string $month, array $override = []): array
    {
        $run = $this->newRun($tenant, $month, $override);

        return $this->postJson(self::FA."/depreciation-runs/{$run['id']}/post")->assertOk()->json();
    }

    /** @return array<string,mixed> a posted disposal (sale unless told otherwise) */
    protected function postedDisposal(Tenant $tenant, array $asset, array $override = []): array
    {
        $body = $override + ['fixed_asset_id' => $asset['id'], 'disposal_type' => 'SALE', 'disposal_date' => '2026-06-30', 'proceeds_amount' => '10000000',
            'proceeds_account_id' => $this->account($tenant, '1120')->id, 'reason' => 'Dijual'];
        $disposal = $this->postJson(self::FA.'/asset-disposals', $body)->assertCreated()->json();
        $this->postJson(self::FA."/asset-disposals/{$disposal['id']}/submit")->assertOk();
        $this->postJson(self::FA."/asset-disposals/{$disposal['id']}/approve")->assertOk();

        return $this->postJson(self::FA."/asset-disposals/{$disposal['id']}/post")->assertOk()->json();
    }

    protected function assetRow(string $id): object
    {
        return DB::table('fixed_assets')->where('id', $id)->first();
    }

    /** @return list<array{string,string,string}> the lines of a journal as [account code, debit, credit], ordered by line */
    protected function journalLines(string $journalId): array
    {
        return DB::table('journal_lines as l')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('l.journal_entry_id', $journalId)->orderBy('l.line_number')
            ->get(['a.code', 'l.debit', 'l.credit'])->map(fn ($r) => [$r->code, $r->debit, $r->credit])->all();
    }

    /** Close a period through the API, as the already-authenticated client. */
    protected function closePeriod(Tenant $tenant, string $month): void
    {
        $this->postJson(self::FA.'/periods/'.$this->period($tenant, $month)->id.'/close')->assertOk();
    }
}
