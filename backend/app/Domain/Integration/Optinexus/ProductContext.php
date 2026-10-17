<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Identity\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * What the signed-in person may open from here, and whether they may still be here at all (audit A29, docs/architecture/
 * OPTINEXUS_ADAPTER.md §11). One question to OptiNexus, POST /authorization/product-context (scope authorization.check):
 * the products the tenant subscribes to AND the person was assigned, never the governance portal, and `allowed` /
 * `deny_reason` for the caller's own session.
 *
 * The answer is kept per tenant + user for at most `ttl_seconds` (never more than 5 minutes). A cached answer is never used
 * after it expires: when OptiNexus cannot be asked then, the call fails (IdentityProviderUnavailable) and the screen shows no
 * products, it does not guess from an old list. A refusal (`allowed=false`) is never cached, so the person who is let back in
 * is judged afresh. Launch links are built here, so the browser never assembles a URL from data it was handed.
 */
final class ProductContext
{
    public const MAX_TTL_SECONDS = 300;

    private const SCHEMA_MAJOR = '1.';

    public function __construct(private readonly NexusApi $api) {}

    /**
     * @return array{available:false,reason:string}|array{available:true,allowed:bool,deny_reason:?string,tenant:?array{id:string,code:string,name:string},current:string,products:list<array<string,mixed>>,evaluated_at:string,expires_at:string,ttl_seconds:int,source:string}
     *
     * @throws IdentityProviderUnavailable
     */
    public function forMember(User $user, string $tenantId): array
    {
        if (! OptinexusSettings::active()) {
            return ['available' => false, 'reason' => 'NOT_OPTINEXUS'];
        }

        $nexusTenant = DB::table('tenants')->where('id', $tenantId)->value('optinexus_tenant_id');
        if (! $user->optinexus_subject || ! is_string($nexusTenant) || $nexusTenant === '') {
            return ['available' => false, 'reason' => 'NOT_LINKED'];
        }

        $key = self::cacheKey($tenantId, (string) $user->id);
        if ($hit = $this->fresh($key)) {
            return $hit + ['source' => 'cache'];
        }

        // One worker asks; the others wait for its answer instead of each asking at the moment the cache expires.
        $lock = Cache::lock($key.':fetch', 10);
        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            throw new IdentityProviderUnavailable('Product context lookup timed out.', transient: true);
        }

