<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Identity\Services\MembershipService;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
        private readonly MembershipService $memberships,
    ) {}

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->auth->attempt($request->string('email')->toString(), $request->string('password')->toString());

        if (! $user) {
            $this->audit->record('auth.login_failed', 'user', null, null, ['email' => strtolower($request->string('email')->toString()), 'reason' => 'INVALID_CREDENTIALS']);

            return response()->json(['message' => 'These credentials do not match our records.', 'code' => 'INVALID_CREDENTIALS'], 422);
        }

        if (! $user->isActive()) {
            $this->audit->record('auth.login_failed', 'user', $user->id, null, ['email' => $user->email, 'reason' => 'USER_INACTIVE']);

            return response()->json(['message' => 'This user account is not active.', 'code' => 'USER_INACTIVE'], 403);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        $tenants = $this->auth->enterableTenants($user);
        $platform = $this->auth->hasPlatformAccess($user);

        // Exactly one place to go: enter it. Otherwise an identity token lets the user choose.
        if ($tenants->count() === 1 && ! $platform) {
            $scope = 'tenant';
            $ability = "tenant:{$tenants->first()->id}";
        } elseif ($tenants->isEmpty() && $platform) {
            $scope = 'platform';
            $ability = 'platform';
        } else {
            $scope = 'identity';
            $ability = 'identity';
        }

        $issued = $this->auth->issueToken($user, $ability);

        $this->context->setUser($user);
        $this->audit->record('auth.login', 'user', $user->id, tenantId: $scope === 'tenant' ? $tenants->first()->id : null);

        return response()->json([
            ...$issued,
            'token_type' => 'Bearer',
            'scope' => $scope,
            'tenant_id' => $scope === 'tenant' ? $tenants->first()->id : null,
            'user' => $user->only(['id', 'name', 'email']),
            'tenants' => $tenants,
            'platform_access' => $platform,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $abilities = $user->currentAccessToken()?->abilities ?? [];

        return response()->json([
            'user' => $user->only(['id', 'name', 'email']),
            'scope' => $this->context->isPlatform() ? 'platform' : ($this->context->tenantId() ? 'tenant' : 'identity'),
            'tenant_id' => $this->context->tenantId(),
            'tenants' => $this->auth->enterableTenants($user),
            'platform_access' => $this->auth->hasPlatformAccess($user),
            'abilities' => collect($abilities)->map(fn ($a) => str_starts_with($a, 'tenant:') ? 'tenant' : $a)->values(),
        ]);
    }

    public function switchTenant(Request $request): JsonResponse
    {
        $data = $request->validate(['tenant_id' => ['required', 'uuid']]);
        $issued = $this->auth->switchTenant($request->user(), $data['tenant_id'], $request->user()->currentAccessToken());

        if (! $issued) {
            return response()->json(['message' => 'You cannot enter this organization.', 'code' => 'TENANT_NOT_ENTERABLE'], 403);
        }

        return response()->json([...$issued, 'token_type' => 'Bearer', 'scope' => 'tenant', 'tenant_id' => $data['tenant_id']]);
    }

    public function switchPlatform(Request $request): JsonResponse
    {
        $issued = $this->auth->switchPlatform($request->user(), $request->user()->currentAccessToken());

        if (! $issued) {
            return response()->json(['message' => 'This account has no platform access.', 'code' => 'PLATFORM_NOT_ENTERABLE'], 403);
        }

        return response()->json([...$issued, 'token_type' => 'Bearer', 'scope' => 'platform', 'tenant_id' => null]);
    }

    public function acceptInvitation(Request $request): JsonResponse
    {
        $data = $request->validate(['tenant_id' => ['required', 'uuid']]);
        $this->memberships->acceptInvitation($request->user(), $data['tenant_id']);

        return response()->json(['message' => 'Invitation accepted.', 'tenants' => $this->auth->enterableTenants($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
