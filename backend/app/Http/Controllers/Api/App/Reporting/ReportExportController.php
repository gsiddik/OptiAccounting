<?php

namespace App\Http\Controllers\Api\App\Reporting;

use App\Domain\Accounting\Support\CsvExporter;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Services\AccountBalanceService;
use App\Domain\Reporting\Services\BalanceSheetService;
use App\Domain\Reporting\Services\CashFlowService;
use App\Domain\Reporting\Services\ComparativeReportService;
use App\Domain\Reporting\Services\ProfitLossService;
use App\Domain\Reporting\Services\ReportAccess;
use App\Domain\Reporting\Support\ReportCsv;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV exports of the OA5 reports. Each export validates the same filters and calls the same service as the report on screen, so the figures, the
 * tenant and the data scope are identical; the route needs `accounting.report.export` and the service-side check below adds the view permission of
 * the report being exported (exporting never opens a report the user could not read; the check comes before the filters are read, so a caller
 * without the view permission learns nothing about the report). Every export is audited with its filters.
 */
class ReportExportController extends AppController
{
    use ValidatesReportFilters;

    public function __construct(
        TenantContext $context,
        private readonly AccountBalanceService $accountBalance,
        private readonly BalanceSheetService $balanceSheet,
        private readonly ProfitLossService $profitLoss,
        private readonly CashFlowService $cashFlow,
        private readonly ComparativeReportService $comparative,
        private readonly ReportAccess $access,
        private readonly AuditService $audit,
    ) {
        parent::__construct($context);
    }

    public function accountBalance(Request $request): StreamedResponse
    {
        $this->access->assertLedger();
        $filters = $this->accountBalanceFilters($request);
        $report = $this->accountBalance->report($filters);

        return $this->download('account_balance', $filters, $report, ReportCsv::accountBalance($report));
    }

    public function balanceSheet(Request $request): StreamedResponse
    {
        $this->access->assertStatement(ReportDefinition::BALANCE_SHEET);
        $filters = $this->balanceSheetFilters($request);
        $report = $this->balanceSheet->report($filters);

        return $this->download('balance_sheet', $filters, $report, ReportCsv::statement($report));
    }

    public function profitLoss(Request $request): StreamedResponse
    {
        $this->access->assertStatement(ReportDefinition::PROFIT_LOSS);
        $filters = $this->periodStatementFilters($request);
        $report = $this->profitLoss->report($filters);

        return $this->download('profit_loss', $filters, $report, ReportCsv::statement($report));
    }

    public function cashFlow(Request $request): StreamedResponse
    {
        $this->access->assertStatement(ReportDefinition::CASH_FLOW);
        $filters = $this->periodStatementFilters($request);
        $report = $this->cashFlow->report($filters);

        return $this->download('cash_flow', $filters, $report, ReportCsv::statement($report));
    }

    public function comparative(Request $request): StreamedResponse
    {
        $filters = $this->comparativeFilters($request);
        $report = $this->comparative->report($filters); // checks the view permission of the statement (and the budget's) itself

        return $this->download('comparative_'.strtolower($filters['statement']).'_'.strtolower($filters['mode']), $filters, $report, ReportCsv::comparative($report));
    }

    /**
     * @param  array<string,mixed>  $filters
     * @param  array<string,mixed>  $report
     * @param  array{filename:string,header:list<string>,rows:list<list<mixed>>}  $csv
     */
    private function download(string $name, array $filters, array $report, array $csv): StreamedResponse
    {
        $this->audit->record('accounting.report.exported', 'report', $name, null, array_filter([
            'filters' => array_filter($filters, fn ($v) => $v !== null && $v !== false && $v !== []),
            'as_of' => $report['as_of'] ?? null,
            'range' => $report['range'] ?? null,
            'definition' => isset($report['definition']['id']) ? array_intersect_key($report['definition'], array_flip(['id', 'code', 'version'])) : null,
            'complete' => $report['complete'],
            'rows' => count($csv['rows']),
        ], fn ($v) => $v !== null));

        return CsvExporter::stream($csv['filename'], $csv['header'], $csv['rows']);
    }
}
