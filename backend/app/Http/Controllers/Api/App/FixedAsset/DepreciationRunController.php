<?php

namespace App\Http\Controllers\Api\App\FixedAsset;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\FixedAsset\Models\DepreciationRun;
use App\Domain\FixedAsset\Services\DepreciationRunService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Depreciation runs. Thin: validation here, every rule in DepreciationRunService. */
class DepreciationRunController extends AppController
{
    public function __construct(TenantContext $context, private readonly DepreciationRunService $runs, private readonly DocumentScope $scope)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in(['DRAFT', 'POSTED', 'CANCELLED', 'REVERSED'])], 'accounting_period_id' => ['nullable', 'uuid'],
            'posting_from' => ['nullable', 'date_format:Y-m-d'], 'posting_to' => ['nullable', 'date_format:Y-m-d'], 'q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return response()->json($this->runs->query($filter)->paginate($filter['per_page'] ?? 25));
    }

    public function show(Request $request, DepreciationRun $run): JsonResponse
    {
        return response()->json($this->present($this->visible($run), $request));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'accounting_period_id' => ['required', 'uuid'], 'posting_date' => ['nullable', 'date_format:Y-m-d'],
            'description' => ['nullable', 'string', 'max:500'], 'reference' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($this->present($this->runs->create($data, $request->user()), $request), 201);
    }

    public function cancel(Request $request, DepreciationRun $run): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->runs->cancel($this->visible($run), $request->user(), $data['reason']), $request));
    }

    public function post(Request $request, DepreciationRun $run): JsonResponse
    {
        return response()->json($this->present($this->runs->post($this->visible($run), $request->user()), $request));
    }

    public function reverse(Request $request, DepreciationRun $run): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'posting_date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->present($this->runs->reverse($this->visible($run), $request->user(), $data['reason'], $data['posting_date'] ?? null), $request));
    }

    private function visible(DepreciationRun $run): DepreciationRun
    {
        $this->scope->authorize($run);

        return $run;
    }

    private function present(DepreciationRun $run, Request $request): array
    {
        $run = $this->runs->load($run);

        return $run->toArray() + ['sod' => $this->runs->sod($run, $request->user()->id)];
    }
}
