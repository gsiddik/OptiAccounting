<?php

namespace App\Http\Controllers\Api\App;

use App\Domain\AccessControl\Models\DataScope;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Services\MembershipService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Users of the current tenant = its memberships. */
class MemberController extends AppController
{
    public function __construct(TenantContext $context, private readonly MembershipService $memberships)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', 'string', 'max:20'], 'search' => ['nullable', 'string', 'max:100']]);
        $query = TenantUser::query()->with(['user:id,name,email,status', 'roles:id,name', 'dataScopes'])->orderByDesc('created_at');
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($search = trim($filters['search'] ?? '')) {
            $query->whereHas('user', fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('email', 'ilike', "%{$search}%"));
        }

        return response()->json($query->paginate(25));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['nullable', $this->passwordRule()],
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['uuid'],
            ...$this->scopeRules(),
        ]);

        $membership = $this->memberships->add(
            $this->tenantId(), $request->user(), collect($data)->only(['name', 'email', 'password'])->all(), $data['role_ids'], $data['data_scopes'] ?? [],
        );

        return response()->json($membership->load('user:id,name,email,status', 'roles:id,name', 'dataScopes'), 201);
    }

    public function status(Request $request, TenantUser $member): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([TenantUser::ACTIVE, TenantUser::SUSPENDED, TenantUser::INACTIVE])]]);

        return response()->json($this->memberships->setStatus($member, $request->user(), $data['status']));
    }

    public function roles(Request $request, TenantUser $member): JsonResponse
    {
        $data = $request->validate(['role_ids' => ['present', 'array'], 'role_ids.*' => ['uuid']]);

        return response()->json($this->memberships->setRoles($member, $request->user(), $data['role_ids'])->load('roles:id,name'));
    }

    public function scopes(Request $request, TenantUser $member): JsonResponse
    {
        $data = $request->validate($this->scopeRules(required: true));

        return response()->json($this->memberships->setDataScopes($member, $request->user(), $data['data_scopes'])->load('dataScopes'));
    }

    private function scopeRules(bool $required = false): array
    {
        return [
            'data_scopes' => [$required ? 'present' : 'sometimes', 'array'],
            'data_scopes.*.scope_type' => ['required', Rule::in(DataScope::TYPES)],
            'data_scopes.*.branch_id' => ['nullable', 'uuid'],
            'data_scopes.*.business_unit_id' => ['nullable', 'uuid'],
        ];
    }
}
