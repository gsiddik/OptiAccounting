<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\AccountingEvent;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Support\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Services\OutboxPublisher;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The single posting core. Manual journals, the opening balance, reversals and accounting events all end here, so
 * validation, period locking, segregation of duties, numbering, immutability and audit exist exactly once:
 *
 *   lock journal -> readiness -> workflow state -> validate lines + double entry -> lock period (FOR SHARE)
 *   -> SoD -> issue number -> POSTED (database triggers re-check) -> transition + audit + outbox
 *
 * Everything is one transaction: either the whole journal posts or nothing does.
 */
class PostingEngine
{
    private const SEQUENCES = [
        JournalEntry::MANUAL => ['JOURNAL.MANUAL', 'JV'],
        JournalEntry::OPENING => ['JOURNAL.OPENING', 'OB'],
        JournalEntry::REVERSAL => ['JOURNAL.REVERSAL', 'RV'],
        JournalEntry::SYSTEM => ['JOURNAL.SYSTEM', 'SJ'],
    ];

    public function __construct(
        private readonly ReadinessService $readiness,
        private readonly PostingValidator $validator,
        private readonly PeriodGuard $periods,
        private readonly SegregationOfDuties $sod,
        private readonly DocumentNumbering $numbering,
        private readonly AccountingProfileService $profiles,
        private readonly JournalService $journals,
        private readonly AuditService $audit,
        private readonly OutboxPublisher $outbox,
        private readonly PostingRuleService $rules,
        private readonly TenantContext $context,
    ) {}

    /**
     * Post a business fact (accounting event) with system authority: resolve the rule effective on the posting date,
     * resolve every role through the tenant's account mappings, build a SYSTEM journal and post it through post().
     *
     * Idempotent on (source_type, source_id, purpose): a replay returns the original event; the same key with different
     * content is a conflict. A failure leaves a FAILED event row (written outside the rolled-back posting) and no journal.
     * The resolved rule version and role -> account facts are stored on the journal (`posting_snapshot`), so later rule or
     * mapping changes never reinterpret it.
     *
     * @param  array<string,mixed>  $payload  amount components (decimal strings) named by the event type; may also carry `role_accounts`
     *                                        and `distribution` (see PostingRuleService::build) for documents that name their own accounts
     * @param  array{branch_id?:?string,business_unit_id?:?string,cost_center_id?:?string}  $dimensions
     * @param  User|null  $actor  the person whose approved document this fact comes from (recorded as poster; null = pure system authority)
     * @param  bool  $allowSoftClosed  the actor may post into a soft-closed period (decided by the caller from the actor's permission)
     */
    public function postEvent(string $eventType, string $sourceType, string $sourceId, string $postingDate, array $payload, array $dimensions = [], string $purpose = 'POST', ?string $description = null, ?string $reference = null, ?User $actor = null, bool $allowSoftClosed = false): AccountingEvent
    {
        $hash = $this->fingerprint($eventType, $postingDate, $payload, $dimensions);

        try {
            return DB::transaction(function () use ($eventType, $sourceType, $sourceId, $postingDate, $payload, $dimensions, $purpose, $description, $reference, $hash, $actor, $allowSoftClosed) {
                $event = $this->claimEvent($eventType, $sourceType, $sourceId, $purpose, $postingDate, $payload, $hash);
                if ($event->status === 'POSTED') {
                    if ($event->payload_hash !== $hash) {
                        throw new DomainException('This business fact was already posted with different content.', 'ACCOUNTING_EVENT_CONFLICT', 409, ['source_type' => $sourceType, 'source_id' => $sourceId]);
                    }

                    return $event; // idempotent replay
                }

                $profile = $this->journals->profile();
                $rule = $this->rules->resolve($eventType, $postingDate);
                $built = $this->rules->build($rule, $payload, $dimensions, (int) $profile->currency_scale);
                if ($built['lines'] === []) {
                    throw new DomainException('The event produced no journal lines.', 'EVENT_EMPTY', 422);
                }

                $label = DB::table('accounting_event_types')->where('code', $eventType)->value('name');
                $journal = $this->journals->newDraft([
                    'document_date' => $postingDate, 'posting_date' => $postingDate, 'description' => mb_substr($description ?? "{$label} {$sourceId}", 0, 500),
                    'reference' => $reference ?? $sourceId, 'source_type' => $sourceType, 'source_id' => $sourceId, 'posting_purpose' => $purpose,
                    'posting_snapshot' => [
                        'event_type' => $eventType, 'payload' => $payload, 'dimensions' => $dimensions,
                        'rule' => ['id' => $rule->id, 'code' => $rule->code, 'version' => $rule->version, 'effective_from' => $rule->effective_from->toDateString()],
                        'lines' => $built['trace'],
                    ],
                ], JournalEntry::SYSTEM, $profile, null);
                $this->journals->writeLines($journal, $built['lines'], $profile, null, enforceScope: false);
                $this->journals->transition($journal, null, JournalEntry::DRAFT, null);
                $posted = $this->post($journal, $actor, $allowSoftClosed);

                DB::table('accounting_events')->where('id', $event->id)->update([
                    'status' => 'POSTED', 'journal_entry_id' => $posted->id, 'posting_rule_id' => $rule->id, 'failure_code' => null, 'failure_message' => null,
                    'attempts' => DB::raw('attempts + 1'), 'processed_at' => now(), 'updated_at' => now(),
                ]);

                return AccountingEvent::query()->findOrFail($event->id);
            });
        } catch (\Throwable $e) {
            if (! ($e instanceof DomainException && $e->errorCode === 'ACCOUNTING_EVENT_CONFLICT')) {
                $this->recordFailure($eventType, $sourceType, $sourceId, $purpose, $postingDate, $payload, $hash, $e);
            }
            throw $e;
        }
    }

