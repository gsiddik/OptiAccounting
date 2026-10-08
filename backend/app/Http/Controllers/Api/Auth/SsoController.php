<?php

namespace App\Http\Controllers\Api\Auth;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Services\AuthService;
use App\Domain\Integration\Optinexus\IdentityProviderUnavailable;
use App\Domain\Integration\Optinexus\OidcClient;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use App\Domain\Integration\Optinexus\SsoException;
use App\Domain\Integration\Optinexus\SsoLoginService;
use App\Http\Controllers\Controller;
use App\Support\IdentityMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Sign in with OptiNexus" (identity mode `optinexus`).
 *
 * Browser flow: GET /auth/sso/redirect -> OptiNexus -> GET /auth/sso/callback -> SPA /sso/callback?ticket=...
 * -> POST /auth/sso/exchange (one-time ticket -> session token). The token never travels in a URL.
 */
class SsoController extends Controller
{
    public function __construct(
        private readonly OidcClient $oidc,
        private readonly SsoLoginService $login,
        private readonly AuthService $auth,
        private readonly AuditService $audit,
    ) {}

    /** Public; tells the login page which doors exist. No secrets. */
    public function status(): JsonResponse
    {
        return response()->json([
            'identity_mode' => IdentityMode::current(),
            'sso_enabled' => OptinexusSettings::ssoEnabled(),
            'password_login' => ! OptinexusSettings::active() || (bool) config('optiaccounting.optinexus.break_glass_login'),
        ]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        if (! OptinexusSettings::ssoEnabled()) {
            return $this->failure('sso_disabled');
        }

        try {
            return redirect()->away($this->oidc->authorizationUrl($request->query('tenant_hint') ? (string) $request->query('tenant_hint') : null));
        } catch (IdentityProviderUnavailable) {
            return $this->failure('sso_unavailable');
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        if (! OptinexusSettings::ssoEnabled()) {
            return $this->failure('sso_disabled');
        }

        if ($request->query('error')) {
            return $this->failure($request->query('error') === 'access_denied' ? 'access_denied' : 'sso_failed');
        }

        $code = (string) $request->query('code');
        $state = (string) $request->query('state');
        if ($code === '' || $state === '') {
            return $this->failure('sso_failed');
        }

        try {
            $claims = $this->oidc->completeLogin($code, $state, $request->query('iss') ? (string) $request->query('iss') : null);
        } catch (SsoException $e) {
            return $this->failure($e->getMessage());
        } catch (IdentityProviderUnavailable) {
            return $this->failure('sso_unavailable');
        } catch (\Throwable $e) {
            report($e);

            return $this->failure('sso_failed');
        }

        try {
            $ticket = $this->login->ticketFor($claims);
        } catch (SsoException $e) {
            // The claims are verified and authentic here, so a refusal is worth a record.
            $this->audit->record('auth.sso_refused', 'user', null, null, ['reason' => $e->getMessage(), 'optinexus_subject' => $claims['sub'] ?? null]);

            return $this->failure($e->getMessage());
        } catch (IdentityProviderUnavailable) {
            return $this->failure('sso_unavailable');
        } catch (\Throwable $e) {
            report($e);

            return $this->failure('sso_failed');
        }

        return redirect()->away(config('optiaccounting.optinexus.sso.frontend_url').'/sso/callback?'.http_build_query(['ticket' => $ticket]));
    }

    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate(['ticket' => ['required', 'string', 'max:128']]);

        $session = OptinexusSettings::ssoEnabled() ? $this->login->redeemTicket($data['ticket']) : null;
        if (! $session) {
            return response()->json(['message' => 'This sign-in link is invalid or has expired. Please sign in again.', 'code' => 'INVALID_TICKET'], 422);
        }

        ['user' => $user, 'tenant' => $tenant] = $session;
        $issued = $this->auth->issueToken($user, "tenant:{$tenant->id}");

        try {
            $logoutUrl = $this->oidc->logoutUrl();
        } catch (IdentityProviderUnavailable) {
            $logoutUrl = null;
        }

        return response()->json([
            ...$issued,
            'token_type' => 'Bearer',
            'scope' => 'tenant',
            'tenant_id' => $tenant->id,
            'user' => $user->only(['id', 'name', 'email']),
            'tenants' => $this->auth->enterableTenants($user),
            'platform_access' => $this->auth->hasPlatformAccess($user),
            'sso' => ['logout_url' => $logoutUrl],
        ]);
    }

    private function failure(string $code): RedirectResponse
    {
        return redirect()->away(config('optiaccounting.optinexus.sso.frontend_url').'/login?'.http_build_query(['sso_error' => $code]));
    }
}
