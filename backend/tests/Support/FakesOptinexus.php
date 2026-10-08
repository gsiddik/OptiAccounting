<?php

namespace Tests\Support;

use Firebase\JWT\JWT;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/**
 * An in-memory OptiNexus: OIDC provider (discovery, JWKS, token, userinfo), service-account token, authorization check,
 * commercial context, events and audit endpoints. Only the HTTP boundary is faked; every line of the adapter runs for real.
 * State lives in $this->nexus and can be changed by a test between calls (revoke a permission, take OptiNexus down, ...).
 */
trait FakesOptinexus
{
    protected const NEXUS_URL = 'https://nexus.test';

    protected const NEXUS_TENANT = '7b1c5d0e-2f55-4f3e-9a58-0d3d2a6c1111';

    protected const NEXUS_USER = '5a9d1f4e-7c3b-4b8a-8f60-2e1d9c0a2222';

    protected const FRONTEND = 'https://app.test';

    /** @var array<string,mixed> */
    protected array $nexus = [];

    private static ?array $nexusKeyPair = null;

    /** Switch the installation to optinexus mode against the fake and fake the HTTP boundary. */
    protected function nexusUp(): void
    {
        config([
            'optiaccounting.identity_mode' => 'optinexus',
            'optiaccounting.optinexus.base_url' => self::NEXUS_URL,
            'optiaccounting.optinexus.application_code' => 'optiaccounting',
            'optiaccounting.optinexus.sso.client_id' => 'oa-client',
            'optiaccounting.optinexus.sso.client_secret' => 'oa-secret',
            'optiaccounting.optinexus.sso.redirect_uri' => 'https://api.test/api/v1/auth/sso/callback',
            'optiaccounting.optinexus.sso.frontend_url' => self::FRONTEND,
            'optiaccounting.optinexus.service.client_id' => 'svc-client',
            'optiaccounting.optinexus.service.client_secret' => 'svc-secret',
            'optiaccounting.optinexus.provision_tenants' => true,
            'optiaccounting.optinexus.permission_ttl_seconds' => 300,
            'optiaccounting.optinexus.entitlement_ttl_seconds' => 300,
        ]);

        $this->nexus = [
            'down' => false,
            'tenants' => [self::NEXUS_TENANT => $this->nexusTenantState()],
            'grants' => [], // "{sub}|{tenant}" => [permission code => scope]
            'codes' => [],
            'requests' => [],
            'service_tokens' => 0,
            'service_scopes_ok' => true,
            'userinfo' => null, // override: array of claims to return
            'jwks_down' => false,
            'event_response' => [201, ['success' => true]],
            'audit_response' => [201, ['success' => true]],
            'signing_key' => 'primary',
        ];

        Http::preventStrayRequests();
        Http::fake(fn (Request $request) => $this->nexusRespond($request));
    }

    /** @return array<string,mixed> */
    protected function nexusTenantState(array $over = []): array
    {
        return $over + [
            'status' => 'ACTIVE',
            'applications' => ['optiaccounting'],
            'subscriptions' => [['id' => 'sub-1', 'status' => 'ACTIVE']],
            'entitlements' => [
                ...array_map(fn ($code) => ['entitlement_type' => 'CAPABILITY', 'entitlement_key' => $code, 'value' => true, 'source' => 'PLAN'],
                    DB::table('modules')->pluck('code')->all()),
                ['entitlement_type' => 'LIMIT', 'entitlement_key' => 'user_limit', 'value' => 10.0, 'source' => 'PLAN'],
            ],
        ];
    }

    /** Give a user permissions (default: every tenant permission of the catalog) in a tenant. */
    protected function nexusGrant(string $sub = self::NEXUS_USER, string $tenant = self::NEXUS_TENANT, string|array $permissions = '*', string $scope = 'TENANT'): void
    {
        $codes = $permissions === '*' ? DB::table('permissions')->where('scope', 'tenant')->pluck('code')->all() : (array) $permissions;
        $this->nexus['grants']["{$sub}|{$tenant}"] = array_fill_keys($codes, $scope);
    }

    /** Claims as OptiNexus puts them in a verified id_token. */
    protected function nexusClaims(array $over = []): array
    {
        return $over + [
            'sub' => self::NEXUS_USER, 'email' => 'siti@example.test', 'email_verified' => true, 'name' => 'Siti Aminah',
            'tenant_id' => self::NEXUS_TENANT, 'tenant_code' => 'ACME-ID', 'tenant_name' => 'PT Acme Indonesia',
            'groups' => ['optiaccounting'], 'apps' => [['code' => 'optiaccounting', 'name' => 'OptiAccounting', 'launch_url' => self::FRONTEND]],
        ];
    }

