<?php

namespace App\Http\Controllers\Api\App\Budget;

use App\Domain\Accounting\Support\CsvExporter;
use App\Domain\Accounting\Support\ListExport;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Budget\Services\BudgetVsActualService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Budget versus actual (JSON and CSV). The export runs the same service call as the screen, so tenant, version and data scope are identical, and is audited. */
class BudgetReportController extends AppController
{
    public function __construct(TenantContext $context, private readonly BudgetVsActualService $report, private readonly AuditService $audit)
    {
        parent::__construct($context);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->report->report($this->filter($request)));
    }

    public function export(Request $request): StreamedResponse
    {
        $filter = $this->filter($request);
        $report = $this->report->report($filter);
        $this->audit->record('budget.report.exported', 'report', 'budget_vs_actual', null, ['filters' => array_filter($filter, fn ($v) => $v !== null), 'rows' => count($report['rows']), 'version' => $report['version']['version_number']]);

        return CsvExporter::stream("budget-vs-actual_{$report['budget']['code']}.csv",
            ['Kode', 'Nama', 'Anggaran', 'Aktual', 'Selisih', 'Selisih %', 'Menguntungkan', 'Tanpa anggaran'],
            (function () use ($report) {
                foreach ($report['rows'] as $r) {
                    yield [$r['code'], $r['name'], ListExport::number($r['budget']), ListExport::number($r['actual']), ListExport::number($r['variance']), $r['variance_pct'] === null ? '' : CsvExporter::number($r['variance_pct']),
                        $r['favorable'] === null ? '' : ($r['favorable'] ? 'Ya' : 'Tidak'), $r['unbudgeted'] ? 'Ya' : 'Tidak'];
                }
                $t = $report['totals'];
                yield ['', 'Total', ListExport::number($t['budget']), ListExport::number($t['actual']), ListExport::number($t['variance']), $t['variance_pct'] === null ? '' : CsvExporter::number($t['variance_pct']), '', ''];
            })());
    }

    /** @return array<string,mixed> */
    private function filter(Request $request): array
    {
        return $request->validate([
            'budget_id' => ['required', 'uuid'], 'version_id' => ['nullable', 'uuid'], 'as_of' => ['nullable', 'date_format:Y-m-d'],
            'period_from' => ['nullable', 'uuid'], 'period_to' => ['nullable', 'uuid'], 'group_by' => ['nullable', Rule::in(BudgetVsActualService::GROUPS)],
            'account_id' => ['nullable', 'uuid'], 'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'],
        ]);
    }
}
