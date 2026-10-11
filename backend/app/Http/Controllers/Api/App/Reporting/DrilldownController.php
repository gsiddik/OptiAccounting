<?php

namespace App\Http\Controllers\Api\App\Reporting;

use App\Domain\Reporting\Services\DrilldownService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The accounts behind one line of a statement, with the general ledger query that lists their journal lines. Thin: filters here, the explanation in the service. */
class DrilldownController extends AppController
{
    use ValidatesReportFilters;

    public function __construct(TenantContext $context, private readonly DrilldownService $drilldown)
    {
        parent::__construct($context);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->drilldown->report($this->drilldownFilters($request)));
    }
}
