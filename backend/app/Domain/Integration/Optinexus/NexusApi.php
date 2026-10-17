<?php

namespace App\Domain\Integration\Optinexus;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
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

    /**
     * One call to OptiNexus with bounded recovery, so a short disturbance does not become an outage for the user:
     *  - 401: the cached token is dropped and renewed once;
     *  - 429: the request was not processed, so it is repeated after `Retry-After` (capped; a longer wait is not slept through
     *    but returned, see data()), at most `max_retries` times;
     *  - a connection error or 502/503/504, only when the caller says the call is safe to repeat (`$repeatable`): once, after a pause.
     * Every other status is returned as it is. Total waiting is bounded by `max_retries` x `max_retry_wait_seconds`.
     *
     * @param  array<string,mixed>  $data
     */
    public function send(string $method, string $path, array $data = [], bool $repeatable = false): Response
    {
        $max = max(0, (int) config('optientry.optinexus.service.max_retries'));
        $cap = max(1, (int) config('optientry.optinexus.service.max_retry_wait_seconds'));
        $renewed = false;
        $limited = 0;
        $transient = 0;

        while (true) {
            try {
                $response = $this->attempt($method, $path, $data);
            } catch (IdentityProviderUnavailable $e) {
                if (! $repeatable || $transient++ >= 1 || ! $e->transient) {
                    throw $e;
                }
                Sleep::for(1)->seconds();

                continue;
            }

            $status = $response->status();

            if ($status === 401 && ! $renewed) { // expired or revoked token: renew once
                Cache::forget(self::TOKEN_KEY);
                $renewed = true;

                continue;
            }

            if ($status === 429 && $limited < $max) {
                $wait = $this->retryAfter($response) ?? (2 ** $limited);
                if ($wait <= $cap) {
                    $limited++;
                    Sleep::for(max(1, $wait))->seconds();

                    continue;
                }
            }

            if ($repeatable && in_array($status, [502, 503, 504], true) && $transient++ < 1) {
                Sleep::for(1)->seconds();

                continue;
            }

            return $response;
        }
    }

    /** Seconds OptiNexus asked us to wait (`Retry-After`), or null when it did not say. */
    public function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(0, (int) $header) : null;
    }

    /**
     * The `data` member of a 2xx answer. Anything else means OptiNexus did not give a usable answer.
     *
     * @return array<string,mixed>
     */
    public function data(Response $response): array
    {
        if (! $response->successful()) {
            throw new IdentityProviderUnavailable("HTTP {$response->status()}", $response->status() === 429 ? ($this->retryAfter($response) ?? 60) : null, transient: $response->serverError());
        }

        return (array) ($response->json('data') ?? []);
    }

    private function attempt(string $method, string $path, array $data): Response
    {
        try {
            $request = $this->decorate(Http::withToken($this->token()));

            return strtoupper($method) === 'GET' ? $request->get($this->url($path), $data) : $request->send(strtoupper($method), $this->url($path), ['json' => $data]);
        } catch (ConnectionException $e) {
            throw new IdentityProviderUnavailable(Str::limit($e->getMessage(), 200), transient: true);
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

        // One worker renews the token; the others wait for it instead of each asking OptiNexus at the same moment.
        try {
            return Cache::lock(self::TOKEN_KEY.':renewal', 15)->block(10, function () {
                $cached = Cache::get(self::TOKEN_KEY);

                return is_string($cached) && $cached !== '' ? $cached : $this->requestToken();
            });
        } catch (LockTimeoutException) {
            throw new IdentityProviderUnavailable('Service account token renewal timed out.', transient: true);
        }
    }

    private function requestToken(): string
    {
        $max = max(0, (int) config('optientry.optinexus.service.max_retries'));
        $cap = max(1, (int) config('optientry.optinexus.service.max_retry_wait_seconds'));

        for ($attempt = 0; ; $attempt++) {
            try {
                $response = Http::asForm()->timeout((int) config('optientry.optinexus.service.timeout_seconds'))
                    ->post(OptinexusSettings::baseUrl().'/api/v1/oauth/token', [
                        'grant_type' => 'client_credentials',
                        'client_id' => config('optientry.optinexus.service.client_id'),
                        'client_secret' => config('optientry.optinexus.service.client_secret'),
                        'scope' => self::SCOPES,
                    ]);
            } catch (ConnectionException $e) {
                throw new IdentityProviderUnavailable(Str::limit($e->getMessage(), 200), transient: true);
            }

            $wait = $response->status() === 429 ? ($this->retryAfter($response) ?? (2 ** $attempt)) : null;
            if ($wait === null || $attempt >= $max || $wait > $cap) {
                break;
            }
            Sleep::for(max(1, $wait))->seconds();
        }

        $token = $response->json('access_token');
        if (! $response->ok() || ! is_string($token) || $token === '') {
            throw new IdentityProviderUnavailable(
                "Service account authentication failed (HTTP {$response->status()}).",
                $response->status() === 429 ? ($this->retryAfter($response) ?? 60) : null,
                transient: $response->serverError(),
            );
        }

        $ttl = max(60, (int) ($response->json('expires_in') ?? 3600) - 120);
        Cache::put(self::TOKEN_KEY, $token, $ttl);

        return $token;
    }
}
