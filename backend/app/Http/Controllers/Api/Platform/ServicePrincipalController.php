<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Identity\Models\ServicePrincipal;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Services\ServicePrincipalKinds;
use App\Domain\Identity\Services\ServicePrincipalRegistry;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The service identities (machine accounts) of tenants, for the platform operator only. Tenants never reach these routes (platform scope) and
 * no body field decides who is a machine: the registry row is the decision, written here or by the issue command, never edited afterwards.
 */
class ServicePrincipalController extends PlatformController
{
    public function __construct(TenantContext $context, private readonly ServicePrincipalRegistry $registry)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['tenant_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in(['live', 'revoked'])]]);
        $live = match ($filters['status'] ?? null) {
            'live' => true,
            'revoked' => false,
            default => null,
        };

        return response()->json([
            'data' => $this->registry->list($filters['tenant_id'] ?? null, $live)->map(fn (ServicePrincipal $p) => $this->present($p))->values(),
            'kinds' => ServicePrincipalKinds::all(),
            'unregistered_reserved_addresses' => $this->registry->unregisteredReservedUsers()->map(fn ($u) => ['user_id' => $u->id, 'email' => $u->email, 'status' => $u->status])->values(),
        ]);
    }

    /** Issue the identity of a tenant (idempotent: 201 when created, 200 when the tenant already had a live one of the kind). */
    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['kind' => ['required', 'string', Rule::in(ServicePrincipalKinds::all())], 'reason' => ['required', 'string', 'max:500']]);

        $result = $this->registry->issue($tenant, $data['kind'], $data['reason'], 'PLATFORM_API', $request->user());

        return response()->json($this->present($result['principal']->load('user:id,email,status', 'tenant:id,code,name')), $result['created'] ? 201 : 200);
    }

    public function revoke(Request $request, string $principal): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $row = ServicePrincipal::query()->findOrFail($principal);

        $result = $this->registry->revoke($row, $data['reason'], $request->user());

        return response()->json($this->present($result['principal']->load('user:id,email,status', 'tenant:id,code,name')));
    }

    /** @return array<string,mixed> */
    private function present(ServicePrincipal $p): array
    {
        return [
            'id' => $p->id, 'status' => $p->isRevoked() ? 'REVOKED' : 'LIVE', 'kind' => $p->kind, 'source_system' => $p->source_system,
            'tenant' => $p->tenant ? ['id' => $p->tenant->id, 'code' => $p->tenant->code, 'name' => $p->tenant->name] : ['id' => $p->tenant_id],
            'user' => $p->user ? ['id' => $p->user->id, 'email' => $p->user->email, 'status' => $p->user->status] : ['id' => $p->user_id],
            'issued_at' => $p->issued_at?->toIso8601String(), 'issued_via' => $p->issued_via, 'issued_by' => $p->issued_by_label, 'reason' => $p->reason,
            'revoked_at' => $p->revoked_at?->toIso8601String(), 'revoked_by' => $p->revoked_by_label, 'revoke_reason' => $p->revoke_reason,
        ];
    }
}
