<?php

namespace App\Http\Controllers\Api\Integration;

use App\Domain\Integration\Optinexus\BackchannelLogoutService;
use App\Domain\Integration\Optinexus\IdentityProviderUnavailable;
use App\Domain\Integration\Optinexus\OidcClient;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use App\Domain\Integration\Optinexus\SsoException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Machine-to-machine receiver for OptiNexus. Authenticity comes from the signed token, not from a bearer token. */
class OptinexusController extends Controller
{
    public function __construct(
        private readonly OidcClient $oidc,
        private readonly BackchannelLogoutService $lifecycle,
    ) {}

    /** OIDC Back-Channel Logout 1.0 (+ OptiNexus access-revoked). 200 accepted, 400 invalid token (not retried), 503 retry. */
    public function backchannelLogout(Request $request): JsonResponse
    {
        $headers = ['Cache-Control' => 'no-store'];

        if (! OptinexusSettings::ssoEnabled()) {
            return response()->json(['error' => 'sso_disabled'], 400, $headers);
        }

        try {
            $claims = $this->oidc->verifyLogoutToken((string) $request->input('logout_token'));
        } catch (SsoException $e) {
            return response()->json(['error' => 'invalid_request', 'error_description' => $e->getMessage()], 400, $headers);
        } catch (IdentityProviderUnavailable) {
            // We could not fetch the signing keys: let OptiNexus retry instead of dropping a revocation.
            return response()->json(['error' => 'temporarily_unavailable'], 503, $headers);
        }

        $this->lifecycle->apply($claims);

        return response()->json((object) [], 200, $headers);
    }
}
