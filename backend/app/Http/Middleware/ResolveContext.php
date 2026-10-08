<?php

namespace App\Http\Middleware;

use App\Domain\AccessControl\AccessDecision;
use App\Domain\AccessControl\AccessRequest;
use App\Domain\AccessControl\Services\EffectiveAccess;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves who is calling and for which tenant, from the token only
 * (abilities `identity`, `platform`, `tenant:<uuid>`). Client-supplied tenant
 * ids are never read here. Usage: `context:tenant|platform|any|session`.
 * `session` only identifies the token (user must be active) without evaluating tenant
 * state, so logout and tenant switching keep working for a suspended tenant.
 * Runs after auth:sanctum and enforces the user/tenant/membership gates.
 */
class ResolveContext
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EffectiveAccess $access,
    ) {}

    public function handle(Request $request, Closure $next, string $kind = 'any'): Response
    {
        $user = $request->user();
        if (! $user) {
            return $this->deny(AccessDecision::TOKEN_SCOPE, 401);
        }

        $abilities = $user->currentAccessToken()?->abilities ?? [];
        $tenantAbility = collect($abilities)->first(fn ($a) => is_string($a) && str_starts_with($a, 'tenant:'));
        $isPlatform = in_array('platform', $abilities, true);

        $this->context->setUser($user);
        $this->context->setTenant(null);
        $this->context->setPlatform(false);

        if ($kind === 'session') {
            if (! $user->isActive()) {
                return $this->deny(AccessDecision::USER_INACTIVE);
            }
            $this->context->setTenant($tenantAbility ? substr($tenantAbility, strlen('tenant:')) : null);
            $this->context->setPlatform($isPlatform);
        } elseif ($kind === 'tenant' || ($kind === 'any' && $tenantAbility)) {
            if (! $tenantAbility) {
                return $this->deny(AccessDecision::TOKEN_SCOPE);
            }
            $tenantId = substr($tenantAbility, strlen('tenant:'));
            $decision = $this->access->evaluate(new AccessRequest($user, $tenantId));
            if (! $decision->allowed) {
                return $this->deny($decision->code, $decision->httpStatus(), $decision->message());
            }
            $this->context->setTenant($tenantId);
        } elseif ($kind === 'platform' || ($kind === 'any' && $isPlatform)) {
            if (! $isPlatform) {
                return $this->deny(AccessDecision::TOKEN_SCOPE);
            }
            $decision = $this->access->evaluate(new AccessRequest($user, null));
            if (! $decision->allowed) {
                return $this->deny($decision->code, 403, $decision->message());
            }
            $this->context->setPlatform(true);
        } elseif (! $user->isActive()) {
            return $this->deny(AccessDecision::USER_INACTIVE);
        }

        return $next($request);
    }

    private function deny(string $code, int $status = 403, ?string $message = null): JsonResponse
    {
        return response()->json(['message' => $message ?? 'Access denied.', 'code' => $code], $status);
    }
}
