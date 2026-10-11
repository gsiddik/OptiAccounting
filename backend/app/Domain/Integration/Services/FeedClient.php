<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Models\IntegrationConnection as Connection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Reads one page of the source's cursor feed (docs/architecture/INTEGRATION_ARCHITECTURE.md §7.1, §8):
 *
 *   GET {base_url}/api/v1/integration/accounting-feed?after={cursor}&limit=100      Authorization: Bearer {credential}
 *   200 {"data":[canonical envelope + sequence],"next_cursor":"...","has_more":false}
 *
 * `after` is left out for the first pull. Read-only, 15 second timeout, redirects are not followed (the credential never leaves the
 * configured host). Never call it inside a database transaction.
 */
class FeedClient
{
    public const PATH = '/api/v1/integration/accounting-feed';

    public const PAGE_SIZE = 100;

    public const TIMEOUT_SECONDS = 15;

    /** @return array{data:list<array<string,mixed>>,next_cursor:?string,has_more:bool} */
    public function fetch(Connection $connection, ?string $after): array
    {
        try {
            $response = Http::withToken((string) $connection->credential)->acceptJson()->timeout(self::TIMEOUT_SECONDS)->withoutRedirecting()
                ->get($connection->base_url.self::PATH, array_filter(['after' => $after, 'limit' => self::PAGE_SIZE], fn ($value) => $value !== null));
        } catch (ConnectionException $e) {
            throw new FeedException('The source could not be reached: '.Str::limit($e->getMessage(), 200, ''));
        }
        if (! $response->successful()) {
            throw new FeedException("The source answered HTTP {$response->status()}.");
        }

        $page = $response->json();
        $cursor = is_array($page) ? ($page['next_cursor'] ?? null) : null;
        if (! is_array($page) || ! is_array($page['data'] ?? null) || ! array_is_list($page['data']) || ! is_bool($page['has_more'] ?? null)
            || ($cursor !== null && ! is_string($cursor) && ! is_int($cursor))) {
            throw new FeedException('The source answered with an invalid feed page.');
        }

        return ['data' => $page['data'], 'next_cursor' => $cursor === null ? null : (string) $cursor, 'has_more' => $page['has_more']];
    }
}