    /** The event row of this business fact, created on first sight and locked for the rest of the transaction. */
    private function claimEvent(string $eventType, string $sourceType, string $sourceId, string $purpose, string $postingDate, array $payload, string $hash): AccountingEvent
    {
        DB::table('accounting_events')->insertOrIgnore([
            'id' => (string) Str::uuid7(), 'tenant_id' => $this->context->tenantId(), 'event_type' => $eventType, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'posting_purpose' => $purpose, 'status' => 'PENDING', 'posting_date' => $postingDate, 'payload' => json_encode($payload), 'payload_hash' => $hash,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $event = AccountingEvent::query()->lockForUpdate()->where('source_type', $sourceType)->where('source_id', $sourceId)->where('posting_purpose', $purpose)->firstOrFail();

        if ($event->status !== 'POSTED') { // a retried failure may carry a corrected fact
            DB::table('accounting_events')->where('id', $event->id)->update([
                'event_type' => $eventType, 'posting_date' => $postingDate, 'payload' => json_encode($payload), 'payload_hash' => $hash, 'updated_at' => now(),
            ]);
            $event->refresh();
        }

        return $event;
    }

    private function recordFailure(string $eventType, string $sourceType, string $sourceId, string $purpose, string $postingDate, array $payload, string $hash, \Throwable $e): void
    {
        $code = $e instanceof DomainException ? $e->errorCode : 'POSTING_ERROR';
        $message = $e instanceof DomainException ? $e->getMessage() : 'Unexpected error while posting the event.';

        try {
            $inserted = DB::table('accounting_events')->insertOrIgnore([
                'id' => (string) Str::uuid7(), 'tenant_id' => $this->context->tenantId(), 'event_type' => $eventType, 'source_type' => $sourceType, 'source_id' => $sourceId,
                'posting_purpose' => $purpose, 'status' => 'FAILED', 'posting_date' => $postingDate, 'payload' => json_encode($payload), 'payload_hash' => $hash,
                'failure_code' => $code, 'failure_message' => mb_substr($message, 0, 500), 'attempts' => 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($inserted === 0) { // a retried fact: keep the one row, count the attempt, never touch a POSTED one
                DB::table('accounting_events')->where('tenant_id', $this->context->tenantId())->where('source_type', $sourceType)->where('source_id', $sourceId)
                    ->where('posting_purpose', $purpose)->where('status', '!=', 'POSTED')
                    ->update(['status' => 'FAILED', 'failure_code' => $code, 'failure_message' => mb_substr($message, 0, 500), 'payload' => json_encode($payload), 'payload_hash' => $hash,
                        'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
            }
        } catch (\Throwable) {
            // The failure record is best effort; the original error is what the caller needs.
        }
    }

    /** Stable content hash: key order and int/string form of the same value do not matter. */
    private function fingerprint(string $eventType, string $postingDate, array $payload, array $dimensions): string
    {
        $sort = function (array $a) use (&$sort): array {
            ksort($a);

            return array_map(fn ($v) => is_array($v) ? $sort($v) : (is_scalar($v) ? (string) $v : $v), $a);
        };

        return hash('sha256', json_encode(['t' => $eventType, 'd' => $postingDate, 'p' => $sort($payload), 'x' => $sort(array_filter($dimensions))]));
    }

    /**
     * Post a journal that is APPROVED (or a DRAFT whose type/policy allows posting without approval).
     *
     * @param  User|null  $actor  null = system authority (accounting events)
     */
    public function post(JournalEntry $journal, ?User $actor, bool $allowSoftClosed = false): JournalEntry
    {
        try {
            return DB::transaction(function () use ($journal, $actor, $allowSoftClosed) {
                // 1. The journal row lock serializes approval / cancel / double posting of the same journal.
                $journal = JournalEntry::query()->lockForUpdate()->findOrFail($journal->id);
                if ($journal->isPosted()) {
                    throw new DomainException('This journal is already posted.', 'JOURNAL_ALREADY_POSTED', 409, ['journal_number' => $journal->journal_number]);
                }

                // Posting shares the ledger gate; the opening balance takes it exclusively, so no posting slips in before a cutover date.
                DB::select('select pg_advisory_xact_lock_shared(hashtextextended(?, 0))', [self::ledgerGate($journal->tenant_id)]);

                $postingDate = $journal->posting_date->toDateString();
                $profile = $this->readiness->assertCanPost($postingDate, $journal->journal_type);
                $this->assertWorkflowAllowsPosting($journal, $profile->approval_required);

                // 2. Re-validate the stored lines exactly as a new posting would; totals come from the lines, never the client.
                $lines = JournalLine::query()->with('dimensions')->where('journal_entry_id', $journal->id)->orderBy('line_number')->get();
                $normalized = $this->validator->normalize($lines->map(fn (JournalLine $l) => [
                    'account_id' => $l->account_id, 'debit' => $l->debit, 'credit' => $l->credit, 'description' => $l->description, 'reference' => $l->reference,
                    'branch_id' => $l->branch_id, 'business_unit_id' => $l->business_unit_id, 'cost_center_id' => $l->cost_center_id,
                    'dimensions' => $l->dimensions->map(fn ($d) => ['type' => $d->dimension_type, 'reference_id' => $d->reference_id])->all(),
                ])->all(), $profile, $journal->journal_type, balanced: true, lockAccounts: true);

                // 3. The period is resolved from posting_date and locked FOR SHARE: a concurrent close waits, a later one sees the posting.
                $period = $this->periods->resolveForPosting($postingDate, $allowSoftClosed && $actor !== null);

                if ($actor !== null && in_array($journal->journal_type, [JournalEntry::MANUAL, JournalEntry::OPENING], true)) {
                    $this->sod->assertMayPost($journal, $actor->id, $profile);
                }

                // 4. Number, then the status change; the database triggers verify balance, accounts and period once more.
                $year = FiscalYear::query()->findOrFail($period->fiscal_year_id);
                [$sequence, $prefix] = self::SEQUENCES[$journal->journal_type];
                $from = $journal->status;

                $journal->journal_number = $this->numbering->issue($sequence, $year->id, $prefix, $year->code);
                $journal->fiscal_year_id = $year->id;
                $journal->accounting_period_id = $period->id;
                $journal->total_debit = Money::str($normalized['total_debit']);
                $journal->total_credit = Money::str($normalized['total_credit']);
                $journal->status = JournalEntry::POSTED;
                $journal->posted_by = $actor?->id;
                $journal->posted_at = now();
                $journal->save();

                $this->journals->transition($journal, $from, JournalEntry::POSTED, $actor?->id);
                $this->profiles->lockOnFirstPosting($journal->tenant_id);
                $this->audit->record('accounting.journal.posted', 'journal_entry', $journal->id, ['status' => $from], $journal->only([
                    'journal_number', 'journal_type', 'status', 'posting_date', 'total_debit', 'total_credit', 'accounting_period_id',
                ]));
                $this->outbox->event('journal.posted', $journal->tenant_id, [
                    'journal_id' => $journal->id, 'journal_number' => $journal->journal_number, 'journal_type' => $journal->journal_type,
                    'posting_date' => $postingDate, 'total' => $journal->total_debit, 'currency' => $journal->currency,
                ]);

                return $journal->load('lines.dimensions');
            });
        } catch (QueryException $e) {
            throw $this->translate($e);
        }
    }

    /** Advisory lock key serializing the opening balance against every other posting of the tenant. */
    public static function ledgerGate(string $tenantId): string
    {
        return "oa1:ledger:{$tenantId}";
    }

    /** MANUAL journals follow the approval policy; every other type is created and posted by its own service. */
    private function assertWorkflowAllowsPosting(JournalEntry $journal, bool $approvalRequired): void
    {
        $needsApproval = $journal->journal_type === JournalEntry::MANUAL && $approvalRequired;
        $allowed = $needsApproval ? [JournalEntry::APPROVED] : [JournalEntry::DRAFT, JournalEntry::APPROVED];

        if (! in_array($journal->status, $allowed, true)) {
            $code = $needsApproval && $journal->status === JournalEntry::DRAFT ? 'JOURNAL_APPROVAL_REQUIRED' : 'JOURNAL_NOT_POSTABLE';
            throw new DomainException("A {$journal->status} journal cannot be posted".($code === 'JOURNAL_APPROVAL_REQUIRED' ? ': approval is required by the accounting policy.' : '.'), $code, 409, ['status' => $journal->status]);
        }
    }

    /** Database integrity refusals become a stable, non-leaking domain error. */
    private function translate(QueryException $e): \Throwable
    {
        $state = $e->errorInfo[0] ?? '';
        if ($state === '23505') {
            return new DomainException('This posting duplicates an existing journal.', 'JOURNAL_DUPLICATE', 409);
        }
        if ($state === '23514') {
            return new DomainException('The posting was refused by a database integrity rule.', 'FINANCIAL_INVARIANT_VIOLATION', 422);
        }

        return $e;
    }
}
