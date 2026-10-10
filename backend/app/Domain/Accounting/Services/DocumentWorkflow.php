<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\AccountingEvent;
use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\Database\Micros;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The lifecycle shared by every OA2 source document (vendor invoice, vendor payment, expense, cash transaction):
 *   DRAFT > SUBMITTED > APPROVED > POSTED (> REVERSED), with REJECTED (back to DRAFT) and CANCELLED.
 * It owns transitions, actor columns, segregation of duties, document numbering, the append-only history and the audit record.
 * What a document means financially (lines, totals, the accounting event) stays in its own service; posting itself is always
 * `PostingEngine::postEvent`. There is no arbitrary status write: a document moves only along TRANSITIONS, under a row lock.
 * Models need the columns status, created_by, submitted_*, approved_*, rejected_*, cancelled_*, and the constants DOCUMENT_TYPE / AUDIT_PREFIX.
 */
class DocumentWorkflow
{
    public const TRANSITIONS = [
        'DRAFT' => ['SUBMITTED', 'CANCELLED', 'POSTED'],
        'SUBMITTED' => ['APPROVED', 'REJECTED', 'CANCELLED'],
        'APPROVED' => ['POSTED', 'CANCELLED'],
        'REJECTED' => ['DRAFT', 'CANCELLED'],
        'POSTED' => ['REVERSED'],
        'REVERSED' => [],
        'CANCELLED' => [],
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly SegregationOfDuties $sod,
        private readonly JournalService $journals,
        private readonly DocumentNumbering $numbering,
    ) {}

    public function profile(): AccountingProfile
    {
        return $this->journals->profile();
    }

    /** @param callable(Model):void|null $guard extra validation run under the lock before the move */
    public function submit(Model $doc, User $actor, ?callable $guard = null): Model
    {
        return $this->move($doc, 'SUBMITTED', $actor, $guard, function (Model $doc) use ($actor) {
            $doc->submitted_by = $actor->id;
            $doc->submitted_at = now();
        });
    }

    public function approve(Model $doc, User $actor, ?callable $guard = null): Model
    {
        return $this->move($doc, 'APPROVED', $actor, function (Model $doc) use ($actor, $guard) {
            $this->sod->assertDocumentMayApprove($doc, $actor->id, $this->profile());
            if ($guard) {
                $guard($doc);
            }
        }, function (Model $doc) use ($actor) {
            $doc->approved_by = $actor->id;
            $doc->approved_at = now();
        });
    }

    public function reject(Model $doc, User $actor, string $reason): Model
    {
        return $this->move($doc, 'REJECTED', $actor, null, function (Model $doc) use ($actor, $reason) {
            $doc->rejected_by = $actor->id;
            $doc->rejected_at = now();
            $doc->reject_reason = $reason;
        }, $reason);
    }

    /** REJECTED -> DRAFT so the preparer can correct and resubmit. */
    public function reopen(Model $doc, User $actor): Model
    {
        return $this->move($doc, 'DRAFT', $actor, null, function (Model $doc) {
            $doc->submitted_by = $doc->submitted_at = $doc->approved_by = $doc->approved_at = $doc->rejected_by = $doc->rejected_at = $doc->reject_reason = null;
        });
    }

    public function cancel(Model $doc, User $actor, string $reason): Model
    {
        return $this->move($doc, 'CANCELLED', $actor, null, function (Model $doc) use ($actor, $reason) {
            $doc->cancelled_by = $actor->id;
            $doc->cancelled_at = now();
            $doc->cancel_reason = $reason;
        }, $reason);
    }

    /**
     * The status and segregation-of-duties gate in front of posting. Call it under the document's row lock.
     * A document that needs approval (profile policy, or a flow that always has it) posts from APPROVED only.
     */
    public function assertMayPost(Model $doc, User $actor, bool $approvalFlow = true): void
    {
        $profile = $this->profile();
        $needsApproval = $approvalFlow && $profile->approval_required;
        $allowed = $needsApproval ? ['APPROVED'] : ['DRAFT', 'APPROVED'];

        if (! in_array($doc->status, $allowed, true)) {
            $code = $doc->status === 'POSTED' ? 'DOCUMENT_ALREADY_POSTED' : ($needsApproval && $doc->status === 'DRAFT' ? 'DOCUMENT_APPROVAL_REQUIRED' : 'DOCUMENT_NOT_POSTABLE');
            throw new DomainException("A {$doc->status} document cannot be posted".($code === 'DOCUMENT_APPROVAL_REQUIRED' ? ': approval is required by the accounting policy.' : '.'), $code, 409, ['status' => $doc->status]);
        }
        $this->sod->assertDocumentMayPost($doc, $actor->id, $profile);
    }

