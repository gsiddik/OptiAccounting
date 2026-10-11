<?php

namespace App\Http\Controllers\Api\App\Reporting;

use App\Domain\Reporting\Services\ComparativeReportService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** A statement beside its prior period, prior year or budget. Thin: filters are validated here, the comparison is the service's. */
class ComparativeController extends AppController
{
    use ValidatesReportFilters;

    public function __construct(TenantContext $context, private readonly ComparativeReportService $comparative)
    {
        parent::__construct($context);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->comparative->report($this->comparativeFilters($request)));
    }
}
