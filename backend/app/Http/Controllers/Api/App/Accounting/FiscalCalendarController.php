<?php

namespace App\Http\Controllers\Api\App\Accounting;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Services\FiscalCalendarService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiscalCalendarController extends AppController
{
    public function __construct(TenantContext $context, private readonly FiscalCalendarService $calendar)
    {
        parent::__construct($context);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => FiscalYear::query()->with('periods')->orderByDesc('start_date')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9_\/-]+$/'],
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'months' => ['sometimes', 'integer', 'between:1,18'],
        ]);

        return response()->json($this->calendar->createFiscalYear($data), 201);
    }

    public function open(Request $request, FiscalYear $fiscalYear): JsonResponse
    {
        $data = $request->validate(['open_periods' => ['sometimes', 'boolean']]);

        return response()->json($this->calendar->openFiscalYear($fiscalYear, $data['open_periods'] ?? true));
    }

    public function close(FiscalYear $fiscalYear): JsonResponse
    {
        return response()->json($this->calendar->closeFiscalYear($fiscalYear));
    }

    public function destroy(FiscalYear $fiscalYear): JsonResponse
    {
        $this->calendar->deleteFiscalYear($fiscalYear);

        return response()->json(null, 204);
    }

    public function openPeriod(AccountingPeriod $period): JsonResponse
    {
        return response()->json($this->calendar->transitionPeriod($period, AccountingPeriod::OPEN));
    }

    public function softClosePeriod(AccountingPeriod $period): JsonResponse
    {
        return response()->json($this->calendar->transitionPeriod($period, AccountingPeriod::SOFT_CLOSED));
    }

    public function closePeriod(AccountingPeriod $period): JsonResponse
    {
        return response()->json($this->calendar->transitionPeriod($period, AccountingPeriod::CLOSED));
    }
}