    /** POSTED: number issued (gapless, rolled back with the posting), journal linked, history and audit written. @param array<string,mixed> $extra */
    public function markPosted(Model $doc, User $actor, JournalEntry $journal, AccountingEvent $event, string $sequence, string $prefix, array $extra = []): Model
    {
        $year = FiscalYear::query()->findOrFail($journal->fiscal_year_id);
        $from = $doc->status;

        $doc->forceFill($extra + [
            'document_number' => $this->numbering->issue($sequence, $year->id, $prefix, $year->code),
            'journal_entry_id' => $journal->id, 'accounting_event_id' => $event->id,
            'status' => 'POSTED', 'posted_by' => $actor->id, 'posted_at' => now(),
        ])->save();

        $this->log($doc, $from, 'POSTED', $actor->id);
        $this->audit->record($this->prefix($doc).'.posted', $doc::DOCUMENT_TYPE, $doc->id, ['status' => $from], [
            'status' => 'POSTED', 'document_number' => $doc->document_number, 'journal_number' => $journal->journal_number, 'posting_date' => $journal->posting_date->toDateString(),
            'total' => $journal->total_debit,
        ]);

        return $doc;
    }

    /** REVERSED: the reversal journal is linked once; the document keeps its own posted figures. */
    public function markReversed(Model $doc, User $actor, JournalEntry $reversal, string $reason): Model
    {
        $doc->forceFill([
            'status' => 'REVERSED', 'reversal_journal_id' => $reversal->id, 'reversed_by' => $actor->id, 'reversed_at' => now(),
            'reversal_reason' => $reason, 'reversal_posting_date' => $reversal->posting_date->toDateString(),
        ])->save();

        $this->log($doc, 'POSTED', 'REVERSED', $actor->id, $reason);
        $this->audit->record($this->prefix($doc).'.reversed', $doc::DOCUMENT_TYPE, $doc->id, ['status' => 'POSTED'], [
            'status' => 'REVERSED', 'document_number' => $doc->document_number, 'reversal_journal_number' => $reversal->journal_number,
            'reversal_posting_date' => $reversal->posting_date->toDateString(), 'reason' => $reason,
        ]);

        return $doc;
    }

    public function log(Model $doc, ?string $from, string $to, ?string $actorId, ?string $reason = null): void
    {
        DB::table('document_transitions')->insert([
            'id' => (string) Str::uuid7(), 'tenant_id' => $doc->tenant_id, 'document_type' => $doc::DOCUMENT_TYPE, 'document_id' => $doc->id,
            'from_status' => $from, 'to_status' => $to, 'actor_user_id' => $actorId, 'reason' => $reason, 'occurred_at' => Micros::now(),
        ]);
    }

    /** Record the creation (call once, in the creating transaction). */
    public function created(Model $doc, ?string $actorId): void
    {
        $this->log($doc, null, 'DRAFT', $actorId);
    }

    public function prefix(Model $doc): string
    {
        return $doc::AUDIT_PREFIX;
    }

    private function move(Model $doc, string $to, User $actor, ?callable $guard, callable $apply, ?string $reason = null): Model
    {
        return DB::transaction(function () use ($doc, $to, $actor, $guard, $apply, $reason) {
            $doc = $doc::query()->lockForUpdate()->findOrFail($doc->id);
            $from = $doc->status;

            if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
                throw new DomainException("A {$from} document cannot become {$to}.", in_array($from, ['POSTED', 'REVERSED'], true) ? 'DOCUMENT_IMMUTABLE' : 'DOCUMENT_INVALID_TRANSITION', 409, ['from' => $from, 'to' => $to]);
            }
            if ($guard) {
                $guard($doc);
            }
            $apply($doc);
            $doc->status = $to;
            $doc->save();

            $this->log($doc, $from, $to, $actor->id, $reason);
            $this->audit->record($this->prefix($doc).'.'.strtolower($to), $doc::DOCUMENT_TYPE, $doc->id, ['status' => $from], ['status' => $to] + ($reason ? ['reason' => $reason] : []));

            return $doc;
        });
    }
}
