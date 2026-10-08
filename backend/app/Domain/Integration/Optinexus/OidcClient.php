<?php

namespace App\Domain\Integration\Optinexus;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use UnexpectedValueException;

/**
 * Relying-party side of OptiNexus SSO (authorization code + PKCE S256).
 *
 * State, nonce and PKCE verifier stay server-side in the cache keyed by the hash of the opaque `state`,
 * so the browser only carries the state; a state is single use. Every token is verified with a maintained
 * JWT library restricted to RS256 keys from the issuer's JWKS (signature, `kid`, `exp`), then `iss`, `aud`,
 * `iat` and (id_token) `nonce` are checked here. The id_token is finally cross-checked with /userinfo, which
 * re-validates the user's access at OptiNexus at that moment.
 */
class OidcClient
{
    public const EVENT_BACKCHANNEL_LOGOUT = 'http://schemas.openid.net/event/backchannel-logout';

    public const EVENT_ACCESS_REVOKED = 'https://schemas.optinexus.io/event/access-revoked';

    private const CLOCK_SKEW = 60;

    public function authorizationUrl(?string $tenantHint = null): string
    {
        $state = Str::random(40);
        $verifier = Str::random(64);
        $nonce = Str::random(32);

        Cache::put($this->stateKey($state), ['nonce' => $nonce, 'verifier' => $verifier], (int) config('optiaccounting.optinexus.sso.state_ttl_seconds'));

        return $this->discovery()['authorization_endpoint'].'?'.http_build_query(array_filter([
            'response_type' => 'code',
            'client_id' => OptinexusSettings::clientId(),
            'redirect_uri' => OptinexusSettings::redirectUri(),
            'scope' => 'openid profile email',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'tenant_hint' => $tenantHint,
        ]));
    }

    /**
     * @return array<string,mixed> verified claims (sub, email, tenant_id, apps, ...)
     *
     * @throws SsoException
     */
    public function completeLogin(string $code, string $state, ?string $issuer = null): array
    {
        $pending = Cache::pull($this->stateKey($state));
        if (! $pending) {
            throw new SsoException('state_invalid');
        }

        $discovery = $this->discovery();
        if ($issuer !== null && $issuer !== $discovery['issuer']) {
            throw new SsoException('id_token_invalid');
        }

        $timeout = (int) config('optiaccounting.optinexus.service.timeout_seconds');
        try {
            $response = Http::asForm()
                ->withBasicAuth(OptinexusSettings::clientId(), (string) config('optiaccounting.optinexus.sso.client_secret'))
                ->timeout($timeout)
                ->post($discovery['token_endpoint'], [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => OptinexusSettings::redirectUri(),
                    'code_verifier' => $pending['verifier'],
                ]);
        } catch (ConnectionException) {
            throw new SsoException('token_exchange_failed');
        }

        if (! $response->ok()) {
            throw new SsoException($response->json('error') === 'access_denied' ? 'access_denied' : 'token_exchange_failed');
        }

        $claims = $this->decode((string) $response->json('id_token'));
        $now = time();
        if ($claims === null
            || ($claims['iss'] ?? null) !== $discovery['issuer']
            || ! in_array(OptinexusSettings::clientId(), (array) ($claims['aud'] ?? []), true)
            || ! is_int($claims['exp'] ?? null) || $claims['exp'] < $now - self::CLOCK_SKEW
            || ! is_int($claims['iat'] ?? null) || $claims['iat'] > $now + self::CLOCK_SKEW
            || ! hash_equals($pending['nonce'], (string) ($claims['nonce'] ?? ''))
            || ! is_string($claims['sub'] ?? null) || $claims['sub'] === ''
            || ! is_string($claims['tenant_id'] ?? null) || $claims['tenant_id'] === '') {
            throw new SsoException('id_token_invalid');
        }

        try {
            $userinfo = Http::withToken((string) $response->json('access_token'))->timeout($timeout)->get($discovery['userinfo_endpoint']);
        } catch (ConnectionException) {
            throw new SsoException('token_exchange_failed');
        }
        if (! $userinfo->ok() || $userinfo->json('sub') !== $claims['sub'] || $userinfo->json('tenant_id') !== $claims['tenant_id']) {
            throw new SsoException('access_denied');
        }

        return $claims;
    }