    /**
     * Runs the browser round trip as far as the callback and returns its redirect response. $claims override the
     * id_token claims; $idToken lets a test tamper with the token (iss/aud/exp/nonce/iat or a different signing key).
     *
     * @param  array<string,mixed>  $claims
     * @param  array<string,mixed>  $idToken
     */
    protected function nexusCallback(array $claims = [], array $idToken = []): TestResponse
    {
        $redirect = $this->get('/api/v1/auth/sso/redirect');
        $redirect->assertRedirectContains(self::NEXUS_URL.'/oidc/authorize');
        parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

        $code = 'code-'.(count($this->nexus['codes']) + 1);
        $this->nexus['codes'][$code] = [
            'claims' => $this->nexusClaims($claims),
            'nonce' => $query['nonce'],
            'challenge' => $query['code_challenge'],
            'tamper' => $idToken,
        ];

        return $this->get('/api/v1/auth/sso/callback?'.http_build_query(['code' => $code, 'state' => $query['state'], 'iss' => self::NEXUS_URL]));
    }

    /** Full sign-in: callback, then ticket exchange. Returns the exchange response. */
    protected function nexusSignIn(array $claims = [], array $idToken = []): TestResponse
    {
        $callback = $this->nexusCallback($claims, $idToken);
        $location = (string) $callback->headers->get('Location');
        $this->assertStringStartsWith(self::FRONTEND.'/sso/callback?ticket=', $location, "sign-in was refused: {$location}");
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $this->postJson('/api/v1/auth/sso/exchange', ['ticket' => $query['ticket']]);
    }

    protected function nexusRequests(string $path): array
    {
        return array_values(array_filter($this->nexus['requests'], fn ($r) => str_ends_with($r['path'], $path)));
    }

    /** A signed logout_token as OptiNexus sends it. */
    protected function nexusLogoutToken(array $over = [], bool $accessRevoked = false, array $revoked = []): string
    {
        $events = ['http://schemas.openid.net/event/backchannel-logout' => (object) []];
        if ($accessRevoked) {
            $events['https://schemas.optinexus.io/event/access-revoked'] = $revoked + ['reason' => 'user_disabled', 'scope' => 'user'];
        }

        return $this->nexusSign($over + [
            'iss' => self::NEXUS_URL, 'aud' => 'oa-client', 'iat' => time(), 'exp' => time() + 120, 'jti' => bin2hex(random_bytes(8)),
            'sub' => self::NEXUS_USER, 'events' => $events,
        ], 'logout+jwt');
    }

    // ---------------------------------------------------------------- the fake server

    private function nexusRespond(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        $this->nexus['requests'][] = ['method' => $request->method(), 'path' => $path, 'body' => $request->data(), 'auth' => $request->header('Authorization')[0] ?? null];

        if ($this->nexus['down']) {
            return Http::failedConnection('Nexus is down');
        }

        return match (true) {
            $path === '/.well-known/openid-configuration' => Http::response([
                'issuer' => self::NEXUS_URL, 'authorization_endpoint' => self::NEXUS_URL.'/oidc/authorize', 'token_endpoint' => self::NEXUS_URL.'/oidc/token',
                'userinfo_endpoint' => self::NEXUS_URL.'/oidc/userinfo', 'jwks_uri' => self::NEXUS_URL.'/oidc/jwks', 'end_session_endpoint' => self::NEXUS_URL.'/oidc/logout',
            ]),
            $path === '/oidc/jwks' => $this->nexus['jwks_down'] ? Http::response('', 500) : Http::response($this->nexusJwks()),
            $path === '/oidc/token' => $this->nexusToken($request),
            $path === '/oidc/userinfo' => $this->nexusUserinfo($request),
            $path === '/api/v1/oauth/token' => $this->nexusServiceToken($request),
            default => $this->nexusApi($request, $path),
        };
    }

    private function nexusToken(Request $request): Response|PromiseInterface
    {
        $form = $request->data();
        $code = $this->nexus['codes'][$form['code'] ?? ''] ?? null;
        $expectedAuth = 'Basic '.base64_encode('oa-client:oa-secret');

        if (! $code || ($request->header('Authorization')[0] ?? null) !== $expectedAuth || ($form['grant_type'] ?? null) !== 'authorization_code') {
            return Http::response(['error' => 'invalid_grant'], 400);
        }
        // PKCE: the verifier must hash to the challenge sent on the authorization request.
        $challenge = rtrim(strtr(base64_encode(hash('sha256', (string) ($form['code_verifier'] ?? ''), true)), '+/', '-_'), '=');
        if (! hash_equals($code['challenge'], $challenge) || ($form['redirect_uri'] ?? null) !== 'https://api.test/api/v1/auth/sso/callback') {
            return Http::response(['error' => 'invalid_grant'], 400);
        }

        // A test can override or remove (value '__unset__') any claim, or sign with another key ('__key').
        $tamper = $code['tamper'];
        if (isset($tamper['__raw'])) { // a ready-made (forged) token
            return Http::response(['token_type' => 'Bearer', 'access_token' => 'at-'.$form['code'], 'id_token' => $tamper['__raw']]);
        }
        $key = $tamper['__key'] ?? null;
        unset($tamper['__key']);
        $claims = $tamper + $code['claims'] + ['iss' => self::NEXUS_URL, 'aud' => 'oa-client', 'iat' => time(), 'exp' => time() + 300, 'nonce' => $code['nonce']];
        $claims = array_filter($claims, fn ($v) => $v !== '__unset__');

        return Http::response(['token_type' => 'Bearer', 'access_token' => 'at-'.$form['code'], 'id_token' => $this->nexusSign($claims, 'JWT', $key)]);
    }

