<?php

namespace App\Domain\FixedAsset\Services;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\AccountGuard;
use App\Domain\Accounting\Services\ActorAuthority;
use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Services\DocumentWorkflow;
use App\Domain\Accounting\Services\PeriodGuard;
use App\Domain\Accounting\Services\PostingEngine;
use App\Domain\Accounting\Services\ReadinessService;
use App\Domain\Accounting\Services\ReversalService;
use App\Domain\Accounting\Services\SegregationOfDuties;
use App\Domain\Accounting\Support\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\FixedAsset\Models\AssetDisposal;
use App\Domain\FixedAsset\Models\DepreciationScheduleRow;
use App\Domain\FixedAsset\Models\FixedAsset;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Asset disposal (OA4 batch E): sale, scrap or write-off of one asset as a source document with its own approval workflow.
 *
 *  - Posting takes a snapshot of the register (cost, accumulated depreciation, net book value), computes proceeds, gain or loss and posts
 *    them through the engine (event ASSET_DISPOSED): debit accumulated depreciation, proceeds and a loss, credit the asset and a gain.
 *  - The asset is never deleted and its depreciation history stays; it becomes DISPOSED and is no longer eligible for depreciation.
 *  - Depreciation must be caught up to the disposal date first (every month ending on or before it posted, nothing held by a draft run),
 *    so the accumulated depreciation the disposal removes is the one the ledger holds. The asset row lock serializes a disposal against a
 *    depreciation run posting the same asset.
 *  - Reversing a posted disposal puts the asset back on the books.
 */
