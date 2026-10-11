<?php

namespace App\Http\Controllers\Api\App\Reporting;

use App\Domain\Reporting\Services\BalanceSheetService;
use App\Domain\Reporting\Services\CashFlowService;
use App\Domain\Reporting\Services\ProfitLossService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The financial statements derived live from POSTED lines. Thin: filters are validated here, the figures come from the statement services. */
class StatementController extends AppController
{
    use ValidatesReportFilters;

    public function __construct(TenantContext $context, private readonly BalanceSheetService $balanceSheet, private readonly ProfitLossService $profitLoss, private readonly CashFlowService $cashFlow)
    {
        parent::__construct($context);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        return response()->json($this->balanceSheet->report($this->balanceSheetFilters($request)));
    }

    public function profitLoss(Request $request): JsonResponse
    {
        return response()->json($this->profitLoss->report($this->periodStatementFilters($request)));
    }

    public function cashFlow(Request $request): JsonResponse
    {
        return response()->json($this->cashFlow->report($this->periodStatementFilters($request)));
    }
}
