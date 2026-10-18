<?php

namespace App\Http\Controllers\Api\App\Delegation;

use App\Domain\Integration\Optinexus\Delegation\TenantDelegation;
use App\Http\Controllers\Api\App\AppController;
use App\Http\Requests\Delegation\SaveDelegatedRoleRequest;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Custom roles of the current tenant in `optinexus` mode, created inside the delegable permission set that OptiNexus enforces. */
class DelegatedRoleController extends AppController
{
    public function __construct(TenantContext $context, private readonly TenantDelegation $delegation)
    {
        parent::__construct($context);
    }

    /** Roles (templates included) and the permission catalog with the state of each permission. */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->delegation->catalog($this->tenantId(), $request->user()));
    }

    /** Roles only, without their permissions: what the user screens need to assign a role. */
    public function assignable(Request $request): JsonResponse
    {
        $roles = array_map(
            fn (array $role) => ['id' => $role['id'], 'code' => $role['code'], 'name' => $role['name'], 'description' => $role['description'], 'template' => $role['template']],
            $this->delegation->catalog($this->tenantId(), $request->user())['roles'],
        );

        return response()->json(['data' => $roles]);
    }

    public function store(SaveDelegatedRoleRequest $request): JsonResponse
    {
        return response()->json($this->delegation->createRole($this->tenantId(), $request->user(), $request->validated()), 201);
    }

    public function update(SaveDelegatedRoleRequest $request, string $role): JsonResponse
    {
        return response()->json($this->delegation->updateRole($this->tenantId(), $request->user(), $role, $request->validated()));
    }

    public function destroy(Request $request, string $role): JsonResponse
    {
        $this->delegation->deleteRole($this->tenantId(), $request->user(), $role);

        return response()->json(['message' => 'Role deleted.']);
    }
}
