<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\User;
use App\Domain\Integration\Services\OutboxPublisher;
use App\Domain\Shared\DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Correction of a posted journal: a new, balanced journal with debit and credit swapped, linked to the original, posted
 * through the same engine. The original stays POSTED and is only given its one-time `reversed_by` link; a journal is
 * reversed at most once (row lock + unique index) and a reversal is never reversed.
 */
class ReversalService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly JournalWorkflow $workflow,
        private readonly PostingEngine $engine,
        private readonly AuditService $audit,
        private readonly OutboxPublisher $outbox,
        private readonly OpeningBalanceService $openings,
    ) {}

    public function reverse(JournalEntry $original, User $actor, string $reason, ?string $postingDate = null, ?string $reference = null): JournalEntry
    {
        return DB::transaction(function () use ($original, $actor, $reason, $postingDate, $reference) {
            $original = JournalEntry::query()->lockForUpdate()->findOrFail($original->id);

            if (! $original->isPosted()) {
                throw new DomainException('Only a posted journal can be reversed.', 'JOURNAL_NOT_POSTED', 409, ['status' => $original->status]);
            }
            if ($original->journal_type === JournalEntry::REVERSAL) {
                throw new DomainException('A reversal journal cannot be reversed; post an adjustment instead.', 'JOURNAL_IS_REVERSAL', 409);
            }
            if ($original->reversed_by_journal_id !== null) {
                throw new DomainException('This journal has already been reversed.', 'JOURNAL_ALREADY_REVERSED', 409, ['reversed_by_journal_id' => $original->reversed_by_journal_id]);
            }

            $date = $postingDate ?? $original->posting_date->toDateString();
            if ($date < $original->posting_date->toDateString()) {
                throw new DomainException('A reversal cannot be posted before the journal it reverses.', 'REVERSAL_DATE_INVALID', 422);
            }

            $profile = $this->journals->profile();
            $lines = $original->lines()->with('dimensions')->get()->map(fn ($l) => [
                'account_id' => $l->account_id, 'description' => $l->description, 'reference' => $l->reference,
                'debit' => $l->credit, 'credit' => $l->debit, // swapped
                'branch_id' => $l->branch_id, 'business_unit_id' => $l->business_unit_id, 'cost_center_id' => $l->cost_center_id,
                'dimensions' => $l->dimensions->map(fn ($d) => ['type' => $d->dimension_type, 'reference_id' => $d->reference_id, 'reference_label' => $d->reference_label])->all(),
            ])->all();

            $reversal = $this->journals->newDraft([
                'document_date' => $date, 'posting_date' => $date,
                'description' => mb_substr("Pembalik {$original->journal_number}: {$reason}", 0, 500),
                'reference' => $reference ?? $original->journal_number,
                'reverses_journal_id' => $original->id, 'reversal_reason' => $reason,
            ], JournalEntry::REVERSAL, $profile, $actor->id);
            $this->journals->writeLines($reversal, $lines, $profile, $actor->id, enforceScope: false);
            $this->journals->transition($reversal, null, JournalEntry::DRAFT, $actor->id);

            $posted = $this->engine->post($reversal, $actor, $this->workflow->canPostSoftClosed($actor));

            DB::table('journal_entries')->where('tenant_id', $original->tenant_id)->where('id', $original->id)
                ->update(['reversed_by_journal_id' => $posted->id, 'updated_at' => now()]);

            if ($original->journal_type === JournalEntry::OPENING) {
                $this->openings->markReversed($original);
            }

            $this->audit->record('accounting.journal.reversed', 'journal_entry', $original->id, ['journal_number' => $original->journal_number], [
                'reversal_id' => $posted->id, 'reversal_number' => $posted->journal_number, 'posting_date' => $date, 'reason' => $reason,
            ]);
            $this->outbox->event('journal.reversed', $original->tenant_id, [
                'journal_id' => $original->id, 'journal_number' => $original->journal_number,
                'reversal_id' => $posted->id, 'reversal_number' => $posted->journal_number, 'posting_date' => $date,
            ]);

            return $posted->load('lines.dimensions');
        });
    }
}