    private function nexusUserinfo(Request $request): mixed
    {
        $token = substr((string) ($request->header('Authorization')[0] ?? ''), 7);
        $code = $this->nexus['codes'][substr($token, 3)] ?? null;
        if (! $code) {
            return Http::response(['error' => 'invalid_token'], 401);
        }

        return Http::response($this->nexus['userinfo'] ?? ['sub' => $code['claims']['sub'], 'tenant_id' => $code['claims']['tenant_id']]);
    }

    private function nexusServiceToken(Request $request): mixed
    {
        $form = $request->data();
        if (($form['client_id'] ?? null) !== 'svc-client' || ($form['client_secret'] ?? null) !== 'svc-secret' || ($form['grant_type'] ?? null) !== 'client_credentials') {
            return Http::response(['message' => 'invalid_client'], 401);
        }
        $this->nexus['service_tokens']++;

        return Http::response(['token_type' => 'Bearer', 'expires_in' => 3600, 'access_token' => 'service-token-'.$this->nexus['service_tokens']]);
    }

    private function nexusApi(Request $request, string $path): mixed
    {
        $auth = $request->header('Authorization')[0] ?? '';
        if (! str_starts_with($auth, 'Bearer service-token-')) {
            return Http::response(['success' => false, 'error' => ['code' => 'UNAUTHENTICATED']], 401);
        }
        if (! $this->nexus['service_scopes_ok']) {
            return Http::response(['success' => false, 'error' => ['code' => 'FORBIDDEN']], 403);
        }
        $body = $request->data();

        if ($path === '/api/v1/authorization/check') {
            if (($body['application_code'] ?? null) !== 'optiaccounting') {
                return Http::response(['success' => false, 'error' => ['code' => 'RESOURCE_NOT_FOUND']], 404);
            }
            $grants = $this->nexus['grants'][($body['user_id'] ?? '').'|'.($body['tenant_id'] ?? '')] ?? [];
            $code = substr((string) ($body['permission'] ?? ''), strlen('optiaccounting.'));
            $scope = $grants[$code] ?? null;

            return Http::response(['success' => true, 'data' => ['allowed' => $scope !== null, 'permission' => $body['permission'] ?? null, 'scope' => $scope]]);
        }

        if (preg_match('#^/api/v1/tenants/([^/]+)/commercial-context$#', $path, $m)) {
            $tenant = $this->nexus['tenants'][$m[1]] ?? null;
            if (! $tenant) {
                return Http::response(['success' => false, 'error' => ['code' => 'RESOURCE_NOT_FOUND']], 404);
            }

            return Http::response(['success' => true, 'data' => [
                'tenant_id' => $m[1], 'tenant_status' => $tenant['status'],
                'subscriptions' => $tenant['subscriptions'],
                'applications_enabled' => $tenant['applications'],
                'effective_entitlements' => $tenant['entitlements'],
            ]]);
        }

        if ($path === '/api/v1/events') {
            [$status, $payload] = $this->nexus['event_response'];

            return Http::response($payload, $status);
        }
        if ($path === '/api/v1/audit-events') {
            [$status, $payload] = $this->nexus['audit_response'];

            return Http::response($payload, $status);
        }

        return Http::response(['success' => false, 'error' => ['code' => 'NOT_FOUND']], 404);
    }

    // ---------------------------------------------------------------- keys and JWTs

    protected function nexusKeys(string $which = 'primary'): array
    {
        if (self::$nexusKeyPair === null) {
            self::$nexusKeyPair = [];
            foreach (['primary', 'rotated', 'attacker'] as $name) {
                $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
                openssl_pkey_export($key, $pem);
                $rsa = openssl_pkey_get_details($key)['rsa'];
                self::$nexusKeyPair[$name] = [
                    'pem' => $pem, 'kid' => "kid-{$name}",
                    'jwk' => ['kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => "kid-{$name}",
                        'n' => rtrim(strtr(base64_encode($rsa['n']), '+/', '-_'), '='), 'e' => rtrim(strtr(base64_encode($rsa['e']), '+/', '-_'), '=')],
                ];
            }
        }

        return self::$nexusKeyPair[$which];
    }

    /** The key set currently published; `signing_key` decides which key OptiNexus signs with (rotation tests). */
    private function nexusJwks(): array
    {
        $published = $this->nexus['published_keys'] ?? ['primary'];

        return ['keys' => array_map(fn ($name) => $this->nexusKeys($name)['jwk'], $published)];
    }

    private function nexusSign(array $claims, string $typ = 'JWT', ?string $key = null): string
    {
        $key = $this->nexusKeys($key ?? $this->nexus['signing_key']);

        return JWT::encode($claims, $key['pem'], 'RS256', $key['kid'], ['typ' => $typ]);
    }
}
