<?php

namespace App\Domain\Integration\Services;

use RuntimeException;

/**
 * The source's feed could not be read. `access` says which of the three different situations this is (ecosystem audit A19), because they need
 * different people to act and must never look alike:
 *
 *   REFUSED      the source, or OptiEntry's own pairing check, refused to serve this organization (credential, scope, pairing, subscription,
 *                connector entitlement). Nothing is wrong with the pipeline; somebody has to lift the cause. Stored history is untouched.
 *   UNAVAILABLE  the source or its governance could not answer right now (network, 5xx, 429, 503); `retryAfter` is the source's hint in seconds.
 *   INVALID      the source answered, but not with the agreed feed (malformed page, a cursor ahead of the source).
 *
 * `errorCode` is a stable identifier (the source's own `FEED_*` code or one of OptiEntry's); the message is for logs and for the English fallback.
 */
class FeedException extends RuntimeException
{
    public const REFUSED = 'REFUSED';

    public const UNAVAILABLE = 'UNAVAILABLE';

    public const INVALID = 'INVALID';

    public function __construct(string $message, public readonly string $access = self::UNAVAILABLE, public readonly string $errorCode = 'SOURCE_ERROR', public readonly ?int $retryAfter = null, public readonly ?int $highWater = null)
    {
        parent::__construct($message);
    }

    /** `$highWater`: the source's newest committed sequence when the page stated one (kept so a source that fell behind our cursor can be shown). */
    public static function invalid(string $message, string $code = 'FEED_PAGE_INVALID', ?int $highWater = null): self
    {
        return new self($message, self::INVALID, $code, null, $highWater);
    }

    public static function refused(string $message, string $code): self
    {
        return new self($message, self::REFUSED, $code);
    }
}
