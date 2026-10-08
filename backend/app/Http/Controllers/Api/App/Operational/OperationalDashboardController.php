<?php

namespace App\Http\Controllers\Api\App\Operational;

use App\Domain\Accounting\Services\OperationalSummaryService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

/** The OA2 counters for the accounting home. Each section is decided by the central access resolver inside the service. */
class OperationalDashboardController extends AppController
{
    public function __construct(TenantContext $context, private readonly OperationalSummaryService $summary)
    {
        parent::__construct($context);
    }

    public function show(): JsonResponse
    {
        return response()->json($this->summary->summary());
    }
}