        try {
            if ($hit = $this->fresh($key)) {
                return $hit + ['source' => 'cache'];
            }

            return $this->ask($key, (string) $user->optinexus_subject, $nexusTenant) + ['source' => 'nexus'];
        } finally {
            $lock->release();
        }
    }

    /** Drops the remembered answer (a refusal ends the session; a revocation event bumps it too). */
    public function forget(string $tenantId, string $userId): void
    {
        Cache::forget(self::cacheKey($tenantId, $userId));
    }

    public static function cacheKey(string $tenantId, string $userId): string
    {
        return "optinexus:product-context:{$tenantId}:{$userId}";
    }

    /** @return array<string,mixed>|null */
    private function fresh(string $key): ?array
    {
        $entry = Cache::get($key);
        if (! is_array($entry) || ! isset($entry['expires_at']) || now()->getTimestamp() >= (int) $entry['expires_at']) {
            return null;
        }

        return $entry['answer'];
    }

    /** @return array<string,mixed> */
    private function ask(string $key, string $subject, string $nexusTenant): array
    {
        $response = $this->api->send('POST', '/authorization/product-context', ['user_id' => $subject, 'tenant_id' => $nexusTenant], repeatable: true);

        // An OptiNexus without the endpoint, or a service account that may not call it: nothing to show, nothing is ended.
        if (in_array($response->status(), [401, 403, 404], true)) {
            return ['available' => false, 'reason' => $response->status() === 404 ? 'NOT_SUPPORTED' : 'NOT_PERMITTED'];
        }

        $body = $this->api->data($response); // 429 / 5xx: IdentityProviderUnavailable, nothing cached
        $answer = $this->normalise($body, $subject, $nexusTenant);

        if (! $answer['allowed']) {
            Cache::forget($key);

            return $answer;
        }

        Cache::put($key, ['expires_at' => (int) strtotime($answer['expires_at']), 'answer' => $answer], $answer['ttl_seconds']);

        return $answer;
    }

    /**
     * Accepts only an answer about this user, this tenant and this application, in schema 1.x; anything else is not an answer.
     *
     * @param  array<string,mixed>  $body
     * @return array<string,mixed>
     */
    private function normalise(array $body, string $subject, string $nexusTenant): array
    {
        $schema = $body['schema_version'] ?? null;
        $allowed = $body['allowed'] ?? null;
        if (! is_string($schema) || ! str_starts_with($schema, self::SCHEMA_MAJOR) || ! is_bool($allowed)
            || ($body['user_id'] ?? null) !== $subject || ($body['application_code'] ?? null) !== OptinexusSettings::applicationCode()) {
            throw new IdentityProviderUnavailable('Unexpected product-context answer.');
        }

        $ttl = max(1, min(self::MAX_TTL_SECONDS, is_numeric($body['ttl_seconds'] ?? null) ? (int) $body['ttl_seconds'] : self::MAX_TTL_SECONDS));
        $tenant = is_array($body['tenant'] ?? null) ? $body['tenant'] : null;
        if ($allowed && (($tenant['id'] ?? null) !== $nexusTenant)) {
            throw new IdentityProviderUnavailable('Product-context answer is about another tenant.');
        }

        $now = now();
        $reason = $allowed ? null : (is_string($body['deny_reason'] ?? null) && $body['deny_reason'] !== '' ? $body['deny_reason'] : 'access_revoked');

        return [
            'available' => true,
            'allowed' => $allowed,
            'deny_reason' => $reason,
            'tenant' => $allowed ? ['id' => (string) $tenant['id'], 'code' => (string) ($tenant['code'] ?? ''), 'name' => (string) ($tenant['name'] ?? '')] : null,
            'current' => OptinexusSettings::applicationCode(),
            'products' => $allowed ? $this->products((array) ($body['products'] ?? []), $nexusTenant) : [],
            'evaluated_at' => $now->toIso8601String(),
            'expires_at' => $now->copy()->addSeconds($ttl)->toIso8601String(),
            'ttl_seconds' => $ttl,
        ];
    }

    /**
     * @param  array<int,mixed>  $rows
     * @return list<array<string,mixed>>
     */
    private function products(array $rows, string $nexusTenant): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['code'] ?? null) || $row['code'] === '' || ! is_string($row['name'] ?? null)) {
                continue;
            }
            if ($row['code'] === 'cgo') { // the governance portal is reached from OptiNexus, never from a product's switcher
                continue;
            }
            $current = $row['code'] === OptinexusSettings::applicationCode();
            $launch = self::safeUrl($row['launch_url'] ?? null);
            $out[] = [
                'code' => $row['code'],
                'name' => $row['name'],
                'current' => $current,
                'launch_url' => $launch,
                'switch_url' => $current || $launch === null ? null : self::withTenantHint($launch, $nexusTenant),
            ];
        }

        return $out;
    }

    /** An absolute http(s) address without credentials, or null. */
    public static function safeUrl(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > 2048) {
            return null;
        }
        $parts = parse_url($value);
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $value;
    }

    public static function withTenantHint(string $launchUrl, string $nexusTenant): string
    {
        $fragment = '';
        if (($pos = strpos($launchUrl, '#')) !== false) {
            $fragment = substr($launchUrl, $pos);
            $launchUrl = substr($launchUrl, 0, $pos);
        }
        $query = '';
        if (($pos = strpos($launchUrl, '?')) !== false) {
            $query = substr($launchUrl, $pos + 1);
            $launchUrl = substr($launchUrl, 0, $pos);
        }
        parse_str($query, $params);
        $params['tenant_hint'] = $nexusTenant;

        return $launchUrl.'?'.http_build_query($params).$fragment;
    }
}
