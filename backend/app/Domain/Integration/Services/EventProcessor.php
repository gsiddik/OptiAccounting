<?php

namespace App\Domain\Integration\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Integration\Contracts\ConnectorRequirements;
use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\ExternalSourceLink;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Applies one stored event through its source system's adapter, isolated from everything else.
 *
 * The event row is locked, and the adapter runs in a nested transaction together with the source links and the POSTED mark: either the
 * whole result exists or none of it does. Failures never touch accounting data (that transaction is rolled back) and are recorded on the
 * event only:
 *
 *   DomainException (period not open, mapping or rule missing, vendor unmapped, ...)  -> NEEDS_REVIEW with the business code; a person acts
 *   any other throwable (technical fault)                                             -> FAILED, retried after 1, 5, 15 and 60 minutes
 *   the fifth failed attempt                                                          -> DEAD_LETTER (audited); only a person can retry
 *   no adapter for the source, or the adapter does not support the type / major       -> NEEDS_REVIEW (NO_ADAPTER / UNSUPPORTED_EVENT)
 *   the adapter's connector is not (or no longer) entitled for the tenant             -> the event is left exactly as it is (ConnectorRequirements)
 *   the connection is not ACTIVE (paused, disconnected, in error)                      -> the event is left exactly as it is: a pause is a hard stop
 *   the event's content is disputed (an open conflict)                                 -> never processed until a person decides (ConflictService)
 *
 * The connection row is locked FOR SHARE before the event row: a processor in flight finishes before a pause or disconnect (which lock the
 * connection FOR UPDATE) can complete, so the moment a pause returns is a clear boundary - nothing starts after it. Lock order everywhere is
 * connection, then event.
 *
 * `POSTED` means the event was processed, not that a journal exists: `accounting_outcome` says what reached the books (ProcessOutcome).
 * Only RECEIVED events and FAILED events whose backoff has elapsed are processed; anything else is returned untouched, so calling this twice
 * (two workers, a retry racing the scheduler) applies an event once. Nothing here blocks manual accounting: no lock outlives the transaction.
 */
class EventProcessor
{
    public const MAX_ATTEMPTS = 5;

    /** Minutes to wait after the 1st, 2nd, 3rd and 4th failed attempt. */
    public const BACKOFF_MINUTES = [1, 5, 15, 60];

    public function __construct(private readonly AdapterRegistry $adapters, private readonly AuditService $audit) {}

    public function process(ExternalEvent $event): ExternalEvent
    {
        return DB::transaction(function () use ($event) {
            $connection = IntegrationConnection::query()->sharedLock()->find($event->connection_id);
            $locked = ExternalEvent::query()->lockForUpdate()->find($event->id);
            if ($locked === null || ! $locked->isDue() || $connection === null || $connection->status !== IntegrationConnection::ACTIVE) {
                return $locked ?? $event;
            }

            $adapter = $this->adapters->for($locked->source_system);
            if ($adapter === null) {
                return $this->review($locked, 'NO_ADAPTER', "No adapter is registered for {$locked->source_system}.");
            }
            if ($adapter instanceof ConnectorRequirements && $adapter->entitlementProblems() !== []) {
                return $locked; // a lapsed entitlement is not a fault of the event: it waits, and nothing reaches the books meanwhile
            }
            if (! $adapter->supports($locked->event_type, $locked->schemaMajor())) {
                return $this->review($locked, 'UNSUPPORTED_EVENT', "The {$locked->source_system} adapter does not support {$locked->event_type} at schema major {$locked->schemaMajor()}.");
            }

            try {
                DB::transaction(function () use ($adapter, $locked) {
                    $outcome = $adapter->process($locked);
                    $this->link($locked, $outcome);
                    $this->touch($locked, [
                        'status' => ExternalEvent::POSTED, 'resulting_journal_id' => $outcome->journalId, 'processed_at' => now(),
                        'accounting_outcome' => $outcome->accountingOutcome(), 'document_status' => $outcome->documentStatus,
                        'next_attempt_at' => null, 'last_error_code' => null, 'last_error' => null,
                    ]);
                });
            } catch (DomainException $e) {
                return $this->review($locked, $e->errorCode, $e->getMessage());
            } catch (Throwable $e) {
                report($e);

                return $this->failed($locked, $e);
            }

            return $locked->refresh();
        });
    }

    private function link(ExternalEvent $event, ProcessOutcome $outcome): void
    {
        foreach ($outcome->links as $link) {
            $key = [
                'source_system' => $event->source_system, 'source_type' => $link['source_type'], 'source_id' => $link['source_id'], 'source_event_id' => $event->event_id,
            ];
            $row = ExternalSourceLink::query()->where($key)->first() ?? (new ExternalSourceLink)->forceFill($key);
            $row->forceFill([
                'journal_id' => $link['journal_id'] ?? $outcome->journalId, 'document_type' => $link['document_type'] ?? null, 'document_id' => $link['document_id'] ?? null,
                'source_document_number' => $link['source_document_number'] ?? null,
            ])->save();
        }
    }

    private function review(ExternalEvent $event, string $code, string $message): ExternalEvent
    {
        return $this->touch($event, ['status' => ExternalEvent::NEEDS_REVIEW, 'next_attempt_at' => null, 'last_error_code' => $code, 'last_error' => Str::limit($message, 500, '')]);
    }

    private function failed(ExternalEvent $event, Throwable $e): ExternalEvent
    {
        $attempts = $event->attempts + 1;
        $dead = $attempts >= self::MAX_ATTEMPTS;
        $failed = $this->touch($event, [
            'status' => $dead ? ExternalEvent::DEAD_LETTER : ExternalEvent::FAILED, 'attempts' => $attempts,
            'next_attempt_at' => $dead ? null : now()->addMinutes(self::BACKOFF_MINUTES[$attempts - 1]),
            'last_error_code' => class_basename($e), 'last_error' => Str::limit($e->getMessage(), 500, ''),
        ]);
        if ($dead) {
            $this->audit->record('accounting.integration.event.dead_lettered', 'external_event', $event->id, null, [
                'event_id' => $event->event_id, 'event_type' => $event->event_type, 'attempts' => $attempts, 'last_error_code' => $failed->last_error_code,
            ], $event->tenant_id);
        }

        return $failed;
    }

    /** Write processing state through the query builder, so a rolled-back attempt leaves no dirty attributes behind in the model. */
    private function touch(ExternalEvent $event, array $state): ExternalEvent
    {
        ExternalEvent::query()->whereKey($event->id)->update($state + ['updated_at' => now()]);

        return $event->refresh();
    }
}
