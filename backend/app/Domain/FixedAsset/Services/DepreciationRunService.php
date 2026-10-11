<?php

namespace App\Domain\FixedAsset\Services;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\JournalEntry;
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
use App\Domain\FixedAsset\Models\DepreciationRun;
use App\Domain\FixedAsset\Models\DepreciationRunLine;
use App\Domain\FixedAsset\Models\DepreciationScheduleRow;
use App\Domain\FixedAsset\Models\FixedAsset;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The depreciation run (OA4 batch D): Eligible assets > Calculate (DRAFT, reviewed) > Post > one accounting event > Posting Engine.
 *
 *  - Eligible: ACTIVE assets (inside the user's data scope) whose schedule has PLANNED months up to the end of the chosen period. A month
 *    missed earlier is caught up by the next run; the months a line covers are claimed (PLANNED > IN_RUN) when the draft is calculated, so
 *    two runs can never hold the same asset-month, and a month already depreciated can never be depreciated again.
 *  - Posting locks the run, then its assets in a fixed order, re-checks everything under the locks and posts one journal through the
 *    engine (expense and accumulated depreciation per account, branch, unit and cost center). The journal, the schedule rows, the assets'
 *    accumulated depreciation and the run itself change in one transaction.
 *  - A posted run is immutable. Reversing it reverses its journal, frees its months and gives the accumulated depreciation back; the
 *    most recent run of an asset is reversed first, so an asset never has a gap in its depreciation.
 */
class DepreciationRunService
{
    public function __construct(
        private readonly DocumentWorkflow $workflow,
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
        $query = DepreciationRun::query()->with(['period', 'creator']);
        if (! $this->scope->isTenantWide()) {
            $query->where('asset_depreciation_runs.created_by', $this->context->user()?->id); // a run has no branch of its own: scoped users see their own runs
        }

        return $query
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('asset_depreciation_runs.status', $v))
            ->when($filter['accounting_period_id'] ?? null, fn ($q, $v) => $q->where('asset_depreciation_runs.accounting_period_id', $v))
            ->when($filter['posting_from'] ?? null, fn ($q, $v) => $q->whereDate('asset_depreciation_runs.posting_date', '>=', $v))
            ->when($filter['posting_to'] ?? null, fn ($q, $v) => $q->whereDate('asset_depreciation_runs.posting_date', '<=', $v))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(coalesce(asset_depreciation_runs.document_number, \'\')) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(asset_depreciation_runs.description, \'\')) like ?', [$like]));
            })
            ->orderByDesc('asset_depreciation_runs.posting_date')->orderByDesc('asset_depreciation_runs.created_at');
    }

    public function load(DepreciationRun $run): DepreciationRun
    {
        return $run->load(['period', 'creator', 'transitions.actor', 'lines.asset']);
    }

    public function sod(DepreciationRun $run, string $userId): array
    {
        return $this->sod->documentAllowed($run, $userId, $this->workflow->profile());
    }

    // ------------------------------------------------------------------------------------------------ calculate

    public function create(array $data, User $actor): DepreciationRun
    {
        return DB::transaction(function () use ($data, $actor) {
            $period = AccountingPeriod::query()->find($data['accounting_period_id'] ?? null)
                ?? throw new DomainException('The accounting period does not exist.', 'PERIOD_NOT_FOUND', 422, ['field' => 'accounting_period_id']);
            if ($period->status === AccountingPeriod::CLOSED) {
                throw new DomainException("Period {$period->code} is closed.", 'PERIOD_CLOSED', 422, ['period' => $period->code]);
            }
            if ($period->status === AccountingPeriod::FUTURE) {
                throw new DomainException("Period {$period->code} is not open yet.", 'PERIOD_NOT_OPEN', 422, ['period' => $period->code]);
            }
            $postingDate = substr((string) ($data['posting_date'] ?? $period->end_date->toDateString()), 0, 10);
            if ($postingDate < $period->start_date->toDateString() || $postingDate > $period->end_date->toDateString()) {
                throw new DomainException('The posting date must lie in the chosen period.', 'DEPRECIATION_DATE_INVALID', 422, ['field' => 'posting_date', 'period' => $period->code]);
            }

            $until = $period->end_date->toDateString();
            $eligible = FixedAsset::query()->where('fixed_assets.status', FixedAsset::ACTIVE)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('asset_depreciation_schedules as s')->whereColumn('s.fixed_asset_id', 'fixed_assets.id')
                    ->where('s.status', DepreciationScheduleRow::PLANNED)->where('s.period_end', '<=', $until));
            $this->scope->restrict($eligible->getQuery(), 'fixed_assets');
            $assets = $eligible->orderBy('fixed_assets.id')->lockForUpdate()->get(); // the same order everywhere: concurrent runs and disposals queue instead of deadlocking
            if ($assets->isEmpty()) {
                throw new DomainException("There is nothing to depreciate up to {$period->code}.", 'DEPRECIATION_NOTHING_ELIGIBLE', 422, ['period' => $period->code]);
            }

            $run = new DepreciationRun(['description' => $data['description'] ?? null, 'reference' => $data['reference'] ?? null]);
            $run->forceFill(['accounting_period_id' => $period->id, 'posting_date' => $postingDate, 'status' => DepreciationRun::DRAFT, 'created_by' => $actor->id]);
            $run->save();

            $lines = [];
            $claims = [];
            $total = BigDecimal::zero();
            $number = 0;
            foreach ($assets as $asset) {
                $rows = DepreciationScheduleRow::query()->where('fixed_asset_id', $asset->id)->where('status', DepreciationScheduleRow::PLANNED)
                    ->where('period_end', '<=', $until)->orderBy('sequence_no')->get(['id', 'amount']);
                if ($rows->isEmpty()) {
                    continue; // a concurrent run took this asset's months while this one waited for the asset's lock
                }
                $amount = Money::sum($rows->pluck('amount'));
                $before = BigDecimal::of($asset->accumulated_depreciation);
                $lineId = (string) Str::uuid7();
                $lines[] = [
                    'id' => $lineId, 'tenant_id' => $run->tenant_id, 'run_id' => $run->id, 'line_number' => ++$number, 'fixed_asset_id' => $asset->id, 'schedule_rows' => $rows->count(),
                    'amount' => Money::str($amount), 'accumulated_before' => Money::str($before), 'accumulated_after' => Money::str($before->plus($amount)),
                    'book_value_after' => Money::str(BigDecimal::of($asset->acquisition_cost)->minus($before)->minus($amount)),
                    'expense_account_id' => $asset->expense_account_id, 'accumulated_account_id' => $asset->accumulated_account_id,
                    'branch_id' => $asset->branch_id, 'business_unit_id' => $asset->business_unit_id, 'cost_center_id' => $asset->cost_center_id,
                    'created_at' => now(), 'updated_at' => now(),
                ];
                $claims[$lineId] = $rows->pluck('id')->all();
                $total = $total->plus($amount);
            }
            if ($lines === []) {
                throw new DomainException("There is nothing to depreciate up to {$period->code}.", 'DEPRECIATION_NOTHING_ELIGIBLE', 422, ['period' => $period->code]);
            }
            foreach (array_chunk($lines, 200) as $chunk) {
                DB::table('asset_depreciation_run_lines')->insert($chunk);
            }
            foreach ($claims as $lineId => $rowIds) {
                $taken = DB::table('asset_depreciation_schedules')->whereIn('id', $rowIds)->where('status', DepreciationScheduleRow::PLANNED)
                    ->update(['status' => DepreciationScheduleRow::IN_RUN, 'run_line_id' => $lineId, 'updated_at' => now()]);
                if ($taken !== count($rowIds)) {
                    throw new DomainException('A schedule month was taken by another run.', 'DEPRECIATION_ROWS_TAKEN', 409);
                }
            }
            $run->forceFill(['total_amount' => Money::str($total), 'asset_count' => count($lines)])->save();
            $this->workflow->created($run, $actor->id);
            $this->audit->record('fixed_asset.depreciation_run.created', 'depreciation_run', $run->id, null, $this->summary($run->refresh()));

            return $this->load($run);
        });
    }

    /** Cancel a draft: its months go back to PLANNED, so another run can take them. */
    public function cancel(DepreciationRun $run, User $actor, string $reason): DepreciationRun
    {
        return DB::transaction(function () use ($run, $actor, $reason) {
            $run = DepreciationRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->status !== DepreciationRun::DRAFT) {
                throw new DomainException('Only a draft depreciation run can be cancelled; a posted run is reversed.', 'DEPRECIATION_RUN_NOT_DRAFT', 409, ['status' => $run->status]);
            }
            DB::table('asset_depreciation_schedules')->where('tenant_id', $run->tenant_id)->where('status', DepreciationScheduleRow::IN_RUN)
                ->whereIn('run_line_id', DepreciationRunLine::query()->where('run_id', $run->id)->select('id'))
                ->update(['status' => DepreciationScheduleRow::PLANNED, 'run_line_id' => null, 'updated_at' => now()]);

            return $this->load($this->workflow->cancel($run, $actor, $reason));
        });
    }

    // ------------------------------------------------------------------------------------------------ posting

    public function post(DepreciationRun $run, User $actor): DepreciationRun
    {
        return DB::transaction(function () use ($run, $actor) {
            $run = DepreciationRun::query()->lockForUpdate()->findOrFail($run->id);
            $this->workflow->assertMayPost($run, $actor, approvalFlow: false); // the calculated draft is the review step; no approval workflow
            $this->authority->assertLedgerWritable($actor);

            $lines = DepreciationRunLine::query()->where('run_id', $run->id)->orderBy('line_number')->get();
            $assets = FixedAsset::query()->whereIn('id', $lines->pluck('fixed_asset_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $line) {
                $asset = $assets[$line->fixed_asset_id] ?? throw new DomainException('An asset of this run no longer exists.', 'ASSET_NOT_FOUND', 422);
                if ($asset->status !== FixedAsset::ACTIVE) {
                    throw new DomainException("Asset {$asset->asset_number} is {$asset->status} and can no longer be depreciated; cancel this run and calculate it again.", 'DEPRECIATION_ASSET_NOT_ACTIVE', 409, ['asset' => $asset->asset_number, 'status' => $asset->status]);
                }
                if (! BigDecimal::of($asset->accumulated_depreciation)->isEqualTo($line->accumulated_before)) {
                    throw new DomainException("Asset {$asset->asset_number} changed since this run was calculated; cancel it and calculate again.", 'DEPRECIATION_RUN_STALE', 409, ['asset' => $asset->asset_number]);
                }
            }
            $held = DepreciationScheduleRow::query()->whereIn('run_line_id', $lines->pluck('id'))->where('status', DepreciationScheduleRow::IN_RUN)->count();
            if ($held !== (int) $lines->sum('schedule_rows')) {
                throw new DomainException('The schedule months of this run changed; cancel it and calculate again.', 'DEPRECIATION_RUN_STALE', 409);
            }

            $postingDate = $run->posting_date->toDateString();
            $this->readiness->assertCanPost($postingDate, JournalEntry::SYSTEM);
            $period = $this->periods->resolveForPosting($postingDate, $this->authority->canPostSoftClosed($actor));

            $total = Money::sum($lines->pluck('amount'));
            $event = $this->engine->postEvent(
                'DEPRECIATION_RECOGNIZED', DepreciationRun::DOCUMENT_TYPE, $run->id, $postingDate, ['amount' => Money::str($total), 'distribution' => $this->distribution($lines)],
                [], 'POST', mb_substr("Penyusutan aset periode {$period->code}", 0, 500), $run->reference, $actor, $this->authority->canPostSoftClosed($actor),
            );
            $journal = JournalEntry::query()->findOrFail($event->journal_entry_id);
            if (! BigDecimal::of((string) $journal->total_debit)->isEqualTo($total)) {
                throw new DomainException('The depreciation journal does not match the run.', 'DEPRECIATION_JOURNAL_MISMATCH', 500, ['journal' => $journal->journal_number]);
            }

            $posted = DB::table('asset_depreciation_schedules')->where('tenant_id', $run->tenant_id)->where('status', DepreciationScheduleRow::IN_RUN)
                ->whereIn('run_line_id', $lines->pluck('id'))->update(['status' => DepreciationScheduleRow::POSTED, 'updated_at' => now()]);
            if ($posted !== $held) {
                throw new DomainException('The schedule months of this run changed; nothing was posted.', 'DEPRECIATION_RUN_STALE', 409);
            }
            foreach ($lines as $line) {
                $asset = $assets[$line->fixed_asset_id];
                $full = BigDecimal::of($line->accumulated_after)->isEqualTo($asset->depreciable_basis);
                $asset->forceFill(['accumulated_depreciation' => $line->accumulated_after, 'status' => $full ? FixedAsset::FULLY_DEPRECIATED : FixedAsset::ACTIVE])->save();
                if ($full) {
                    $this->workflow->log($asset, FixedAsset::ACTIVE, FixedAsset::FULLY_DEPRECIATED, $actor->id);
                }
            }

            return $this->load($this->workflow->markPosted($run, $actor, $journal, $event, 'DEPRECIATION_RUN', 'DEP'));
        });
    }

    public function reverse(DepreciationRun $run, User $actor, string $reason, ?string $postingDate = null): DepreciationRun
    {
        return DB::transaction(function () use ($run, $actor, $reason, $postingDate) {
            $run = DepreciationRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($run->status !== DepreciationRun::POSTED) {
                throw new DomainException($run->status === DepreciationRun::REVERSED ? 'This depreciation run has already been reversed.' : 'Only a posted depreciation run can be reversed.', $run->status === DepreciationRun::REVERSED ? 'DEPRECIATION_RUN_ALREADY_REVERSED' : 'DEPRECIATION_RUN_NOT_POSTED', 409, ['status' => $run->status]);
            }
            $this->authority->assertLedgerWritable($actor);

            $lines = DepreciationRunLine::query()->where('run_id', $run->id)->orderBy('line_number')->get();
            $assets = FixedAsset::query()->whereIn('id', $lines->pluck('fixed_asset_id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $line) {
                $asset = $assets[$line->fixed_asset_id];
                if (! in_array($asset->status, FixedAsset::ON_BOOKS, true)) {
                    throw new DomainException("Asset {$asset->asset_number} is {$asset->status}; reverse its disposal before reversing this run.", 'DEPRECIATION_ASSET_NOT_ON_BOOKS', 409, ['asset' => $asset->asset_number, 'status' => $asset->status]);
                }
                $last = DepreciationScheduleRow::query()->where('run_line_id', $line->id)->max('sequence_no');
                $later = DepreciationScheduleRow::query()->where('fixed_asset_id', $asset->id)->where('status', DepreciationScheduleRow::POSTED)->where('sequence_no', '>', $last)->exists();
                if ($later) {
                    throw new DomainException("Asset {$asset->asset_number} was depreciated in a later run; reverse that run first.", 'DEPRECIATION_RUN_NOT_LAST', 409, ['asset' => $asset->asset_number]);
                }
            }

            $original = JournalEntry::query()->findOrFail($run->journal_entry_id);
            $reversal = $this->reversals->reverse($original, $actor, $reason, $postingDate, $run->document_number);
            $this->workflow->markReversed($run, $actor, $reversal, $reason);

            DB::table('asset_depreciation_schedules')->where('tenant_id', $run->tenant_id)->where('status', DepreciationScheduleRow::POSTED)
                ->whereIn('run_line_id', $lines->pluck('id'))->update(['status' => DepreciationScheduleRow::PLANNED, 'run_line_id' => null, 'updated_at' => now()]);
            foreach ($lines as $line) {
                $asset = $assets[$line->fixed_asset_id];
                $from = $asset->status;
                $asset->forceFill(['accumulated_depreciation' => $line->accumulated_before, 'status' => FixedAsset::ACTIVE])->save();
                if ($from !== FixedAsset::ACTIVE) {
                    $this->workflow->log($asset, $from, FixedAsset::ACTIVE, $actor->id, $reason);
                }
            }

            return $this->load($run->refresh());
        });
    }

    // ------------------------------------------------------------------------------------------------ internals

    /**
     * The spread of the run's total over the accounts and dimensions of its assets, for both sides of the rule (the event names the expense and
     * the accumulated depreciation role). Parts that share account, branch, unit and cost center are one journal line.
     *
     * @param  iterable<DepreciationRunLine>  $lines
     * @return array<string,list<array<string,mixed>>>
     */
    private function distribution(iterable $lines): array
    {
        $groups = ['expense' => [], 'accumulated' => []];
        foreach ($lines as $line) {
            foreach (['expense' => $line->expense_account_id, 'accumulated' => $line->accumulated_account_id] as $side => $accountId) {
                $key = implode('|', [$accountId, $line->branch_id, $line->business_unit_id, $line->cost_center_id]);
                $groups[$side][$key] ??= [
                    'amount' => BigDecimal::zero(), 'account_id' => $accountId, 'branch_id' => $line->branch_id, 'business_unit_id' => $line->business_unit_id,
                    'cost_center_id' => $line->cost_center_id, 'description' => $side === 'expense' ? 'Beban penyusutan' : 'Akumulasi penyusutan',
                ];
                $groups[$side][$key]['amount'] = $groups[$side][$key]['amount']->plus($line->amount);
            }
        }
        $parts = fn (array $group) => array_values(array_map(fn ($p) => ['amount' => Money::str($p['amount'])] + $p, $group));
        ksort($groups['expense']);
        ksort($groups['accumulated']);

        return ['amount@DEPRECIATION_EXPENSE' => $parts($groups['expense']), 'amount@ACCUMULATED_DEPRECIATION' => $parts($groups['accumulated'])];
    }

    private function summary(DepreciationRun $run): array
    {
        return $run->only(['document_number', 'accounting_period_id', 'posting_date', 'status', 'total_amount', 'asset_count']);
    }
}
