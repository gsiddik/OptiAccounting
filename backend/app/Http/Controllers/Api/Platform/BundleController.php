<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\ProductCatalog\Models\Bundle;
use App\Domain\ProductCatalog\Services\BundleService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BundleController extends PlatformController
{
    public function __construct(TenantContext $context, private readonly BundleService $bundles)
    {
        parent::__construct($context);
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => Bundle::query()->with(['modules:id,code,name', 'capacities'])->orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,59}$/', 'unique:bundles,code'],
            ...$this->rules(),
            'modules' => ['required', 'array', 'min:1'],
            'capacities' => ['nullable', 'array'],
        ]);

        $bundle = $this->bundles->create(
            collect($data)->only(['code', 'name', 'description'])->all(),
            $data['modules'],
            $this->capacities($data['capacities'] ?? []),
        );

        return response()->json($bundle, 201);
    }

    public function update(Request $request, Bundle $bundle): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
            'modules' => ['sometimes', 'array', 'min:1'],
            'modules.*' => ['string'],
            'capacities' => ['sometimes', 'array'],
        ]);

        $bundle = $this->bundles->update(
            $bundle,
            collect($data)->only(['name', 'description', 'status'])->all(),
            $data['modules'] ?? null,
            array_key_exists('capacities', $data) ? $this->capacities($data['capacities']) : null,
        );

        return response()->json($bundle);
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'modules.*' => ['string'],
        ];
    }

    /** @return array<string,?int> */
    private function capacities(array $input): array
    {
        $out = [];
        foreach ($input as $code => $value) {
            validator(['code' => $code, 'value' => $value], [
                'code' => [Rule::in(CapacityService::codes())],
                'value' => ['nullable', 'integer', 'min:0'],
            ])->validate();
            $out[$code] = $value === null ? null : (int) $value;
        }

        return $out;
    }
}
