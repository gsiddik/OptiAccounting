<?php

namespace App\Http\Controllers\Api\App\Reporting;

use App\Domain\Reporting\Services\AccountBalanceService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Account balance of one account, a range or an account group, as of a date or over a period. Thin: the filters are validated here, the figures come from the service. */
class AccountBalanceController extends AppController
{
    use ValidatesReportFilters;

    public function __construct(TenantContext $context, private readonly AccountBalanceService $balances)
    {
        parent::__construct($context);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->balances->report($this->accountBalanceFilters($request)));
    }
}
