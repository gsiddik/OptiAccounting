<?php

namespace App\Http\Controllers\Api\App\Delegation;

use App\Domain\Integration\Optinexus\Delegation\TenantDelegation;
use App\Http\Controllers\Api\App\AppController;
use App\Http\Requests\Delegation\RevokeDelegatedInvitationRequest;
use App\Http\Requests\Delegation\StoreDelegatedInvitationRequest;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Invitations of the current tenant in `optinexus` mode (contract §2b): OptiNexus owns them, this renders its answers and sends the Admin
 * Tenant's decisions. The invitation id in the path is only ever sent to OptiNexus under the session's tenant, so an id of another tenant is
 * answered `INVITATION_NOT_FOUND`.
 */
class DelegatedInvitationController extends AppController
{
    public function __construct(TenantContext $context, private readonly TenantDelegation $delegation)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['PENDING', 'ACCEPTED', 'REVOKED', 'EXPIRED'])],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json($this->delegation->invitations(
            $this->tenantId(), $request->user(), $filters['status'] ?? null, $filters['search'] ?? null, (int) ($filters['page'] ?? 1), (int) ($filters['per_page'] ?? 25),
        ));
    }

    public function store(StoreDelegatedInvitationRequest $request): JsonResponse
    {
        $input = $request->validated();
        $invitation = $this->delegation->invite($this->tenantId(), $request->user(), ['locale' => $request->invitationLocale()] + $input);

        return response()->json($invitation, 201);
    }

    public function resend(Request $request, string $invitation): JsonResponse
    {
        return response()->json($this->delegation->resendInvitation($this->tenantId(), $request->user(), $invitation));
    }

    public function revoke(RevokeDelegatedInvitationRequest $request, string $invitation): JsonResponse
    {
        return response()->json($this->delegation->revokeInvitation($this->tenantId(), $request->user(), $invitation, $request->validated()['reason'] ?? null));
    }
}
