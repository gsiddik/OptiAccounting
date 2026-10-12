<?php

namespace App\Domain\Integration\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\ExternalEventConflict;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The review decision about a disputed event (permission accounting.integration.manage, audited with the reason). EventInbox keeps the stored
 * event, records the disputed payload as evidence and holds the event; this decides what happens next, without ever changing the evidence:
 *
 *   USE_ORIGINAL  the stored event is the truth. The conflicts are closed, a held event goes back to the queue and is processed with its
 *                 original payload (or, if it was already posted, the incident is simply closed). If the source really changed its mind it
 *                 sends a new event with a new id (a correction), which is how the books follow.
 *   REJECT        the event is refused for good (REJECTED) and nothing is booked; not possible once it is posted (reverse the document).
 */
class ConflictService
{
    public function __construct(private readonly EventProcessor $processor, private readonly AuditService $audit) {}

    public function resolve(string $id, string $decision, string $reason): ExternalEvent
    {
        if (! in_array($decision, ExternalEventConflict::RESOLUTIONS, true)) {
            throw new DomainException('The decision must be USE_ORIGINAL or REJECT.', 'CONFLICT_DECISION_INVALID', 422, ['field' => 'decision']);
        }

        $event = DB::transaction(function () use ($id, $decision, $reason) {
            $known = Str::isUuid($id) ? ExternalEvent::query()->find($id) : null;
            if ($known === null) {
                throw new DomainException('Integration event not found.', 'EVENT_NOT_FOUND', 404);
            }
            IntegrationConnection::query()->sharedLock()->find($known->connection_id);
            $event = ExternalEvent::query()->lockForUpdate()->findOrFail($id);
            if (! $event->conflict) {
                throw new DomainException('This event has no open conflict.', 'NO_OPEN_CONFLICT', 409);
            }
            if ($decision === ExternalEventConflict::REJECT && $event->status === ExternalEvent::POSTED) {
                throw new DomainException('The event is already posted; reverse the document instead of rejecting the event.', 'EVENT_ALREADY_POSTED', 409);
            }

            $before = ['status' => $event->status, 'conflict' => true, 'last_error_code' => $event->last_error_code];
            ExternalEventConflict::query()->where('external_event_id', $event->id)->whereNull('resolved_at')->get()->each(
                fn (ExternalEventConflict $conflict) => $conflict->forceFill([
                    'resolution' => $decision, 'resolution_reason' => Str::limit($reason, 500, ''), 'resolved_by' => $this->actor(), 'resolved_at' => now(),
                ])->save()
            );

            $state = ['conflict' => false];
            if ($decision === ExternalEventConflict::REJECT) {
                $state += ['status' => ExternalEvent::REJECTED, 'next_attempt_at' => null, 'last_error_code' => 'CONFLICT_REJECTED', 'last_error' => Str::limit("Rejected after a payload conflict: {$reason}", 500, '')];
            } elseif ($event->status === ExternalEvent::NEEDS_REVIEW && $event->last_error_code === 'PAYLOAD_CONFLICT') {
                $state += ['status' => ExternalEvent::RECEIVED, 'attempts' => 0, 'next_attempt_at' => null, 'last_error_code' => null, 'last_error' => null];
            }
            $event->forceFill($state)->save();
            $this->audit->record('accounting.integration.event.conflict_resolved', 'external_event', $event->id, $before, [
                'decision' => $decision, 'reason' => $reason, 'status' => $event->status, 'event_id' => $event->event_id,
            ]);

            return $event;
        });

        return $this->processor->process($event); // a released event is applied now; any other state is returned untouched
    }

    private function actor(): ?string
    {
        return auth()->id();
    }
}
