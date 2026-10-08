<?php

namespace App\Domain\Accounting\Services;

use App\Domain\AccessControl\AccessRequest;
use App\Domain\AccessControl\Services\EffectiveAccess;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** Journal lifecycle transitions of manual journals: submit, approve, reject, reopen, cancel, post. Never an arbitrary status write. */
class JournalWorkflow
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly PostingValidator $validator,
        private readonly PeriodGuard $periods,
        private readonly SegregationOfDuties $sod,
        private readonly PostingEngine $engine,
        private readonly EffectiveAccess $access,
        private readonly TenantContext $context,
        private readonly AuditService $audit,
    ) {}

    public function submit(JournalEntry $journal, User $actor): JournalEntry
    {
        $this->assertManual($journal);

        return $this->move($journal, JournalEntry::SUBMITTED, $actor, function (JournalEntry $journal) use ($actor) {
            $profile = $this->journals->profile();
            $lines = $journal->lines()->with('dimensions')->get()->map(fn ($l) => [
                'account_id' => $l->account_id, 'debit' => $l->debit, 'credit' => $l->credit, 'branch_id' => $l->branch_id,
                'business_unit_id' => $l->business_unit_id, 'cost_center_id' => $l->cost_center_id,
                'dimensions' => $l->dimensions->map(fn ($d) => ['type' => $d->dimension_type, 'reference_id' => $d->reference_id])->all(),
            ])->all();
            $this->validator->normalize($lines, $profile, $journal->journal_type, balanced: true);
            $this->periods->resolveForPosting($journal->posting_date->toDateString(), $this->canPostSoftClosed($actor));
            $journal->submitted_by = $actor->id;
            $journal->submitted_at = now();
        });
    }

    public function approve(JournalEntry $journal, User $actor): JournalEntry
    {
        $this->assertManual($journal);

        return $this->move($journal, JournalEntry::APPROVED, $actor, function (JournalEntry $journal) use ($actor) {
            $this->sod->assertMayApprove($journal, $actor->id, $this->journals->profile());
            $journal->approved_by = $actor->id;
            $journal->approved_at = now();
        });
    }

    public function reject(JournalEntry $journal, User $actor, string $reason): JournalEntry
    {
        $this->assertManual($journal);

        return $this->move($journal, JournalEntry::REJECTED, $actor, function (JournalEntry $journal) use ($actor, $reason) {
            $journal->rejected_by = $actor->id;
            $journal->rejected_at = now();
            $journal->reject_reason = $reason;
        }, $reason);
    }

    /** REJECTED -> DRAFT so the preparer can correct and resubmit. */
    public function reopen(JournalEntry $journal, User $actor): JournalEntry
    {
        $this->assertManual($journal);

        return $this->move($journal, JournalEntry::DRAFT, $actor, function (JournalEntry $journal) {
            $journal->submitted_by = $journal->submitted_at = $journal->approved_by = $journal->approved_at = null;
        });
    }

    public function cancel(JournalEntry $journal, User $actor, string $reason): JournalEntry
    {
        $this->assertManual($journal);

        return $this->move($journal, JournalEntry::CANCELLED, $actor, function (JournalEntry $journal) use ($actor, $reason) {
            $journal->cancelled_by = $actor->id;
            $journal->cancelled_at = now();
            $journal->cancel_reason = $reason;
        }, $reason);
    }

    public function post(JournalEntry $journal, User $actor): JournalEntry
    {
        $this->assertManual($journal);

        return $this->engine->post($journal, $actor, $this->canPostSoftClosed($actor));
    }

    /** The opening balance owns its draft; this is how its service cancels it (type OPENING only). */
    public function cancelOpening(JournalEntry $journal, User $actor, string $reason): JournalEntry
    {
        if ($journal->journal_type !== JournalEntry::OPENING) {
            throw new DomainException('Not an opening balance journal.', 'JOURNAL_NOT_OPENING', 409);
        }

        return $this->move($journal, JournalEntry::CANCELLED, $actor, function (JournalEntry $journal) use ($actor, $reason) {
            $journal->cancelled_by = $actor->id;
            $journal->cancelled_at = now();
            $journal->cancel_reason = $reason;
        }, $reason);
    }

    /** Opening balance drafts are prepared, cancelled and posted on their own page (their own permission and preconditions). */
    private function assertManual(JournalEntry $journal): void
    {
        if ($journal->journal_type !== JournalEntry::MANUAL) {
            throw new DomainException('Only manual journals follow this workflow; an opening balance is handled on its own page.', 'JOURNAL_NOT_MANUAL', 409, ['journal_type' => $journal->journal_type]);
        }
    }

    public function canPostSoftClosed(User $actor): bool
    {
        return $this->access->evaluate(new AccessRequest($actor, $this->context->tenantId(), permission: 'accounting.journal.post_soft_closed'))->allowed;
    }

    private function move(JournalEntry $journal, string $to, User $actor, callable $apply, ?string $reason = null): JournalEntry
    {
        return DB::transaction(function () use ($journal, $to, $actor, $apply, $reason) {
            $journal = JournalEntry::query()->lockForUpdate()->findOrFail($journal->id);
            $from = $journal->status;

            if (! in_array($to, JournalEntry::TRANSITIONS[$from] ?? [], true) || ($to === JournalEntry::POSTED)) {
                throw new DomainException("A {$from} journal cannot become {$to}.", $journal->isPosted() ? 'JOURNAL_IMMUTABLE' : 'JOURNAL_INVALID_TRANSITION', 409, ['from' => $from, 'to' => $to]);
            }
            if ($from === JournalEntry::DRAFT && $to === JournalEntry::SUBMITTED && ! $journal->lines()->exists()) {
                throw new DomainException('A journal needs at least two lines.', 'JOURNAL_TOO_FEW_LINES', 422);
            }

            $apply($journal);
            $journal->status = $to;
            $journal->save();
            $this->journals->transition($journal, $from, $to, $actor->id, $reason);
            $this->audit->record('accounting.journal.'.strtolower($to), 'journal_entry', $journal->id, ['status' => $from], ['status' => $to, 'journal_type' => $journal->journal_type] + ($reason ? ['reason' => $reason] : []));

            return $journal->load('lines.dimensions');
        });
    }
}
