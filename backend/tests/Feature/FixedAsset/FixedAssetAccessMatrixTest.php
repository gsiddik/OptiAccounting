<?php

namespace Tests\Feature\FixedAsset;

use Tests\Support\AccountingFixtures;
use Tests\Support\FixedAssetFixtures;
use Tests\Support\Fixtures;
use Tests\Support\ModuleAccessMatrix;
use Tests\Support\PayablesFixtures;
use Tests\TestCase;

/** OA4: one sweep over every ACCOUNTING_FIXED_ASSET route for permission, tenant ownership and entitlement state. */
class FixedAssetAccessMatrixTest extends TestCase
{
    use AccountingFixtures, FixedAssetFixtures, Fixtures, ModuleAccessMatrix, PayablesFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->assetTenant('alpha');
        $this->bravo = $this->assetTenant('bravo');
        $this->admin = $this->memberToken($this->alpha);

        $this->as($this->admin);
        $category = $this->newCategory();
        $draft = $this->newAsset($this->alpha, $category);
        $active = $this->capitalizedAsset($this->alpha, $category, ['name' => 'Mesin aktif']);
        $run = $this->newRun($this->alpha, '2026-03');
        $disposal = $this->postJson(self::FA.'/asset-disposals', [
            'fixed_asset_id' => $active['id'], 'disposal_type' => 'SCRAP', 'disposal_date' => '2026-03-31', 'reason' => 'Rusak',
        ])->assertCreated()->json();
        $this->ids = ['category' => $category['id'], 'asset' => $draft['id'], 'run' => $run['id'], 'disposal' => $disposal['id']];
    }

    protected function matrixModules(): array
    {
        return ['ACCOUNTING_FIXED_ASSET'];
    }

    protected function matrixFeatures(): array
    {
        return ['ASSET_REGISTER', 'DEPRECIATION'];
    }

    protected function fingerprintTables(): array
    {
        return [
            'asset_categories', 'fixed_assets', 'asset_depreciation_schedules', 'asset_depreciation_runs', 'asset_depreciation_run_lines', 'asset_disposals',
            'document_transitions', 'journal_entries', 'journal_lines', 'audit_logs',
        ];
    }

    protected function pinnedPermissions(): array
    {
        return [
            'DELETE asset-categories/{category}' => 'accounting.asset_category.manage',
            'GET asset-categories' => 'accounting.asset.view',
            'GET asset-categories/{category}' => 'accounting.asset.view',
            'GET asset-disposals' => 'accounting.asset.view',
            'GET asset-disposals/{disposal}' => 'accounting.asset.view',
            'GET asset-reconciliation' => 'accounting.asset.reconciliation.view',
            'GET asset-reconciliation/export' => 'accounting.report.export',
            'GET asset-rules' => 'accounting.asset.view',
            'GET assets' => 'accounting.asset.view',
            'GET assets/{asset}' => 'accounting.asset.view',
            'GET assets/{asset}/schedule' => 'accounting.asset.view',
            'GET depreciation-runs' => 'accounting.asset.view',
            'GET depreciation-runs/{run}' => 'accounting.asset.view',
            'PATCH asset-categories/{category}' => 'accounting.asset_category.manage',
            'PATCH asset-disposals/{disposal}' => 'accounting.asset.dispose',
            'PATCH assets/{asset}' => 'accounting.asset.manage',
            'POST asset-categories' => 'accounting.asset_category.manage',
            'POST asset-categories/{category}/activate' => 'accounting.asset_category.manage',
            'POST asset-categories/{category}/deactivate' => 'accounting.asset_category.manage',
            'POST asset-disposals' => 'accounting.asset.dispose',
            'POST asset-disposals/{disposal}/approve' => 'accounting.asset.disposal.approve',
            'POST asset-disposals/{disposal}/cancel' => 'accounting.asset.dispose',
            'POST asset-disposals/{disposal}/post' => 'accounting.asset.disposal.post',
            'POST asset-disposals/{disposal}/reject' => 'accounting.asset.disposal.approve',
            'POST asset-disposals/{disposal}/reopen' => 'accounting.asset.dispose',
            'POST asset-disposals/{disposal}/reverse' => 'accounting.asset.disposal.post',
            'POST asset-disposals/{disposal}/submit' => 'accounting.asset.dispose',
            'POST asset-rules/defaults' => 'accounting.posting_rule.manage',
            'POST assets' => 'accounting.asset.manage',
            'POST assets/{asset}/capitalize' => 'accounting.asset.capitalize',
            'POST assets/{asset}/discard' => 'accounting.asset.manage',
            'POST assets/{asset}/reverse-capitalization' => 'accounting.asset.capitalize',
            'POST depreciation-runs' => 'accounting.asset.depreciation.run',
            'POST depreciation-runs/{run}/cancel' => 'accounting.asset.depreciation.run',
            'POST depreciation-runs/{run}/post' => 'accounting.asset.depreciation.post',
            'POST depreciation-runs/{run}/reverse' => 'accounting.asset.depreciation.post',
        ];
    }
}
