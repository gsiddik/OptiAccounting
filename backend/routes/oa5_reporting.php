<?php

use App\Http\Controllers\Api\App\Reporting;
use Illuminate\Support\Facades\Route;

/*
| OA5 reporting routes. Included from routes/api.php inside the tenant `accounting` prefix group (auth + tenant context already applied).
| Every route declares access:<permission>,module=ACCOUNTING_REPORTING,feature=<feature>; the central resolver is the only authority.
*/
$rp = fn (string $permission, string $feature = 'FINANCIAL_STATEMENTS') => "access:{$permission},module=ACCOUNTING_REPORTING,feature={$feature}";

// ------------------------------------------------------------------------------------------ reports (derived live from POSTED lines)
Route::get('reports/account-balance', [Reporting\AccountBalanceController::class, 'show'])->middleware($rp('accounting.gl.view'));

Route::get('reports/balance-sheet', [Reporting\StatementController::class, 'balanceSheet'])->middleware($rp('accounting.reporting.balance_sheet.view'));
Route::get('reports/profit-loss', [Reporting\StatementController::class, 'profitLoss'])->middleware($rp('accounting.reporting.pnl.view'));
Route::get('reports/cash-flow', [Reporting\StatementController::class, 'cashFlow'])->middleware($rp('accounting.reporting.cash_flow.view'));
// The comparative serves all three statements behind one route: the gate is the profit and loss permission, and the service additionally requires the
// view permission of the statement named in the request (and, for a budget comparison, accounting.budget.view with the budget module).
Route::get('reports/comparative', [Reporting\ComparativeController::class, 'show'])->middleware($rp('accounting.reporting.pnl.view'));

// Exports run the report's own service with the same filters. The gate is the export permission; the controller additionally requires the view
// permission of the report exported, so exporting never opens a report the user could not read.
Route::get('reports/account-balance/export', [Reporting\ReportExportController::class, 'accountBalance'])->middleware($rp('accounting.report.export'));
Route::get('reports/balance-sheet/export', [Reporting\ReportExportController::class, 'balanceSheet'])->middleware($rp('accounting.report.export'));
Route::get('reports/profit-loss/export', [Reporting\ReportExportController::class, 'profitLoss'])->middleware($rp('accounting.report.export'));
Route::get('reports/cash-flow/export', [Reporting\ReportExportController::class, 'cashFlow'])->middleware($rp('accounting.report.export'));
Route::get('reports/comparative/export', [Reporting\ReportExportController::class, 'comparative'])->middleware($rp('accounting.report.export'));

// The accounts behind a statement line and the general ledger query that lists their journals: the gate is the ledger view permission, the service
// additionally requires the view permission of the statement named in the request.
Route::get('reports/drilldown', [Reporting\DrilldownController::class, 'show'])->middleware($rp('accounting.gl.view'));

// ------------------------------------------------------------------------------------------ report definitions (layout, versions, account mapping)
Route::get('report-definitions', [Reporting\ReportDefinitionController::class, 'index'])->middleware($rp('accounting.report_definition.view'));
Route::post('report-definitions', [Reporting\ReportDefinitionController::class, 'store'])->middleware($rp('accounting.report_definition.manage'));
Route::post('report-definitions/defaults', [Reporting\ReportDefinitionController::class, 'applyDefaults'])->middleware($rp('accounting.report_definition.manage'));
Route::get('report-definitions/{definition}', [Reporting\ReportDefinitionController::class, 'show'])->middleware($rp('accounting.report_definition.view'));
Route::patch('report-definitions/{definition}', [Reporting\ReportDefinitionController::class, 'update'])->middleware($rp('accounting.report_definition.manage'));
Route::delete('report-definitions/{definition}', [Reporting\ReportDefinitionController::class, 'destroy'])->middleware($rp('accounting.report_definition.manage'));
Route::put('report-definitions/{definition}/lines', [Reporting\ReportDefinitionController::class, 'replaceLines'])->middleware($rp('accounting.report_definition.manage'));
Route::post('report-definitions/{definition}/lines', [Reporting\ReportDefinitionController::class, 'storeLine'])->middleware($rp('accounting.report_definition.manage'));
Route::patch('report-definitions/{definition}/lines/{line}', [Reporting\ReportDefinitionController::class, 'updateLine'])->middleware($rp('accounting.report_definition.manage'));
Route::delete('report-definitions/{definition}/lines/{line}', [Reporting\ReportDefinitionController::class, 'destroyLine'])->middleware($rp('accounting.report_definition.manage'));
Route::put('report-definitions/{definition}/mappings', [Reporting\ReportDefinitionController::class, 'replaceMappings'])->middleware($rp('accounting.report_definition.manage'));
Route::get('report-definitions/{definition}/unmapped-accounts', [Reporting\ReportDefinitionController::class, 'unmapped'])->middleware($rp('accounting.report_definition.view'));
Route::post('report-definitions/{definition}/validate', [Reporting\ReportDefinitionController::class, 'validateDefinition'])->middleware($rp('accounting.report_definition.view'));
Route::post('report-definitions/{definition}/new-version', [Reporting\ReportDefinitionController::class, 'newVersion'])->middleware($rp('accounting.report_definition.manage'));
Route::post('report-definitions/{definition}/publish', [Reporting\ReportDefinitionController::class, 'publish'])->middleware($rp('accounting.report_definition.manage'));
Route::post('report-definitions/{definition}/archive', [Reporting\ReportDefinitionController::class, 'archive'])->middleware($rp('accounting.report_definition.manage'));
