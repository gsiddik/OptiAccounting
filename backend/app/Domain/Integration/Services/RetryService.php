<?php

namespace App\Domain\Integration\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A person puts a FAILED, DEAD_LETTER or NEEDS_REVIEW event back in the queue (permission accounting.integration.retry, audited with the
 * reason) after fixing the cause, e.g. opening the period or completing a mapping. The attempt counter starts again and the event is
 * processed right away, so the caller sees the result; the content of the event never changes, and REJECTED events are final.
 *
 * A retry is refused (nothing changes, nothing is booked) while the connection is not ACTIVE: a paused or disconnected connection is a hard
 * stop, and so is an event whose content is disputed until ConflictService decides (ecosystem audit A20/A21). `dismiss` is the other
 * review decision: the event is refused for good (REJECTED) without touching the books, which stays possible on a paused connection.
 * Lock order is connection, then event, as in EventProcessor.
 */
class RetryService
{
    public function __construct(private readonly EventProcessor $processor, private readonly AuditService $audit) {}

    public function retry(string $id, string $reason): ExternalEvent
    {
        $event = DB::transaction(function () use ($id, $reason) {
            $event = $this->lock($id, active: true);
            if (! in_array($event->status, ExternalEvent::RETRYABLE, true)) {
                throw new DomainException("A {$event->status} event cannot be retried.", 'EVENT_NOT_RETRYABLE', 409, ['status' => $event->status]);
            }

            $before = ['status' => $event->status, 'attempts' => $event->attempts, 'last_error_code' => $event->last_error_code];
            $event->forceFill(['status' => ExternalEvent::RECEIVED, 'attempts' => 0, 'next_attempt_at' => null])->save();
            $this->audit->record('accounting.integration.event.retried', 'external_event', $event->id, $before, [
                'status' => ExternalEvent::RECEIVED, 'event_id' => $event->event_id, 'reason' => $reason,
            ]);

            return $event;
        });

        return $this->processor->process($event);
    }

    /** The reviewer decides the event must never be booked. */
    public function dismiss(string $id, string $reason): ExternalEvent
    {
        return DB::transaction(function () use ($id, $reason) {
            $event = $this->lock($id, active: false);
            if (! in_array($event->status, ExternalEvent::RETRYABLE, true)) {
                throw new DomainException("A {$event->status} event cannot be dismissed.", 'EVENT_NOT_DISMISSIBLE', 409, ['status' => $event->status]);
            }

            $before = ['status' => $event->status, 'last_error_code' => $event->last_error_code];
            $event->forceFill([
                'status' => ExternalEvent::REJECTED, 'next_attempt_at' => null, 'last_error_code' => 'REVIEW_DISMISSED',
                'last_error' => Str::limit("Dismissed by review: {$reason}", 500, ''),
            ])->save();
            $this->audit->record('accounting.integration.event.dismissed', 'external_event', $event->id, $before, [
                'status' => ExternalEvent::REJECTED, 'event_id' => $event->event_id, 'reason' => $reason,
            ]);

            return $event->refresh();
        });
    }

    /** The locked event; for a retry its connection (locked first, FOR SHARE) must be ACTIVE. An open conflict can only be decided by ConflictService. */
    private function lock(string $id, bool $active): ExternalEvent
    {
        $known = Str::isUuid($id) ? ExternalEvent::query()->find($id) : null;
        if ($known === null) {
            throw new DomainException('Integration event not found.', 'EVENT_NOT_FOUND', 404);
        }
        $connection = IntegrationConnection::query()->sharedLock()->find($known->connection_id);
        $event = ExternalEvent::query()->lockForUpdate()->findOrFail($id);

        if ($active && ($connection === null || $connection->status !== IntegrationConnection::ACTIVE)) {
            throw new DomainException('The connection is not active; switch it on again before anything is retried.', 'CONNECTION_NOT_ACTIVE', 409, ['status' => $connection?->status]);
        }
        if ($event->conflict) {
            throw new DomainException('The source sent a different payload for this event; decide about the conflict first.', 'EVENT_CONFLICT_OPEN', 409);
        }

        return $event;
    }
}
