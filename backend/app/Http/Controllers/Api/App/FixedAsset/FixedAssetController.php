<?php

namespace App\Http\Controllers\Api\App\FixedAsset;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\FixedAsset\Models\FixedAsset;
use App\Domain\FixedAsset\Services\Depreciation\DepreciationMethods;
use App\Domain\FixedAsset\Services\FixedAssetService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The asset register. Thin: validation here, every rule in FixedAssetService. An asset outside the user's data scope is a 404. */
class FixedAssetController extends AppController
{
    public function __construct(TenantContext $context, private readonly FixedAssetService $assets, private readonly DocumentScope $scope)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in(['DRAFT', 'ACTIVE', 'FULLY_DEPRECIATED', 'DISPOSED', 'INACTIVE'])], 'asset_category_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'],
            'capitalized_from' => ['nullable', 'date_format:Y-m-d'], 'capitalized_to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $filter['mine'] = $request->boolean('mine');

        return response()->json($this->assets->query($filter)->paginate($filter['per_page'] ?? 25));
    }

    public function show(Request $request, FixedAsset $asset): JsonResponse
    {
        return response()->json($this->present($this->visible($asset), $request));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->present($this->assets->create($this->payload($request, true), $request->user()), $request), 201);
    }

    public function update(Request $request, FixedAsset $asset): JsonResponse
    {
        $this->visible($asset);

        return response()->json($this->present($this->assets->update($asset, $this->payload($request, false), $request->user()), $request));
    }

    public function schedule(FixedAsset $asset): JsonResponse
    {
        return response()->json($this->assets->schedule($this->visible($asset)));
    }

    public function discard(Request $request, FixedAsset $asset): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->assets->discard($this->visible($asset), $request->user(), $data['reason']), $request));
    }

    public function capitalize(Request $request, FixedAsset $asset): JsonResponse
    {
        return response()->json($this->present($this->assets->capitalize($this->visible($asset), $request->user()), $request));
    }

    public function reverseCapitalization(Request $request, FixedAsset $asset): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'posting_date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->present($this->assets->reverseCapitalization($this->visible($asset), $request->user(), $data['reason'], $data['posting_date'] ?? null), $request));
    }

    private function visible(FixedAsset $asset): FixedAsset
    {
        $this->scope->authorize($asset);

        return $asset;
    }

    private function present(FixedAsset $asset, Request $request): array
    {
        $asset = $this->assets->load($asset);

        return $asset->toArray() + ['sod' => $this->assets->sod($asset, $request->user()->id)];
    }

    /** @return array<string,mixed> */
    private function payload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'asset_category_id' => [$req, 'uuid'], 'name' => [$req, 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:500'],
            'acquisition_date' => [$req, 'date_format:Y-m-d'], 'capitalization_date' => ['nullable', 'date_format:Y-m-d'],
            'acquisition_cost' => [$req], 'residual_value' => ['nullable'],
            'useful_life_months' => ['nullable', 'integer', 'between:1,1200'], 'method' => ['sometimes', Rule::in(DepreciationMethods::codes())], 'method_params' => ['nullable', 'array'],
            'start_policy' => ['sometimes', Rule::in(['CAPITALIZATION_MONTH', 'NEXT_MONTH'])],
            'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'],
            'capitalization_mode' => ['sometimes', Rule::in(['POST', 'REGISTER_ONLY'])], 'source_account_id' => ['nullable', 'uuid'], 'ap_invoice_line_id' => ['nullable', 'uuid'],
            'source_reference' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
