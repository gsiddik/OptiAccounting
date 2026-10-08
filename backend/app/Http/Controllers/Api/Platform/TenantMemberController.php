<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use App\Domain\Identity\Services\MembershipService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Platform view of a tenant's memberships (suspend/reactivate for support and abuse cases). */
class TenantMemberController extends PlatformController
{
    public function __construct(TenantContext $context, private readonly MembershipService $memberships)
    {
        parent::__construct($context);
    }

    public function index(Tenant $tenant): JsonResponse
    {
        $rows = $this->inTenant($tenant, fn () => TenantUser::query()->with(['user:id,name,email,status', 'roles:id,name'])
            ->orderByDesc('created_at')->paginate(25));

        return response()->json($rows);
    }

    public function status(Request $request, Tenant $tenant, string $membership): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([TenantUser::ACTIVE, TenantUser::SUSPENDED, TenantUser::INACTIVE])]]);

        $result = $this->inTenant($tenant, function () use ($membership, $data, $request) {
            $row = TenantUser::query()->findOrFail($membership);

            return $this->memberships->setStatus($row, $request->user(), $data['status']);
        });

        return response()->json($result);
    }
}
