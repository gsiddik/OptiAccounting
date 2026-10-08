<?php

namespace App\Http\Controllers\Api\App;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\RoleService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends AppController
{
    public function __construct(TenantContext $context, private readonly RoleService $roles)
    {
        parent::__construct($context);
    }

    public function index(): JsonResponse
    {
        $rows = Role::query()->forTenant($this->tenantId())->with('permissions:id,code')->orderBy('name')->get();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string'],
        ]);

        $role = $this->roles->create(Role::TENANT, $this->tenantId(), $request->user(), $data['name'], $data['description'] ?? null, $data['permissions']);

        return response()->json($role, 201);
    }

    public function update(Request $request, string $role): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ]);

        $row = Role::query()->forTenant($this->tenantId())->findOrFail($role);

        return response()->json($this->roles->update($row, $request->user(), $data['name'] ?? null, $data['description'] ?? null, $data['permissions'] ?? null));
    }

    public function destroy(string $role): JsonResponse
    {
        $this->roles->delete(Role::query()->forTenant($this->tenantId())->findOrFail($role));

        return response()->json(['message' => 'Role deleted.']);
    }

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => Permission::query()->where('scope', 'tenant')->orderBy('group')->orderBy('code')->get(['id', 'code', 'group', 'description'])]);
    }
}