class AssetDisposalService
{
    public function __construct(
        private readonly DocumentWorkflow $workflow,
        private readonly AccountGuard $accounts,
        private readonly DocumentScope $scope,
        private readonly ActorAuthority $authority,
        private readonly PostingEngine $engine,
        private readonly ReversalService $reversals,
        private readonly PeriodGuard $periods,
        private readonly ReadinessService $readiness,
        private readonly SegregationOfDuties $sod,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    // ------------------------------------------------------------------------------------------------ queries

    public function query(array $filter = []): Builder
    {
        $query = AssetDisposal::query()->with(['asset', 'branch', 'creator']);
        $this->scope->restrict($query->getQuery(), 'asset_disposals');

        return $query
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('asset_disposals.status', $v))
            ->when($filter['fixed_asset_id'] ?? null, fn ($q, $v) => $q->where('asset_disposals.fixed_asset_id', $v))
            ->when($filter['disposal_type'] ?? null, fn ($q, $v) => $q->where('asset_disposals.disposal_type', $v))
            ->when($filter['branch_id'] ?? null, fn ($q, $v) => $q->where('asset_disposals.branch_id', $v))
            ->when($filter['posting_from'] ?? null, fn ($q, $v) => $q->whereDate('asset_disposals.posting_date', '>=', $v))
            ->when($filter['posting_to'] ?? null, fn ($q, $v) => $q->whereDate('asset_disposals.posting_date', '<=', $v))
            ->when($filter['mine'] ?? false, fn ($q) => $q->where('asset_disposals.created_by', $this->context->user()?->id))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(coalesce(asset_disposals.document_number, \'\')) like ?', [$like])->orWhereRaw('lower(asset_disposals.reason) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(asset_disposals.reference, \'\')) like ?', [$like]));
            })
            ->orderByDesc('asset_disposals.posting_date')->orderByDesc('asset_disposals.created_at');
    }

    public function load(AssetDisposal $disposal): AssetDisposal
    {
        $disposal->load(['asset', 'proceedsAccount', 'branch', 'businessUnit', 'costCenter', 'creator', 'transitions.actor']);
        $disposal->setAttribute('preview', $this->figures($disposal));

        return $disposal;
    }

    public function sod(AssetDisposal $disposal, string $userId): array
    {
        return $this->sod->documentAllowed($disposal, $userId, $this->workflow->profile()) + ['approval_required' => $this->workflow->profile()->approval_required];
    }

    // ------------------------------------------------------------------------------------------------ draft

    public function create(array $data, User $actor): AssetDisposal
    {
        return DB::transaction(function () use ($data, $actor) {
            $prepared = $this->prepare($data, null, $actor);
            $disposal = new AssetDisposal;
            $disposal->forceFill($prepared);
            $disposal->status = AssetDisposal::DRAFT;
            $disposal->created_by = $actor->id;
            try {
                $disposal->save();
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? '') === '23505' && str_contains($e->getMessage(), 'asset_disposals_one_live')) {
                    throw new DomainException('This asset already has a disposal in progress or posted.', 'ASSET_DISPOSAL_EXISTS', 409);
                }
                throw $e;
            }
            $this->workflow->created($disposal, $actor->id);
            $this->audit->record('fixed_asset.disposal.created', 'asset_disposal', $disposal->id, null, $this->summary($disposal));

            return $this->load($disposal->refresh());
        });
    }

    public function update(AssetDisposal $disposal, array $data, User $actor): AssetDisposal
    {
        return DB::transaction(function () use ($disposal, $data, $actor) {
            $disposal = AssetDisposal::query()->lockForUpdate()->findOrFail($disposal->id);
            $this->assertDraft($disposal);
            $before = $this->summary($disposal);
            $disposal->forceFill($this->prepare($data, $disposal, $actor))->save();
            $this->audit->record('fixed_asset.disposal.updated', 'asset_disposal', $disposal->id, $before, $this->summary($disposal->refresh()));

            return $this->load($disposal);
        });
    }

    // ------------------------------------------------------------------------------------------------ workflow

    public function submit(AssetDisposal $disposal, User $actor): AssetDisposal
    {
        return $this->load($this->workflow->submit($disposal, $actor, fn (AssetDisposal $d) => $this->validateForPosting($d, $actor, false)));
    }

    public function approve(AssetDisposal $disposal, User $actor): AssetDisposal
    {
        return $this->load($this->workflow->approve($disposal, $actor, fn (AssetDisposal $d) => $this->validateForPosting($d, $actor, false)));
    }

    public function reject(AssetDisposal $disposal, User $actor, string $reason): AssetDisposal
    {
        return $this->load($this->workflow->reject($disposal, $actor, $reason));
    }

    public function reopen(AssetDisposal $disposal, User $actor): AssetDisposal
    {
        return $this->load($this->workflow->reopen($disposal, $actor));
    }

    public function cancel(AssetDisposal $disposal, User $actor, string $reason): AssetDisposal
    {
        return $this->load($this->workflow->cancel($disposal, $actor, $reason));
    }

    // ------------------------------------------------------------------------------------------------ posting

    public function post(AssetDisposal $disposal, User $actor): AssetDisposal
    {
        return DB::transaction(function () use ($disposal, $actor) {
            $disposal = AssetDisposal::query()->lockForUpdate()->findOrFail($disposal->id);
            $this->workflow->assertMayPost($disposal, $actor);
            $asset = $this->validateForPosting($disposal, $actor, true);

            $figures = $this->figures($disposal, $asset);
            $roles = array_filter([
                'FIXED_ASSET' => $asset->asset_account_id, 'ACCUMULATED_DEPRECIATION' => $asset->accumulated_account_id,
                'ASSET_DISPOSAL_GAIN_LOSS' => $asset->gain_loss_account_id, 'DOCUMENT_ACCOUNT' => $disposal->proceeds_account_id,
            ]);
            $dims = ['branch_id' => $asset->branch_id, 'business_unit_id' => $asset->business_unit_id, 'cost_center_id' => $asset->cost_center_id];

            $event = $this->engine->postEvent(
                'ASSET_DISPOSED', AssetDisposal::DOCUMENT_TYPE, $disposal->id, $disposal->posting_date->toDateString(),
                ['cost' => $figures['cost'], 'accumulated' => $figures['accumulated'], 'proceeds' => $figures['proceeds'], 'gain' => $figures['gain'], 'loss' => $figures['loss'], 'role_accounts' => $roles],
                $dims, 'POST', mb_substr("Pelepasan aset {$asset->asset_number}: {$disposal->reason}", 0, 500), $disposal->reference, $actor, $this->authority->canPostSoftClosed($actor),
            );
            $journal = JournalEntry::query()->findOrFail($event->journal_entry_id);
            $expected = BigDecimal::of($figures['cost'])->plus($figures['gain']);
            if (! BigDecimal::of((string) $journal->total_debit)->isEqualTo($expected)) {
                throw new DomainException('The disposal journal does not match the register.', 'ASSET_DISPOSAL_JOURNAL_MISMATCH', 500, ['journal' => $journal->journal_number]);
            }

            $this->workflow->markPosted($disposal, $actor, $journal, $event, 'ASSET_DISPOSAL', 'AD', [
                'cost_amount' => $figures['cost'], 'accumulated_depreciation' => $figures['accumulated'], 'book_value' => $figures['book_value'],
                'gain_amount' => $figures['gain'], 'loss_amount' => $figures['loss'],
            ]);
            $from = $asset->status;
            $asset->forceFill(['status' => FixedAsset::DISPOSED, 'disposed_on' => $disposal->disposal_date->toDateString(), 'disposal_id' => $disposal->id])->save();
            $this->workflow->log($asset, $from, FixedAsset::DISPOSED, $actor->id);

            return $this->load($disposal->refresh());
        });
    }

    public function reverse(AssetDisposal $disposal, User $actor, string $reason, ?string $postingDate = null): AssetDisposal
    {
        return DB::transaction(function () use ($disposal, $actor, $reason, $postingDate) {
            $disposal = AssetDisposal::query()->lockForUpdate()->findOrFail($disposal->id);
            if ($disposal->status !== AssetDisposal::POSTED) {
                throw new DomainException($disposal->status === AssetDisposal::REVERSED ? 'This disposal has already been reversed.' : 'Only a posted disposal can be reversed.', $disposal->status === AssetDisposal::REVERSED ? 'ASSET_DISPOSAL_ALREADY_REVERSED' : 'ASSET_DISPOSAL_NOT_POSTED', 409, ['status' => $disposal->status]);
            }
            $this->authority->assertLedgerWritable($actor);
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($disposal->fixed_asset_id);

            $original = JournalEntry::query()->findOrFail($disposal->journal_entry_id);
            $reversal = $this->reversals->reverse($original, $actor, $reason, $postingDate, $disposal->document_number);
            $this->workflow->markReversed($disposal, $actor, $reversal, $reason);

            $full = BigDecimal::of($asset->depreciable_basis)->isPositive() && BigDecimal::of($asset->accumulated_depreciation)->isEqualTo($asset->depreciable_basis);
            $to = $full ? FixedAsset::FULLY_DEPRECIATED : FixedAsset::ACTIVE;
            $asset->forceFill(['status' => $to, 'disposed_on' => null, 'disposal_id' => null])->save();
            $this->workflow->log($asset, FixedAsset::DISPOSED, $to, $actor->id, $reason);

            return $this->load($disposal->refresh());
        });
    }

    // ------------------------------------------------------------------------------------------------ validation

    /** Everything posting needs. With $lock the asset row is locked first, so the register is read as committed. Returns the asset. */
    private function validateForPosting(AssetDisposal $disposal, User $actor, bool $lock): FixedAsset
    {
        $this->authority->assertLedgerWritable($actor);
        $asset = FixedAsset::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($disposal->fixed_asset_id)
            ?? throw new DomainException('The asset no longer exists.', 'ASSET_NOT_FOUND', 422);
        if (! in_array($asset->status, FixedAsset::ON_BOOKS, true)) {
            throw new DomainException("Asset {$asset->asset_number} is {$asset->status} and cannot be disposed.", 'ASSET_NOT_DISPOSABLE', 409, ['status' => $asset->status]);
        }
        $disposalDate = $disposal->disposal_date->toDateString();
        if ($asset->capitalization_date->toDateString() > $disposalDate) {
            throw new DomainException('An asset cannot be disposed before its capitalization date.', 'ASSET_DISPOSAL_BEFORE_CAPITALIZATION', 422, ['capitalization_date' => $asset->capitalization_date->toDateString()]);
        }

        $held = DepreciationScheduleRow::query()->where('fixed_asset_id', $asset->id)->where('status', DepreciationScheduleRow::IN_RUN)->exists();
        if ($held) {
            throw new DomainException('A draft depreciation run holds months of this asset; post or cancel it first.', 'ASSET_DEPRECIATION_IN_RUN', 409);
        }
        $pending = DepreciationScheduleRow::query()->where('fixed_asset_id', $asset->id)->where('status', DepreciationScheduleRow::PLANNED)->where('period_end', '<=', $disposalDate);
        if ($pending->exists()) {
            throw new DomainException('Post the depreciation up to the disposal date before disposing of this asset.', 'ASSET_DEPRECIATION_PENDING', 409, [
                'first_pending_period' => substr((string) $pending->min('period_start'), 0, 10), 'pending_months' => $pending->count(),
            ]);
        }
        if ($disposal->proceeds_account_id !== null) {
            $this->accounts->usable($disposal->proceeds_account_id, ['ASSET'], false, 'proceeds_account_id');
        }

        $postingDate = $disposal->posting_date->toDateString();
        $this->readiness->assertCanPost($postingDate, JournalEntry::SYSTEM);
        $this->periods->resolveForPosting($postingDate, $this->authority->canPostSoftClosed($actor));

        return $asset;
    }

    /**
     * Cost, accumulated depreciation, net book value, proceeds and the resulting gain or loss, as decimal strings (a posted disposal returns
     * its stored snapshot). The register's current values are used until it posts.
     *
     * @return array{cost:string,accumulated:string,book_value:string,proceeds:string,gain:string,loss:string}
     */
    private function figures(AssetDisposal $disposal, ?FixedAsset $asset = null): array
    {
        if (in_array($disposal->status, [AssetDisposal::POSTED, AssetDisposal::REVERSED], true)) {
            return [
                'cost' => Money::str($disposal->cost_amount), 'accumulated' => Money::str($disposal->accumulated_depreciation), 'book_value' => Money::str($disposal->book_value),
                'proceeds' => Money::str($disposal->proceeds_amount), 'gain' => Money::str($disposal->gain_amount), 'loss' => Money::str($disposal->loss_amount),
            ];
        }
        $asset ??= FixedAsset::query()->find($disposal->fixed_asset_id);
        if (! $asset) {
            return ['cost' => '0.0000', 'accumulated' => '0.0000', 'book_value' => '0.0000', 'proceeds' => Money::str($disposal->proceeds_amount), 'gain' => '0.0000', 'loss' => '0.0000'];
        }
        $cost = BigDecimal::of($asset->acquisition_cost);
        $accumulated = BigDecimal::of($asset->accumulated_depreciation);
        $book = $cost->minus($accumulated);
        $proceeds = BigDecimal::of($disposal->proceeds_amount);
        $diff = $proceeds->minus($book);

        return [
            'cost' => Money::str($cost), 'accumulated' => Money::str($accumulated), 'book_value' => Money::str($book), 'proceeds' => Money::str($proceeds),
            'gain' => Money::str($diff->isPositive() ? $diff : BigDecimal::zero()), 'loss' => Money::str($diff->isNegative() ? $diff->negated() : BigDecimal::zero()),
        ];
    }

    /** @return array<string,mixed> */
    private function prepare(array $data, ?AssetDisposal $existing, User $actor): array
    {
        $profile = $this->workflow->profile();
        $scale = (int) $profile->currency_scale;
        $field = fn (string $key, mixed $default = null) => array_key_exists($key, $data) ? $data[$key] : ($existing?->{$key} ?? $default);
        $date = fn (mixed $v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : ($v === null ? null : substr((string) $v, 0, 10));

        $assetId = $data['fixed_asset_id'] ?? $existing?->fixed_asset_id ?? throw new DomainException('The asset is required.', 'ASSET_REQUIRED', 422, ['field' => 'fixed_asset_id']);
        $asset = FixedAsset::query()->find($assetId);
        if ($asset === null || ! $this->scope->visible($asset)) {
            throw new DomainException('The asset does not exist.', 'ASSET_NOT_FOUND', 422, ['field' => 'fixed_asset_id']);
        }
        if ($existing !== null && $existing->fixed_asset_id !== $asset->id) {
            throw new DomainException('The asset of a disposal cannot be changed; create a new disposal.', 'ASSET_DISPOSAL_ASSET_LOCKED', 422, ['field' => 'fixed_asset_id']);
        }
        if (! in_array($asset->status, FixedAsset::ON_BOOKS, true)) {
            throw new DomainException("Asset {$asset->asset_number} is {$asset->status} and cannot be disposed.", 'ASSET_NOT_DISPOSABLE', 409, ['status' => $asset->status]);
        }
        $this->scope->assertWritable($asset->branch_id, $asset->business_unit_id, $existing?->created_by ?? $actor->id);

        $type = $field('disposal_type');
        if (! in_array($type, ['SALE', 'SCRAP'], true)) {
            throw new DomainException('The disposal type must be SALE or SCRAP.', 'ASSET_DISPOSAL_TYPE_INVALID', 422, ['field' => 'disposal_type']);
        }
        $disposalDate = $date($field('disposal_date')) ?? throw new DomainException('The disposal date is required.', 'DOCUMENT_DATE_REQUIRED', 422, ['field' => 'disposal_date']);
        if ($disposalDate < $asset->capitalization_date->toDateString()) {
            throw new DomainException('An asset cannot be disposed before its capitalization date.', 'ASSET_DISPOSAL_BEFORE_CAPITALIZATION', 422, ['field' => 'disposal_date']);
        }
        $documentDate = $date($field('document_date')) ?? $disposalDate;
        $postingDate = $date(array_key_exists('posting_date', $data) && $data['posting_date'] !== null ? $data['posting_date'] : ($existing?->posting_date ?? $disposalDate));
        if ($postingDate < $disposalDate) {
            throw new DomainException('The posting date cannot be before the disposal date.', 'ASSET_DISPOSAL_DATE_INVALID', 422, ['field' => 'posting_date']);
        }

        $proceeds = Money::parse($field('proceeds_amount', 0), $scale, 'proceeds_amount');
        if ($type === 'SALE' && ! $proceeds->isPositive()) {
            throw new DomainException('A sale needs proceeds greater than zero; use SCRAP for a write-off.', 'ASSET_DISPOSAL_PROCEEDS_REQUIRED', 422, ['field' => 'proceeds_amount']);
        }
        $account = $field('proceeds_account_id');
        if ($proceeds->isPositive()) {
            $this->accounts->usable($account, ['ASSET'], false, 'proceeds_account_id');
        } else {
            $account = null;
        }
        $reason = trim((string) $field('reason'));
        if ($reason === '') {
            throw new DomainException('The reason is required.', 'REASON_REQUIRED', 422, ['field' => 'reason']);
        }

        return [
            'fixed_asset_id' => $asset->id, 'disposal_type' => $type, 'document_date' => $documentDate, 'disposal_date' => $disposalDate, 'posting_date' => $postingDate,
            'proceeds_amount' => Money::str($proceeds), 'proceeds_account_id' => $account, 'reason' => mb_substr($reason, 0, 500), 'reference' => $field('reference'),
            'branch_id' => $asset->branch_id, 'business_unit_id' => $asset->business_unit_id, 'cost_center_id' => $asset->cost_center_id,
        ];
    }

    private function assertDraft(AssetDisposal $disposal): void
    {
        if ($disposal->status !== AssetDisposal::DRAFT) {
            throw new DomainException('Only a draft disposal can be edited.', 'DOCUMENT_NOT_EDITABLE', 409, ['status' => $disposal->status]);
        }
    }

    private function summary(AssetDisposal $disposal): array
    {
        return $disposal->only(['document_number', 'fixed_asset_id', 'disposal_type', 'status', 'disposal_date', 'posting_date', 'proceeds_amount', 'proceeds_account_id']);
    }
}
