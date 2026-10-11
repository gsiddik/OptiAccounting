<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\JournalLine;
use App\Domain\Accounting\Models\JournalLineDimension;
use App\Domain\Accounting\Support\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\Database\Micros;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Draft journals: create, edit, list. Status never changes here (JournalWorkflow and PostingEngine own that). */
class JournalService
{
    public function __construct(
        private readonly PostingValidator $validator,
        private readonly AccountingScope $scope,
        private readonly AuditService $audit,
    ) {}

    /** @param array{document_date:string,posting_date:string,transaction_date?:?string,description:string,reference?:?string,lines:list<array<string,mixed>>} $data */
    public function createManual(array $data, User $actor): JournalEntry
    {
        return DB::transaction(function () use ($data, $actor) {
            $profile = $this->profile();
            $journal = $this->newDraft($data, JournalEntry::MANUAL, $profile, $actor->id);
            $this->writeLines($journal, $data['lines'] ?? [], $profile, $actor->id);
            $journal->save();
            $this->transition($journal, null, JournalEntry::DRAFT, $actor->id);
            $this->audit->record('accounting.journal.created', 'journal_entry', $journal->id, null, $this->summary($journal));

            return $journal->refresh()->load('lines.dimensions');
        });
    }

    /** Replace header fields and/or all lines of a DRAFT journal. */
    public function update(JournalEntry $journal, array $data, User $actor): JournalEntry
    {
        return DB::transaction(function () use ($journal, $data) {
            $journal = JournalEntry::query()->lockForUpdate()->findOrFail($journal->id);
            $this->assertEditable($journal);
            if ($journal->journal_type !== JournalEntry::MANUAL) {
                throw new DomainException('Only manual journals are edited here; an opening balance is handled on its own page.', 'JOURNAL_NOT_MANUAL', 409, ['journal_type' => $journal->journal_type]);
            }
            $profile = $this->profile();
            $before = $this->summary($journal);

            $journal->fill(collect($data)->only(['document_date', 'transaction_date', 'posting_date', 'description', 'reference'])->all());
            if (array_key_exists('lines', $data)) {
                $this->writeLines($journal, $data['lines'], $profile, $journal->created_by);
            }
            $journal->save();
            $this->audit->record('accounting.journal.updated', 'journal_entry', $journal->id, $before, $this->summary($journal));

            return $journal->load('lines.dimensions');
        });
    }

    /** Create a draft header of any type; used by the opening balance, reversal and event paths as well. */
    public function newDraft(array $data, string $type, AccountingProfile $profile, ?string $creatorId): JournalEntry
    {
        $journal = new JournalEntry(collect($data)->only(['document_date', 'transaction_date', 'posting_date', 'description', 'reference'])->all());
        $journal->journal_type = $type;
        $journal->status = JournalEntry::DRAFT;
        $journal->currency = $profile->functional_currency;
        $journal->created_by = $creatorId;
        $journal->posting_purpose = $data['posting_purpose'] ?? 'POST';
        $journal->source_type = $data['source_type'] ?? null;
        $journal->source_id = $data['source_id'] ?? null;
        $journal->reverses_journal_id = $data['reverses_journal_id'] ?? null;
        $journal->reversal_reason = $data['reversal_reason'] ?? null;
        $journal->posting_snapshot = $data['posting_snapshot'] ?? null;
        $journal->total_debit = '0';
        $journal->total_credit = '0';
        $journal->save();

        return $journal;
    }

    /**
     * Validate and (re)write the lines of a draft journal and refresh its server-computed totals.
     *
     * @param  list<array<string,mixed>>  $lines
     */
    public function writeLines(JournalEntry $journal, array $lines, AccountingProfile $profile, ?string $creatorId, bool $enforceScope = true): void
    {
        $normalized = $this->validator->normalize($lines, $profile, $journal->journal_type, balanced: false);

        if ($enforceScope) {
            foreach ($normalized['lines'] as $i => $line) {
                if (! $this->scope->lineAllowed($line['branch_id'], $line['business_unit_id'], $creatorId)) {
                    throw new DomainException('You may only book to the branches and business units in your data scope.', 'DATA_SCOPE_DENIED', 403, ['line' => $i + 1]);
                }
            }
        }

        JournalLine::query()->where('journal_entry_id', $journal->id)->delete(); // dimensions cascade; the DB allows it only for drafts
        $currency = $profile->functional_currency;
        foreach ($normalized['lines'] as $i => $line) {
            $row = new JournalLine;
            $row->journal_entry_id = $journal->id;
            $row->line_number = $i + 1;
            $row->account_id = $line['account_id'];
            $row->description = $line['description'];
            $row->reference = $line['reference'];
            $row->debit = Money::str($line['debit']);
            $row->credit = Money::str($line['credit']);
            if ($line['transaction'] ?? null) { // a foreign-currency leg of a system posting: the snapshot beside the functional amount
                $leg = $line['transaction'];
                $row->transaction_currency = $leg['currency'];
                $row->transaction_debit = $line['debit']->isZero() ? '0' : Money::str($leg['amount']);
                $row->transaction_credit = $line['credit']->isZero() ? '0' : Money::str($leg['amount']);
                $row->exchange_rate = (string) $leg['rate']->toScale(10);
            } else {
                $row->transaction_currency = $currency;
                $row->transaction_debit = Money::str($line['debit']);
                $row->transaction_credit = Money::str($line['credit']);
                $row->exchange_rate = '1';
            }
            $row->is_fx_difference = (bool) ($line['fx_difference'] ?? false);
            $row->branch_id = $line['branch_id'];
            $row->business_unit_id = $line['business_unit_id'];
            $row->cost_center_id = $line['cost_center_id'];
            $row->save();

            foreach ($line['dimensions'] as $dimension) {
                $d = new JournalLineDimension;
                $d->journal_line_id = $row->id;
                $d->dimension_type = $dimension['type'];
                $d->reference_id = $dimension['reference_id'];
                $d->reference_label = $dimension['reference_label'];
                $d->save();
            }
        }

        $journal->total_debit = Money::str($normalized['total_debit']);
        $journal->total_credit = Money::str($normalized['total_credit']);
        $journal->save();
    }

    public function assertEditable(JournalEntry $journal): void
    {
        if ($journal->status !== JournalEntry::DRAFT) {
            throw new DomainException("A {$journal->status} journal cannot be edited.", $journal->isPosted() ? 'JOURNAL_IMMUTABLE' : 'JOURNAL_NOT_DRAFT', 409, ['status' => $journal->status]);
        }
    }

    public function transition(JournalEntry $journal, ?string $from, string $to, ?string $actorId, ?string $reason = null): void
    {
        DB::table('journal_transitions')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $journal->tenant_id, 'journal_entry_id' => $journal->id,
            'from_status' => $from, 'to_status' => $to, 'actor_user_id' => $actorId, 'reason' => $reason, 'occurred_at' => Micros::now(),
        ]);
    }

    public function profile(): AccountingProfile
    {
        return AccountingProfile::query()->first()
            ?? throw new DomainException('Save the accounting profile before preparing journals.', 'ACCOUNTING_NOT_CONFIGURED', 409);
    }

    public function summary(JournalEntry $journal): array
    {
        return $journal->only(['journal_number', 'journal_type', 'status', 'posting_date', 'description', 'total_debit', 'total_credit']);
    }
}
