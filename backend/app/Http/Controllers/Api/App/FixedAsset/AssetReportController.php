<?php

namespace App\Http\Controllers\Api\App\FixedAsset;

use App\Domain\Accounting\Support\CsvExporter;
use App\Domain\Audit\Services\AuditService;
use App\Domain\FixedAsset\Services\AssetReconciliationService;
use App\Domain\FixedAsset\Services\AssetSetupService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Register to ledger reconciliation and the default posting rules of the asset events. */
class AssetReportController extends AppController
{
    public function __construct(
        TenantContext $context,
        private readonly AssetReconciliationService $reconciliation,
        private readonly AssetSetupService $setup,
        private readonly AuditService $audit,
    ) {
        parent::__construct($context);
    }

    public function reconciliation(Request $request): JsonResponse
    {
        $filter = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->reconciliation->report($filter['as_of'] ?? null));
    }

    /** The reconciliation as a CSV (cost and accumulated depreciation by account). Read through the user's data scope; the export is audited. */
    public function exportReconciliation(Request $request): StreamedResponse
    {
        $filter = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']]);
        $report = $this->reconciliation->report($filter['as_of'] ?? null);
        $this->audit->record('fixed_asset.report.exported', 'report', 'asset_reconciliation', null, ['as_of' => $report['as_of'], 'complete' => $report['complete'], 'reconciled' => $report['reconciled']]);

        return CsvExporter::stream("rekonsiliasi-aset-tetap_{$report['as_of']}.csv", ['Kelompok', 'Kode akun', 'Nama akun', 'Jumlah aset', 'Register', 'Buku besar', 'Selisih', 'Cocok'], (function () use ($report) {
            foreach (['cost' => 'Harga perolehan', 'accumulated_depreciation' => 'Akumulasi penyusutan'] as $key => $label) {
                foreach ($report[$key] as $r) {
                    yield [$label, $r['account']['code'], $r['account']['name'], $r['asset_count'], CsvExporter::number($r['register']), CsvExporter::number($r['ledger']), CsvExporter::number($r['difference']), $r['matched'] ? 'Ya' : 'Tidak'];
                }
            }
            $t = $report['totals'];
            yield ['Nilai buku', '', '', '', CsvExporter::number($t['register_net_book_value']), CsvExporter::number($t['ledger_net_book_value']), CsvExporter::number($t['difference']), $report['reconciled'] ? 'Ya' : 'Tidak'];
        })());
    }

    public function setupStatus(): JsonResponse
    {
        return response()->json($this->setup->status());
    }

    public function applyDefaults(Request $request): JsonResponse
    {
        $data = $request->validate(['effective_from' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->setup->applyDefaults($data['effective_from'] ?? null), 201);
    }
}
