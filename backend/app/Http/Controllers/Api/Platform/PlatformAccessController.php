<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Services\RoleService;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\Services\PlatformUserService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Platform users, platform roles and the platform permission list. */
class PlatformAccessController extends PlatformController
{
    public function __construct(
        TenantContext $context,
        private readonly PlatformUserService $users,
        private readonly RoleService $roles,
    ) {
        parent::__construct($context);
    }

    public function users(): JsonResponse
    {
        $rows = User::query()->whereHas('platformRoles')->with('platformRoles:id,name')->orderBy('name')
            ->get(['id', 'name', 'email', 'status', 'last_login_at']);

        return response()->json(['data' => $rows]);
    }

    public function storeUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', $this->passwordRule()],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['uuid'],
        ]);

        $user = $this->users->create($request->user(), $data, $data['role_ids']);

        return response()->json($user->load('platformRoles:id,name')->only(['id', 'name', 'email', 'status', 'platformRoles']), 201);
    }

    public function userRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['role_ids' => ['required', 'array', 'min:1'], 'role_ids.*' => ['uuid']]);

        return response()->json($this->users->setRoles($user, $request->user(), $data['role_ids'])->load('platformRoles:id,name'));
    }

    public function userStatus(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([User::ACTIVE, User::INACTIVE, User::SUSPENDED])]]);

        return response()->json($this->users->setStatus($user, $request->user(), $data['status'])->only(['id', 'status']));
    }

    public function roles(): JsonResponse
    {
        return response()->json(['data' => Role::query()->platform()->with('permissions:id,code')->orderBy('name')->get()]);
    }

    public function storeRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['required', 'array'],
            'permissions.*' => ['string'],
        ]);

        return response()->json($this->roles->create(Role::PLATFORM, null, $request->user(), $data['name'], $data['description'] ?? null, $data['permissions']), 201);
    }

    public function updateRole(Request $request, string $role): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ]);

        $row = Role::query()->platform()->findOrFail($role);

        return response()->json($this->roles->update($row, $request->user(), $data['name'] ?? null, $data['description'] ?? null, $data['permissions'] ?? null));
    }

    public function destroyRole(string $role): JsonResponse
    {
        $this->roles->delete(Role::query()->platform()->findOrFail($role));

        return response()->json(['message' => 'Role deleted.']);
    }

    public function permissions(): JsonResponse
    {
        return response()->json(['data' => Permission::query()->where('scope', 'platform')->orderBy('group')->orderBy('code')->get(['id', 'code', 'group', 'description'])]);
    }
}
