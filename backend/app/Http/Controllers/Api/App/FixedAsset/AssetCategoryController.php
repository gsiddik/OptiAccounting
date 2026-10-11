<?php

namespace App\Http\Controllers\Api\App\FixedAsset;

use App\Domain\FixedAsset\Models\AssetCategory;
use App\Domain\FixedAsset\Services\AssetCategoryService;
use App\Domain\FixedAsset\Services\Depreciation\DepreciationMethods;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Asset categories. Thin: validation here, every rule in AssetCategoryService. */
class AssetCategoryController extends AppController
{
    public function __construct(TenantContext $context, private readonly AssetCategoryService $categories)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(['status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])], 'q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);

        return response()->json($this->categories->query($filter)->paginate($filter['per_page'] ?? 50));
    }

    public function show(AssetCategory $category): JsonResponse
    {
        return response()->json($this->categories->load($category));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->categories->create($this->payload($request, true), $request->user()), 201);
    }

    public function update(Request $request, AssetCategory $category): JsonResponse
    {
        return response()->json($this->categories->update($category, $this->payload($request, false)));
    }

    public function activate(AssetCategory $category): JsonResponse
    {
        return response()->json($this->categories->setStatus($category, AssetCategory::ACTIVE));
    }

    public function deactivate(AssetCategory $category): JsonResponse
    {
        return response()->json($this->categories->setStatus($category, AssetCategory::INACTIVE));
    }

    public function destroy(AssetCategory $category): JsonResponse
    {
        $this->categories->delete($category);

        return response()->json(null, 204);
    }

    /** @return array<string,mixed> */
    private function payload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$req, 'string', 'max:30'], 'name' => [$req, 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:500'],
            'asset_account_id' => ['nullable', 'uuid'], 'accumulated_account_id' => ['nullable', 'uuid'], 'expense_account_id' => ['nullable', 'uuid'], 'gain_loss_account_id' => ['nullable', 'uuid'],
            'default_method' => ['sometimes', Rule::in(DepreciationMethods::codes())], 'default_useful_life_months' => ['nullable', 'integer', 'between:1,1200'],
            'default_residual_type' => ['sometimes', Rule::in(['NONE', 'AMOUNT', 'PERCENT'])], 'default_residual_value' => ['nullable'],
            'default_start_policy' => ['sometimes', Rule::in(['CAPITALIZATION_MONTH', 'NEXT_MONTH'])],
        ]);
    }
}
