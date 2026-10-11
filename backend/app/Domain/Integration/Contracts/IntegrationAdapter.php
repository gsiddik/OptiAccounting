<?php

namespace App\Domain\Integration\Contracts;

use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Services\ProcessOutcome;

/**
 * Translates the business facts of one source system into accounting through the existing domain services (never into accounts: debit and
 * credit come from posting rules and account mappings). One adapter per source system, tagged with AdapterRegistry::TAG.
 *
 * `process` runs inside the EventProcessor's database transaction with the tenant context of the event. It returns a ProcessOutcome when the
 * event is fully applied, throws Shared\DomainException for a business refusal that a person must resolve (period not open, mapping or
 * rule missing, vendor unmapped; the event becomes NEEDS_REVIEW) and lets any other throwable propagate (a technical fault; the event becomes
 * FAILED and is retried with backoff). Either way the transaction is rolled back, so a failing event never leaves accounting data behind.
 */
interface IntegrationAdapter
{
    /** The `source_system` this adapter serves, e.g. "optifleet". */
    public function sourceSystem(): string;

    /** Whether the adapter handles this event type at this schema major version; anything else is NEEDS_REVIEW, never guessed. */
    public function supports(string $eventType, int $major): bool;

    public function process(ExternalEvent $event): ProcessOutcome;
}
