<?php

namespace App\Http\Controllers\Api\App\Accounting;

use App\Domain\Accounting\Services\OpeningBalanceService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The tenant's single, controlled opening balance (a balanced OPENING journal; see OpeningBalanceService). */
class OpeningBalanceController extends AppController
{
    public function __construct(TenantContext $context, private readonly OpeningBalanceService $openings)
    {
        parent::__construct($context);
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->openings->present($this->openings->current())]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cutover_date' => ['required', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:2', 'max:500'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.debit' => ['nullable'],
            'lines.*.credit' => ['nullable'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.reference' => ['nullable', 'string', 'max:100'],
            'lines.*.branch_id' => ['nullable', 'uuid'],
            'lines.*.business_unit_id' => ['nullable', 'uuid'],
            'lines.*.cost_center_id' => ['nullable', 'uuid'],
        ]);

        $opening = $this->openings->save($data, $request->user());

        return response()->json(['data' => $this->openings->present($opening)], 200);
    }

    public function post(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->openings->present($this->openings->post($request->user()))]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json(['data' => $this->openings->present($this->openings->cancel($request->user(), $data['reason']))]);
    }
}
