<?php

namespace App\Http\Controllers\Api\App\Closing;

use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Closing\Services\YearEndClosingService;
use App\Domain\Closing\Services\YearEndSetupService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The year-end closing: listing, preview, run, reverse and the posting rule defaults. Thin: every rule is in the closing services. */
class YearEndController extends AppController
{
    public function __construct(TenantContext $context, private readonly YearEndClosingService $yearEnd, private readonly YearEndSetupService $setup)
    {
        parent::__construct($context);
    }

    public function index(): JsonResponse
    {
        return response()->json($this->yearEnd->index());
    }

    public function show(FiscalYear $fiscalYear): JsonResponse
    {
        return response()->json($this->yearEnd->show($fiscalYear));
    }

    public function preview(FiscalYear $fiscalYear): JsonResponse
    {
        return response()->json($this->yearEnd->preview($fiscalYear));
    }

    public function run(Request $request, FiscalYear $fiscalYear): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return response()->json($this->yearEnd->run($fiscalYear, $data['reason'] ?? null), 201);
    }

    public function reverse(Request $request, FiscalYear $fiscalYear): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);

        return response()->json($this->yearEnd->reverse($fiscalYear, $data['reason']));
    }

    public function ruleStatus(): JsonResponse
    {
        return response()->json($this->setup->status());
    }

    public function applyRuleDefaults(Request $request): JsonResponse
    {
        $data = $request->validate(['effective_from' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->setup->applyDefaults($data['effective_from'] ?? null), 201);
    }
}
