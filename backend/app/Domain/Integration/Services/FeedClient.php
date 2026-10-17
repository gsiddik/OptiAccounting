<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Models\IntegrationConnection as Connection;
use App\Domain\Shared\DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
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
 * when the source states its `high_water` (the newest committed sequence), nothing lies beyond it and the stored cursor is not ahead of it.
 *
 * Three different situations are told apart (ecosystem audit A19, FeedException::$access) because they need different people to act:
 *   REFUSED      HTTP 401/403, or the page's `pairing` does not match this organization's OptiNexus tenant (FeedPairing): the source's own `code`
 *                (FEED_*) is kept; nothing is stored;
 *   UNAVAILABLE  network failure, HTTP 429/5xx (FEED_GOVERNANCE_UNAVAILABLE included), with the source's Retry-After as a hint;
 *   INVALID      the source answered, but not with the agreed feed (a malformed page, a cursor ahead of the source).
 */
class FeedClient
{
    public const PATH = '/api/v1/integration/accounting-feed';

    public const PAGE_SIZE = 100;

    public const TIMEOUT_SECONDS = 15;

    /** The longest back-off a source's Retry-After may ask for. */
    public const MAX_RETRY_AFTER_SECONDS = 3600;

    /**
     * `paired_nexus_tenant_id` is the OptiNexus tenant the source verified for this page (null when pairing is not required).
     *
     * @return array{data:list<array<string,mixed>>,next_cursor:?string,has_more:bool,high_water:?string,paired_nexus_tenant_id:?string}
     *
     * @throws FeedException
     */
    public function fetch(Connection $connection, ?string $after, int $limit = self::PAGE_SIZE): array
    {
        $baseline = $after === null || $after === '' ? 0 : $this->sequence($after, 'The stored cursor');

        try {
            $response = Http::withToken((string) $connection->credential)->acceptJson()->timeout(self::TIMEOUT_SECONDS)->withoutRedirecting()
                ->get($connection->base_url.self::PATH, array_filter(['after' => $after, 'limit' => $limit], fn ($value) => $value !== null));
        } catch (ConnectionException $e) {
            throw new FeedException('The source could not be reached: '.Str::limit($e->getMessage(), 200, ''), FeedException::UNAVAILABLE, 'SOURCE_UNREACHABLE');
        }
        if (! $response->successful()) {
            throw $this->failure($response);
        }

        $body = $response->json();
        $page = $this->validated($body, $baseline, $limit);
        // Only a page with the agreed shape is checked for its pairing, so a malformed answer is INVALID and a wrong tenant is REFUSED.
        $page['paired_nexus_tenant_id'] = FeedPairing::verify($connection, is_array($body) ? $body : []);

        return $page;
    }

    /** Whether the source serves this connection at all (one tiny request); the FeedException says why not. */
    public function probe(Connection $connection): void
    {
        $this->fetch($connection, $connection->last_cursor, 1);
    }

    /** A non-2xx answer as the situation it is: 401/403 refuse on purpose, 429/5xx are an outage, anything else is not the feed. */
    private function failure(Response $response): FeedException
    {
        $status = $response->status();
        $message = "The source answered HTTP {$status}.";
        $code = $response->json('code');
        $code = is_string($code) && preg_match('/^[A-Z][A-Z0-9_]{2,59}$/', $code) === 1 ? $code : null;
        $retry = $response->header('Retry-After');
        $retryAfter = is_numeric($retry) ? min(max((int) $retry, 0), self::MAX_RETRY_AFTER_SECONDS) : null;

        return match (true) {
            $status === 401 => FeedException::refused($message, $code ?? 'FEED_UNAUTHENTICATED'),
            $status === 403 => FeedException::refused($message, $code ?? 'FEED_FORBIDDEN'),
            $status === 429 => new FeedException($message, FeedException::UNAVAILABLE, $code ?? 'SOURCE_RATE_LIMITED', $retryAfter),
            $status >= 500 => new FeedException($message, FeedException::UNAVAILABLE, $code ?? 'SOURCE_ERROR', $retryAfter),
            default => new FeedException($message, FeedException::INVALID, $code ?? 'SOURCE_ERROR'),
        };
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
        $invalid = fn (string $why, string $code = 'FEED_PAGE_INVALID', ?int $high = null) => FeedException::invalid("The source answered with an invalid feed page: {$why}.", $code, $high);
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

        if ($highWater !== null && $after > $highWater) {
            // The source is behind what was consumed: restored from a backup or reset. Nothing it says now can be matched with what we hold.
            throw $invalid("the stored cursor {$after} is ahead of the source's high-water mark {$highWater}", 'FEED_CURSOR_AHEAD', $highWater);
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
            throw FeedException::invalid("The source answered with an invalid feed page: {$what} is not a valid sequence.");
        }

        return (int) $text;
    }
}
