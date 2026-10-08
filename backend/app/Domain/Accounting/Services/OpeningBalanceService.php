<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Models\OpeningBalance;
use App\Domain\Accounting\Support\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Controlled initialization of the ledger. An opening balance is NOT a stored balance: it is one balanced OPENING journal
 * dated on the cutover date, prepared as a draft, posted once through the central posting engine and reversed (never
 * edited) afterwards. One live opening balance per tenant; nothing earlier than the cutover date may already be posted.
 * Semantics for the ledger reports: OPENING journals always count in the "opening" column, never in period movement.
 */
class OpeningBalanceService
{
    public function __construct(
        private readonly JournalService $journals,
        private readonly JournalWorkflow $workflow,
        private readonly PostingEngine $engine,
        private readonly AccountingScope $scope,
        private readonly AccountingProfileService $profiles,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    /** The live (draft or posted) opening balance, else the latest historical one, else null. */
    public function current(): ?OpeningBalance
    {
        return OpeningBalance::query()->whereIn('status', [OpeningBalance::DRAFT, OpeningBalance::POSTED])->first()
            ?? OpeningBalance::query()->latest('created_at')->first();
    }

    /** @return array<string,mixed>|null */
    public function present(?OpeningBalance $opening): ?array
    {
        if ($opening === null) {
            return null;
        }
        $journal = JournalEntry::query()->with(['lines.account:id,code,name,normal_balance', 'lines.branch', 'lines.businessUnit', 'lines.costCenter', 'lines.dimensions'])->findOrFail($opening->journal_entry_id);
        if (! $this->scope->journalVisible($journal)) {
            throw new DomainException('Opening balance not found.', 'OPENING_BALANCE_NOT_FOUND', 404);
        }

        $debit = Money::sum($journal->lines->pluck('debit'));
        $credit = Money::sum($journal->lines->pluck('credit'));

        return $opening->toArray() + [
            'journal' => $journal->toArray(),
            'total_debit' => Money::str($debit), 'total_credit' => Money::str($credit),
            'difference' => Money::str($debit->minus($credit)), 'balanced' => $debit->isEqualTo($credit) && $debit->isPositive(),
        ];
    }

    /**
     * Create or replace the draft: cutover date, reference and the full set of lines.
     *
     * @param  array{cutover_date:string,reference?:?string,description?:?string,lines:list<array<string,mixed>>}  $data
     */
    public function save(array $data, User $actor): OpeningBalance
    {
        return DB::transaction(function () use ($data, $actor) {
            DB::table('tenants')->where('id', $this->context->tenantId())->lockForUpdate()->first(); // one preparer at a time
            $profile = $this->journals->profile();
            $live = OpeningBalance::query()->whereIn('status', [OpeningBalance::DRAFT, OpeningBalance::POSTED])->lockForUpdate()->first();
            if ($live?->status === OpeningBalance::POSTED) {
                throw new DomainException('The opening balance is already posted; reverse it to start over.', 'OPENING_BALANCE_POSTED', 409);
            }

            $date = $data['cutover_date'];
            $header = [
                'document_date' => $date, 'posting_date' => $date, 'reference' => $data['reference'] ?? null,
                'description' => $data['description'] ?? 'Saldo awal per '.$date,
            ];

            if ($live) {
                $journal = JournalEntry::query()->lockForUpdate()->findOrFail($live->journal_entry_id);
                $journal->fill($header)->save();
                $live->fill(['cutover_date' => $date, 'reference' => $header['reference'], 'description' => $header['description']])->save();
                $before = ['lines' => $journal->lines()->count()];
            } else {
                $journal = $this->journals->newDraft($header, JournalEntry::OPENING, $profile, $actor->id);
                $this->journals->transition($journal, null, JournalEntry::DRAFT, $actor->id);
                $live = new OpeningBalance(['cutover_date' => $date, 'reference' => $header['reference'], 'description' => $header['description']]);
                $live->journal_entry_id = $journal->id;
                $live->status = OpeningBalance::DRAFT;
                $live->created_by = $actor->id;
                $live->save();
                $before = null;
            }

            $this->journals->writeLines($journal, $data['lines'], $profile, $actor->id);
            $this->audit->record('accounting.opening_balance.saved', 'opening_balance', $live->id, $before, ['cutover_date' => $date, 'lines' => count($data['lines'])]);

            return $live;
        });
    }

    /** Post the draft: the single initialization of the ledger. */
    public function post(User $actor): OpeningBalance
    {
        return DB::transaction(function () use ($actor) {
            // Exclusive ledger gate: in-flight postings finish first, new ones wait until the cutover date is in force.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [PostingEngine::ledgerGate($this->context->tenantId())]);

            $opening = OpeningBalance::query()->whereIn('status', [OpeningBalance::DRAFT, OpeningBalance::POSTED])->lockForUpdate()->first()
                ?? throw new DomainException('There is no opening balance to post.', 'OPENING_BALANCE_NOT_FOUND', 404);
            if ($opening->status === OpeningBalance::POSTED) {
                throw new DomainException('The opening balance is already posted.', 'OPENING_BALANCE_POSTED', 409);
            }

            $journal = JournalEntry::query()->findOrFail($opening->journal_entry_id);
            if (! $this->scope->journalVisible($journal)) {
                throw new DomainException('Opening balance not found.', 'OPENING_BALANCE_NOT_FOUND', 404);
            }

            $date = $opening->cutover_date->toDateString();
            $earlier = JournalEntry::query()->where('status', JournalEntry::POSTED)->where('journal_type', '!=', JournalEntry::OPENING)->whereDate('posting_date', '<', $date)->exists();
            if ($earlier) {
                throw new DomainException('Journals are already posted before the cutover date; choose a cutover date on or before the first posted journal.', 'OPENING_BALANCE_HISTORY_EXISTS', 409, ['cutover_date' => $date]);
            }

            $posted = $this->engine->post($journal, $actor, $this->workflow->canPostSoftClosed($actor));

            $opening->status = OpeningBalance::POSTED;
            $opening->posted_by = $actor->id;
            $opening->posted_at = now();
            $opening->save();
            $this->profiles->setCutoverDate($opening->tenant_id, $date);
            $this->audit->record('accounting.opening_balance.posted', 'opening_balance', $opening->id, ['status' => OpeningBalance::DRAFT], [
                'status' => OpeningBalance::POSTED, 'cutover_date' => $date, 'journal_number' => $posted->journal_number, 'total' => $posted->total_debit,
            ]);

            return $opening;
        });
    }

    /** Abandon a draft; a new one can then be prepared. */
    public function cancel(User $actor, string $reason): OpeningBalance
    {
        return DB::transaction(function () use ($actor, $reason) {
            $opening = OpeningBalance::query()->where('status', OpeningBalance::DRAFT)->lockForUpdate()->first()
                ?? throw new DomainException('There is no draft opening balance.', 'OPENING_BALANCE_NOT_FOUND', 404);
            $this->workflow->cancelOpening(JournalEntry::query()->findOrFail($opening->journal_entry_id), $actor, $reason);
            $opening->status = OpeningBalance::CANCELLED;
            $opening->save();
            $this->audit->record('accounting.opening_balance.cancelled', 'opening_balance', $opening->id, ['status' => OpeningBalance::DRAFT], ['status' => OpeningBalance::CANCELLED, 'reason' => $reason]);

            return $opening;
        });
    }

    /** Called by the reversal service in its transaction: a reversed opening balance frees the tenant to initialize again. */
    public function markReversed(JournalEntry $original): void
    {
        $opening = OpeningBalance::query()->where('journal_entry_id', $original->id)->lockForUpdate()->first();
        if ($opening === null) {
            return;
        }
        $opening->status = OpeningBalance::REVERSED;
        $opening->save();
        DB::table('accounting_profiles')->where('tenant_id', $opening->tenant_id)->update(['cutover_date' => null, 'updated_at' => now()]);
        $this->audit->record('accounting.opening_balance.reversed', 'opening_balance', $opening->id, ['status' => OpeningBalance::POSTED], ['status' => OpeningBalance::REVERSED]);
    }
}