    /**
     * Validates a logout token pushed by OptiNexus (OIDC Back-Channel Logout 1.0 §2.6): type, signature,
     * issuer, audience, freshness, the logout event, a subject, no nonce, and a `jti` not seen before.
     *
     * @return array<string,mixed>
     *
     * @throws SsoException
     */
    public function verifyLogoutToken(string $jwt): array
    {
        // An id_token (typ JWT) must never pass for a logout token.
        $header = json_decode((string) base64_decode(strtr(explode('.', $jwt)[0], '-_', '+/'), true), true);
        if (is_array($header) && isset($header['typ']) && strtolower((string) $header['typ']) !== 'logout+jwt') {
            throw new SsoException('logout_token_invalid');
        }

        $claims = $this->decode($jwt);
        $now = time();

        $valid = $claims !== null
            && ($claims['iss'] ?? null) === $this->discovery()['issuer']
            && in_array(OptinexusSettings::clientId(), (array) ($claims['aud'] ?? []), true)
            && is_int($claims['iat'] ?? null) && $claims['iat'] <= $now + self::CLOCK_SKEW && $claims['iat'] >= $now - 600
            && (! isset($claims['exp']) || (is_int($claims['exp']) && $claims['exp'] >= $now - self::CLOCK_SKEW))
            && is_array($claims['events'] ?? null) && array_key_exists(self::EVENT_BACKCHANNEL_LOGOUT, $claims['events'])
            && ! array_key_exists('nonce', $claims)
            && is_string($claims['sub'] ?? null) && $claims['sub'] !== ''
            && is_string($claims['jti'] ?? null) && $claims['jti'] !== '';

        if (! $valid) {
            throw new SsoException('logout_token_invalid');
        }

        if (! Cache::add('optinexus.logout.jti.'.hash('sha256', $claims['jti']), 1, 900)) {
            throw new SsoException('logout_token_replayed');
        }

        return $claims;
    }

    public function logoutUrl(): ?string
    {
        $endpoint = $this->discovery()['end_session_endpoint'] ?? null;

        // OptiNexus only honours the return address when it is registered on the client (manifest: post_logout_redirect_uris).
        return $endpoint ? $endpoint.'?'.http_build_query([
            'client_id' => OptinexusSettings::clientId(),
            'post_logout_redirect_uri' => rtrim((string) config('optiaccounting.optinexus.sso.frontend_url'), '/').'/login',
        ]) : null;
    }

    /** @return array<string,mixed> */
    public function discovery(): array
    {
        return Cache::remember('optinexus.discovery', 3600, function () {
            try {
                $response = Http::timeout((int) config('optiaccounting.optinexus.service.timeout_seconds'))
                    ->get(OptinexusSettings::baseUrl().'/.well-known/openid-configuration');
            } catch (ConnectionException $e) {
                throw new IdentityProviderUnavailable(Str::limit($e->getMessage(), 200));
            }
            if (! $response->ok() || ! is_string($response->json('issuer')) || ! is_string($response->json('jwks_uri'))) {
                throw new IdentityProviderUnavailable('Discovery document is unavailable.');
            }

            return $response->json();
        });
    }

    /**
     * Signature-verified claims, or null when the token is not an RS256 token signed by the issuer's current keys.
     * An unknown `kid` refetches the key set once (key rotation), at most every 30 seconds.
     *
     * @return array<string,mixed>|null
     */
    public function decode(string $jwt): ?array
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::CLOCK_SKEW;

        try {
            try {
                $claims = JWT::decode($jwt, JWK::parseKeySet($this->jwks(), 'RS256'));
            } catch (UnexpectedValueException $e) {
                if (! str_contains($e->getMessage(), '"kid"') || ! Cache::add('optinexus.jwks.refetch', 1, 30)) {
                    return null;
                }
                $claims = JWT::decode($jwt, JWK::parseKeySet($this->jwks(refresh: true), 'RS256'));
            }

            return json_decode((string) json_encode($claims), true);
        } catch (IdentityProviderUnavailable $e) {
            throw $e;
        } catch (Throwable) {
            return null;
        } finally {
            JWT::$leeway = $previousLeeway;
        }
    }

    /** @return array<string,mixed> */
    public function jwks(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget('optinexus.jwks');
        }

        return Cache::remember('optinexus.jwks', 300, function () {
            try {
                $response = Http::timeout((int) config('optiaccounting.optinexus.service.timeout_seconds'))->get($this->discovery()['jwks_uri']);
            } catch (ConnectionException $e) {
                throw new IdentityProviderUnavailable(Str::limit($e->getMessage(), 200));
            }
            if (! $response->ok() || ! is_array($response->json('keys'))) {
                throw new IdentityProviderUnavailable('JWKS is unavailable.');
            }

            return $response->json();
        });
    }

    private function stateKey(string $state): string
    {
        return 'optinexus.sso.state.'.hash('sha256', $state);
    }
}
