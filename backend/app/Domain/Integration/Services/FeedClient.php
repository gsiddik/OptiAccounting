<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Models\IntegrationConnection as Connection;
use App\Domain\Shared\DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Reads one page of the source's cursor feed (docs/architecture/INTEGRATION_ARCHITECTURE.md §7.1, §8):
 *
 *   GET {base_url}/api/v1/integration/accounting-feed?after={cursor}&limit=100      Authorization: Bearer {credential}
 *   200 {"data":[canonical envelope + sequence],"next_cursor":"...","has_more":false,"high_water":"..."}
 *
 * `after` is left out for the first pull. Read-only, 15 second timeout, redirects are not followed (the credential never leaves the
 * configured host). Never call it inside a database transaction.
 *
 * The page is validated against the contract of a gap-free, commit-ordered sequence feed (ecosystem audit A22) before anything is stored, so
 * a malformed page can never move the cursor: the cursor and every `sequence` are digit strings that fit a 64-bit integer; the page holds at
 * most the requested number of events; the sequences are exactly after+1, after+2, ... with no gap and no repeat; `next_cursor` is the last
 * sequence (an empty page does not advance: `next_cursor` is `after` or absent and `has_more` is false); `has_more` is a boolean; and,
 * when the source states its `high_water` (the newest committed sequence), nothing lies beyond it.
 */
class FeedClient
{
    public const PATH = '/api/v1/integration/accounting-feed';

    public const PAGE_SIZE = 100;

    public const TIMEOUT_SECONDS = 15;

    /** @return array{data:list<array<string,mixed>>,next_cursor:?string,has_more:bool,high_water:?string} */
    public function fetch(Connection $connection, ?string $after, int $limit = self::PAGE_SIZE): array
    {
        $baseline = $after === null || $after === '' ? 0 : $this->sequence($after, 'The stored cursor');

        try {
            $response = Http::withToken((string) $connection->credential)->acceptJson()->timeout(self::TIMEOUT_SECONDS)->withoutRedirecting()
                ->get($connection->base_url.self::PATH, array_filter(['after' => $after, 'limit' => $limit], fn ($value) => $value !== null));
        } catch (ConnectionException $e) {
            throw new FeedException('The source could not be reached: '.Str::limit($e->getMessage(), 200, ''));
        }
        if (! $response->successful()) {
            throw new FeedException("The source answered HTTP {$response->status()}.");
        }

        return $this->validated($response->json(), $baseline, $limit);
    }

    /** The newest committed sequence of the source (one tiny request), or an exception when the source does not state it. */
    public function head(Connection $connection): int
    {
        $page = $this->fetch($connection, $connection->last_cursor, 1);
        if ($page['high_water'] === null) {
            throw new FeedException('The source does not state its high-water mark, so a gap cannot be bounded.');
        }

        return (int) $page['high_water'];
    }

    /** @return array{data:list<array<string,mixed>>,next_cursor:?string,has_more:bool,high_water:?string} */
    private function validated(mixed $page, int $after, int $limit): array
    {
        $invalid = fn (string $why) => new FeedException("The source answered with an invalid feed page: {$why}.");
        if (! is_array($page) || ! is_array($page['data'] ?? null) || ! array_is_list($page['data']) || ! is_bool($page['has_more'] ?? null)) {
            throw $invalid('shape');
        }
        if (count($page['data']) > $limit) {
            throw $invalid('more events than requested');
        }

        $highWater = null;
        if (array_key_exists('high_water', $page) && $page['high_water'] !== null) {
            $highWater = $this->sequence($page['high_water'], 'high_water');
        }

        $last = $after;
        foreach ($page['data'] as $envelope) {
            if (! is_array($envelope)) {
                throw new DomainException('The feed page contains an entry that is not an event.', 'INVALID_ENVELOPE', 422);
            }
            $sequence = $this->sequence($envelope['sequence'] ?? null, 'sequence');
            if ($sequence !== $last + 1) {
                throw $invalid("sequence {$sequence} follows {$last} (the feed is gap-free and ascending)");
            }
            $last = $sequence;
        }
        if ($highWater !== null && $last > $highWater) {
            throw $invalid('an event lies beyond the high-water mark');
        }

        $cursor = $page['next_cursor'] ?? null;
        if ($page['data'] === []) {
            if ($page['has_more']) {
                throw $invalid('an empty page says more follow');
            }
            if ($cursor !== null && $this->sequence($cursor, 'next_cursor') !== $after) {
                throw $invalid('an empty page moved the cursor');
            }

            return ['data' => [], 'next_cursor' => null, 'has_more' => false, 'high_water' => $highWater === null ? null : (string) $highWater];
        }
        if ($cursor === null || $this->sequence($cursor, 'next_cursor') !== $last) {
            throw $invalid('next_cursor is not the last sequence of the page');
        }

        return ['data' => $page['data'], 'next_cursor' => (string) $last, 'has_more' => $page['has_more'], 'high_water' => $highWater === null ? null : (string) $highWater];
    }

    /** A non-negative integer that fits 64 bits, written as digits (a JSON integer or a digit string); nothing else is a sequence. */
    private function sequence(mixed $value, string $what): int
    {
        $text = match (true) {
            is_int($value) => (string) $value,
            is_string($value) => $value,
            default => '',
        };
        if (! preg_match('/^(0|[1-9][0-9]*)$/', $text) || strlen($text) > 19 || (strlen($text) === 19 && strcmp($text, (string) PHP_INT_MAX) > 0)) {
            throw new FeedException("The source answered with an invalid feed page: {$what} is not a valid sequence.");
        }

        return (int) $text;
    }
}
