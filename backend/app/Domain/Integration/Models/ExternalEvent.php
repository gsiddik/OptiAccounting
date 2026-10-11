<?php

namespace App\Domain\Integration\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One canonical event in the inbox. EventInbox is the only writer of the row; the envelope and its hash are evidence and the database refuses
 * any change to them. Everything else is processing state moved by EventProcessor / RetryService. VALIDATED is part of the architecture's
 * vocabulary and accepted by the database; the gate runs inside `receive`, so no code path parks an event there today.
 */
class ExternalEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const RECEIVED = 'RECEIVED';

    public const VALIDATED = 'VALIDATED';

    public const POSTED = 'POSTED';

    public const NEEDS_REVIEW = 'NEEDS_REVIEW';

    public const REJECTED = 'REJECTED';

    public const FAILED = 'FAILED';

    public const DEAD_LETTER = 'DEAD_LETTER';

    public const STATUSES = [self::RECEIVED, self::VALIDATED, self::POSTED, self::NEEDS_REVIEW, self::REJECTED, self::FAILED, self::DEAD_LETTER];

    /** States a person may put back in the queue (REJECTED is final: its content is refused, not its processing). */
    public const RETRYABLE = [self::FAILED, self::DEAD_LETTER, self::NEEDS_REVIEW];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'envelope' => 'array', 'occurred_at' => 'datetime', 'received_at' => 'datetime', 'processed_at' => 'datetime', 'next_attempt_at' => 'datetime',
            'conflict' => 'boolean', 'attempts' => 'integer', 'duplicate_count' => 'integer', 'sequence' => 'integer',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }

    /** Events the processor may pick up now: RECEIVED, or FAILED once its backoff has elapsed. Keep in step with `isDue`. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->where('status', self::RECEIVED)
            ->orWhere(fn ($failed) => $failed->where('status', self::FAILED)->where(fn ($at) => $at->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))));
    }

    public function isDue(): bool
    {
        return $this->status === self::RECEIVED
            || ($this->status === self::FAILED && ($this->next_attempt_at === null || $this->next_attempt_at->lte(now())));
    }

    /** The major version of `schema_version` ("1.2" => 1). */
    public function schemaMajor(): int
    {
        return (int) explode('.', $this->schema_version)[0];
    }
}
