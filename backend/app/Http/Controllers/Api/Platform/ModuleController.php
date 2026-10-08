<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\ProductCatalog\Models\Feature;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\ProductCatalog\Services\ModuleCatalogService;
use App\Domain\ProductCatalog\Services\ModuleDependencyService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Module catalog, dependencies and features (all data-driven). */
class ModuleController extends PlatformController
{
    public function __construct(
        TenantContext $context,
        private readonly ModuleCatalogService $catalog,
        private readonly ModuleDependencyService $dependencies,
    ) {
        parent::__construct($context);
    }

    public function index(): JsonResponse
    {
        $modules = Module::query()->with(['features', 'requires:id,code,name'])->orderBy('sort_order')->orderBy('code')->get();

        return response()->json(['data' => $modules]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,59}$/', 'unique:modules,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'commercially_available' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        return response()->json($this->catalog->createModule($data)->load('features', 'requires:id,code,name'), 201);
    }

    public function update(Request $request, Module $module): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'commercially_available' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        return response()->json($this->catalog->updateModule($module, $data)->load('features', 'requires:id,code,name'));
    }

    public function status(Request $request, Module $module): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([Module::ACTIVE, Module::INACTIVE])]]);

        return response()->json($this->catalog->setModuleStatus($module, $data['status']));
    }

    public function addDependency(Request $request, Module $module): JsonResponse
    {
        $data = $request->validate(['requires' => ['required', 'string', 'exists:modules,code']]);
        $this->dependencies->add($module, Module::query()->where('code', $data['requires'])->firstOrFail());

        return response()->json($module->load('requires:id,code,name'), 201);
    }

    public function removeDependency(Module $module, Module $required): JsonResponse
    {
        $this->dependencies->remove($module, $required);

        return response()->json($module->load('requires:id,code,name'));
    }

    public function storeFeature(Request $request, Module $module): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9_]{1,59}$/', 'unique:features,code'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        return response()->json($this->catalog->createFeature($module, $data), 201);
    }

    public function updateFeature(Request $request, Feature $feature): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        return response()->json($this->catalog->updateFeature($feature, $data));
    }
}
