<?php

namespace App\Http\Controllers\Api\App\Delegation;

use App\Domain\Integration\Optinexus\Delegation\TenantDelegation;
use App\Http\Controllers\Api\App\AppController;
use App\Http\Requests\Delegation\SetDelegatedUserRolesRequest;
use App\Http\Requests\Delegation\StoreDelegatedUserRequest;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Users of the current tenant in `optinexus` mode: the answers and the changes of OptiNexus (docs/architecture/OPTINEXUS_ADAPTER.md §10). */
class DelegatedUserController extends AppController
{
    public function __construct(TenantContext $context, private readonly TenantDelegation $delegation)
    {
        parent::__construct($context);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json($this->delegation->status($this->tenantId(), $request->user()));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json($this->delegation->users($this->tenantId(), $request->user(), $filters['search'] ?? null, (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 25)));
    }

    public function store(StoreDelegatedUserRequest $request): JsonResponse
    {
        $result = $this->delegation->addUser($this->tenantId(), $request->user(), $request->validated());

        return response()->json($result, $result['outcome'] === 'created' ? 201 : 200);
    }

    public function roles(SetDelegatedUserRolesRequest $request, string $subject): JsonResponse
    {
        return response()->json($this->delegation->setUserRoles($this->tenantId(), $request->user(), $subject, $request->validated()['role_ids']));
    }

    public function destroy(Request $request, string $subject): JsonResponse
    {
        $this->delegation->removeUser($this->tenantId(), $request->user(), $subject);

        return response()->json(['message' => 'Access removed.']);
    }
}
