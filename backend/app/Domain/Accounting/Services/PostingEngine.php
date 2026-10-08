<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Support\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Services\OutboxPublisher;
use App\Domain\Shared\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
    ) {}

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

                if ($actor !== null && $journal->journal_type === JournalEntry::MANUAL) {
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
