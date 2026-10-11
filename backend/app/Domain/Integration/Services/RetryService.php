<?php

namespace App\Domain\Integration\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A person puts a FAILED, DEAD_LETTER or NEEDS_REVIEW event back in the queue (permission accounting.integration.retry, audited with the
 * reason) after fixing the cause, e.g. opening the period or completing a mapping. The attempt counter starts again and the event is
 * processed right away, so the caller sees the result; the content of the event never changes, and REJECTED events are final.
 */
class RetryService
{
    public function __construct(private readonly EventProcessor $processor, private readonly AuditService $audit) {}

    public function retry(string $id, string $reason): ExternalEvent
    {
        $event = DB::transaction(function () use ($id, $reason) {
            $event = Str::isUuid($id) ? ExternalEvent::query()->lockForUpdate()->find($id) : null;
            if ($event === null) {
                throw new DomainException('Integration event not found.', 'EVENT_NOT_FOUND', 404);
            }
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
}
