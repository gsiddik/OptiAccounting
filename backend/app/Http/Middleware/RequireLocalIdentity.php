<?php

namespace App\Http\Middleware;

use App\Domain\Integration\Optinexus\OptinexusSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the screens that edit what OptiNexus owns in `optinexus` mode (members, roles, subscriptions,
 * entitlements). It states "read-only here"; it is not an authorization decision, so it never grants anything.
 */
class RequireLocalIdentity
{
    public function handle(Request $request, Closure $next): Response
    {
        if (OptinexusSettings::active()) {
            return response()->json([
                'message' => 'This is managed in OptiNexus in this installation.',
                'code' => 'MANAGED_BY_OPTINEXUS',
            ], 409);
        }

        return $next($request);
    }
}
