<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Models\ExternalEvent;

/** The outcome of EventInbox::receive: a new row, the same event delivered again, or the same event id with different content. */
final class ReceiveResult
{
    public const CREATED = 'CREATED';

    public const DUPLICATE = 'DUPLICATE';

    public const CONFLICT = 'CONFLICT';

    public function __construct(public readonly ExternalEvent $event, public readonly string $outcome) {}

    public function created(): bool
    {
        return $this->outcome === self::CREATED;
    }

    public function duplicate(): bool
    {
        return $this->outcome === self::DUPLICATE;
    }

    public function conflict(): bool
    {
        return $this->outcome === self::CONFLICT;
    }
}
