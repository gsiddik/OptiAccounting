<?php

namespace App\Domain\Integration\Optinexus;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * OptiEntry's machine-to-machine client of the OptiNexus API (client credentials). One platform-level
 * service account acts for every tenant; the tenant is named in the request body or path, never taken from a
 * browser. Any HTTP status is returned to the caller; only "could not talk to OptiNexus at all" raises
 * IdentityProviderUnavailable, so each caller decides what a 4xx means.
 */
class NexusApi
{
    public const SCOPES = 'authorization.check commercial.read event.write audit.write';

    private const TOKEN_KEY = 'optinexus.service.token';

    /** @param  array<string,mixed>  $data */
    public function send(string $method, string $path, array $data = []): Response
    {
        $response = $this->attempt($method, $path, $data);

        if ($response->status() === 401) { // expired or revoked token: renew once
            Cache::forget(self::TOKEN_KEY);
            $response = $this->attempt($method, $path, $data);
        }

        return $response;
    }

    /**
     * Sends independent POSTs concurrently (in chunks) and returns the responses by the keys given.
     *
     * @param  array<string,array<string,mixed>>  $bodies  key => JSON body
     * @return array<string,Response>
     */
    public function pool(string $path, array $bodies): array
    {
        $token = $this->token();
        $results = [];

        foreach (array_chunk($bodies, (int) config('optientry.optinexus.permission_pool_size'), true) as $chunk) {
            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk, $path, $token) {
                    foreach ($chunk as $key => $body) {
                        $this->decorate($pool->as((string) $key)->withToken($token))->post($this->url($path), $body);
                    }
                });
            } catch (ConnectionException $e) {
                throw new IdentityProviderUnavailable(Str::limit($e->getMessage(), 200));
            }

            foreach ($responses as $key => $response) {
                if ($response instanceof ConnectionException) {
                    throw new IdentityProviderUnavailable(Str::limit($response->getMessage(), 200));
                }
                $results[$key] = $response;
            }
        }

        return $results;
    }

    /**
     * The `data` member of a 2xx answer. Anything else means OptiNexus did not give a usable answer.
     *
     * @return array<string,mixed>
     */
    public function data(Response $response): array
    {
        if (! $response->successful()) {
            throw new IdentityProviderUnavailable("HTTP {$response->status()}");
        }

        return (array) ($response->json('data') ?? []);
    }

    private function attempt(string $method, string $path, array $data): Response
    {
        try {
            $request = $this->decorate(Http::withToken($this->token()));

            return strtoupper($method) === 'GET' ? $request->get($this->url($path), $data) : $request->send(strtoupper($method), $this->url($path), ['json' => $data]);
        } catch (ConnectionException $e) {
            throw new IdentityProviderUnavailable(Str::limit($e->getMessage(), 200));
        }
    }

    private function decorate(PendingRequest $request): PendingRequest
    {
        return $request->acceptJson()
            ->withHeaders(['X-Correlation-Id' => (string) Str::uuid()])
            ->timeout((int) config('optientry.optinexus.service.timeout_seconds'))
            ->withoutRedirecting();
    }

    private function url(string $path): string
    {
        return OptinexusSettings::baseUrl().'/api/v1'.$path;
    }

    private function token(): string
    {
        if ($missing = OptinexusSettings::missingForService()) {
            throw new IdentityProviderUnavailable('Service account is not configured: '.implode(', ', $missing));
        }

        $cached = Cache::get(self::TOKEN_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = Http::asForm()->timeout((int) config('optientry.optinexus.service.timeout_seconds'))
                ->post(OptinexusSettings::baseUrl().'/api/v1/oauth/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => config('optientry.optinexus.service.client_id'),
                    'client_secret' => config('optientry.optinexus.service.client_secret'),
                    'scope' => self::SCOPES,
                ]);
        } catch (ConnectionException $e) {
            throw new IdentityProviderUnavailable(Str::limit($e->getMessage(), 200));
        }

        $token = $response->json('access_token');
        if (! $response->ok() || ! is_string($token) || $token === '') {
            throw new IdentityProviderUnavailable("Service account authentication failed (HTTP {$response->status()}).");
        }

        $ttl = max(60, (int) ($response->json('expires_in') ?? 3600) - 120);
        Cache::put(self::TOKEN_KEY, $token, $ttl);

        return $token;
    }
}
