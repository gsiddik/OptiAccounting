<?php

namespace App\Http\Middleware;

use App\Domain\AccessControl\AccessRequest;
use App\Domain\AccessControl\Services\EffectiveAccess;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route gate backed by the EffectiveAccess resolver:
 *   access:accounting.journal.view,module=ACCOUNTING_CORE,feature=JOURNAL
 * Mutating HTTP methods are automatically denied for READ_ONLY entitlements.
 */
class RequireAccess
{
    public function __construct(
        private readonly EffectiveAccess $access,
        private readonly TenantContext $context,
    ) {}

    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        $user = $this->context->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.', 'code' => 'UNAUTHENTICATED'], 401);
        }

        $permission = $module = $feature = null;
        foreach ($params as $param) {
            if (str_starts_with($param, 'module=')) {
                $module = substr($param, 7);
            } elseif (str_starts_with($param, 'feature=')) {
                $feature = substr($param, 8);
            } else {
                $permission = $param;
            }
        }

        $decision = $this->access->evaluate(new AccessRequest(
            user: $user,
            tenantId: $this->context->isPlatform() ? null : $this->context->tenantId(),
            permission: $permission,
            module: $module,
            feature: $feature,
            mutating: ! $request->isMethodSafe(),
        ));

        if (! $decision->allowed) {
            return response()->json(['message' => $decision->message(), 'code' => $decision->code], $decision->httpStatus());
        }

        return $next($request);
    }
}
