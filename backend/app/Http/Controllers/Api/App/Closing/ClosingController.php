<?php

namespace App\Http\Controllers\Api\App\Closing;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Closing\Services\ChecklistService;
use App\Domain\Closing\Services\CloseReadinessService;
use App\Domain\Closing\Services\ClosingHistory;
use App\Domain\Closing\Services\ClosingOverviewService;
use App\Domain\Closing\Services\PeriodClosingService;
use App\Domain\Closing\Services\ReadinessResult;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Period closing: readiness, checklist, pre-close, soft close, hard close, reopen and the history. Thin: every rule is in the closing services. */
class ClosingController extends AppController
{
    public function __construct(
        TenantContext $context,
        private readonly CloseReadinessService $readiness,
        private readonly ClosingOverviewService $overview,
        private readonly PeriodClosingService $closing,
        private readonly ChecklistService $checklist,
        private readonly ClosingHistory $history,
    ) {
        parent::__construct($context);
    }

    public function dashboard(): JsonResponse
    {
        return response()->json($this->overview->overview());
    }

    public function evaluate(Request $request, AccountingPeriod $period): JsonResponse
    {
        $data = $request->validate(['target' => ['sometimes', Rule::in([ReadinessResult::SOFT, ReadinessResult::HARD])]]);

        return response()->json($this->readiness->evaluate($period, $data['target'] ?? ReadinessResult::HARD)->toArray());
    }

    public function events(Request $request): JsonResponse
    {
        $this->readiness->assertFullScope();
        $filters = $request->validate([
            'period_id' => ['nullable', 'uuid'], 'fiscal_year_id' => ['nullable', 'uuid'],
            'event_type' => ['nullable', Rule::in(['PRE_CLOSE', 'SOFT_CLOSE', 'HARD_CLOSE', 'REOPEN', 'YEAR_END_RUN', 'YEAR_END_REVERSE'])], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return response()->json($this->history->query($filters));
    }

    public function preClose(AccountingPeriod $period): JsonResponse
    {
        $done = $this->closing->preClose($period);

        return response()->json($done['readiness']->toArray() + ['event_id' => $done['event_id']]);
    }

    public function softClose(Request $request, AccountingPeriod $period): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return response()->json($this->closing->softClose($period, $data['reason'] ?? null));
    }

    public function hardClose(Request $request, AccountingPeriod $period): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return response()->json($this->closing->hardClose($period, $data['reason'] ?? null));
    }

    public function reopen(Request $request, AccountingPeriod $period): JsonResponse
    {
        $data = $request->validate([
            'target' => ['sometimes', Rule::in([AccountingPeriod::SOFT_CLOSED, AccountingPeriod::OPEN])],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ]);

        $default = $period->status === AccountingPeriod::SOFT_CLOSED ? AccountingPeriod::OPEN : AccountingPeriod::SOFT_CLOSED; // the most conservative step back

        return response()->json($this->closing->reopen($period, $data['target'] ?? $default, $data['reason']));
    }

    public function showChecklist(AccountingPeriod $period): JsonResponse
    {
        return response()->json($this->checklist->view($period));
    }

    public function completeItem(Request $request, AccountingPeriod $period, string $code): JsonResponse
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:1000']]);

        return response()->json($this->checklist->complete($period, $code, $data['notes'] ?? null));
    }

    public function waiveItem(Request $request, AccountingPeriod $period, string $code): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return response()->json($this->checklist->waive($period, $code, $data['reason']));
    }

    public function resetItem(AccountingPeriod $period, string $code): JsonResponse
    {
        return response()->json($this->checklist->reset($period, $code));
    }
}
