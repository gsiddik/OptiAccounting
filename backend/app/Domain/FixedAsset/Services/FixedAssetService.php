<?php

namespace App\Domain\FixedAsset\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\AccountGuard;
use App\Domain\Accounting\Services\AccountMappingService;
use App\Domain\Accounting\Services\ActorAuthority;
use App\Domain\Accounting\Services\DimensionGuard;
use App\Domain\Accounting\Services\DocumentNumbering;
use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Services\DocumentWorkflow;
use App\Domain\Accounting\Services\PeriodGuard;
use App\Domain\Accounting\Services\PostingEngine;
use App\Domain\Accounting\Services\ReadinessService;
use App\Domain\Accounting\Services\ReversalService;
use App\Domain\Accounting\Services\SegregationOfDuties;
use App\Domain\Accounting\Support\Money;
use App\Domain\Audit\Services\AuditService;
use App\Domain\FixedAsset\Models\AssetCategory;
use App\Domain\FixedAsset\Models\DepreciationScheduleRow;
use App\Domain\FixedAsset\Models\FixedAsset;
use App\Domain\FixedAsset\Services\Depreciation\DepreciationMethods;
use App\Domain\FixedAsset\Services\Depreciation\ScheduleBuilder;
use App\Domain\Identity\Models\User;
use App\Domain\Payables\Models\ApInvoice;
use App\Domain\Payables\Models\ApInvoiceLine;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The fixed asset register and capitalization (OA4 batch C). The register is subledger metadata; the general ledger stays the financial
 * truth and every journal comes from the Posting Engine (event ASSET_CAPITALIZED: debit the asset account, credit the source account).
 *
 *  - An asset is a DRAFT until it is capitalized. Capitalization posts exactly once (a single locked transition, one journal), resolves its
 *    accounts through the category and the role mapping, freezes them with the terms and the schedule snapshot on the asset, and generates
 *    the deterministic depreciation schedule.
 *  - Cost that is already in the ledger through a posted AP invoice line is registered without a second journal (mode REGISTER_ONLY), so the
 *    same cost can never be recognised twice; the line's account is the asset account and the cost taken from one line never exceeds it.
 *  - A capitalization is undone only by reversing it, and only while nothing has been depreciated or disposed; the asset then ends INACTIVE.
 */
class FixedAssetService
{
    public const POST_ACCOUNT_TYPES = ['ASSET', 'LIABILITY', 'EQUITY'];

    public function __construct(
        private readonly DocumentWorkflow $workflow,
        private readonly AssetCategoryService $categories,
        private readonly AccountGuard $accounts,
        private readonly AccountMappingService $mappings,
        private readonly DimensionGuard $dimensions,
        private readonly DocumentScope $scope,
        private readonly ActorAuthority $authority,
        private readonly PostingEngine $engine,
        private readonly ReversalService $reversals,
        private readonly PeriodGuard $periods,
        private readonly ReadinessService $readiness,
        private readonly SegregationOfDuties $sod,
        private readonly DocumentNumbering $numbering,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    // ------------------------------------------------------------------------------------------------ queries

    public function query(array $filter = []): Builder
    {
        $query = FixedAsset::query()->with(['category', 'branch', 'creator']);
        $this->scope->restrict($query->getQuery(), 'fixed_assets');

        return $query
            ->when($filter['status'] ?? null, fn ($q, $v) => $q->where('fixed_assets.status', $v))
            ->when($filter['asset_category_id'] ?? null, fn ($q, $v) => $q->where('fixed_assets.asset_category_id', $v))
            ->when($filter['branch_id'] ?? null, fn ($q, $v) => $q->where('fixed_assets.branch_id', $v))
            ->when($filter['business_unit_id'] ?? null, fn ($q, $v) => $q->where('fixed_assets.business_unit_id', $v))
            ->when($filter['cost_center_id'] ?? null, fn ($q, $v) => $q->where('fixed_assets.cost_center_id', $v))
            ->when($filter['capitalized_from'] ?? null, fn ($q, $v) => $q->whereDate('fixed_assets.capitalization_date', '>=', $v))
            ->when($filter['capitalized_to'] ?? null, fn ($q, $v) => $q->whereDate('fixed_assets.capitalization_date', '<=', $v))
            ->when($filter['mine'] ?? false, fn ($q) => $q->where('fixed_assets.created_by', $this->context->user()?->id))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(coalesce(fixed_assets.asset_number, \'\')) like ?', [$like])->orWhereRaw('lower(fixed_assets.name) like ?', [$like])
                    ->orWhereRaw('lower(coalesce(fixed_assets.source_reference, \'\')) like ?', [$like]));
            })
            ->orderByDesc('fixed_assets.capitalization_date')->orderByDesc('fixed_assets.created_at');
    }

    public function load(FixedAsset $asset): FixedAsset
    {
        $asset->load(['category', 'branch', 'businessUnit', 'costCenter', 'creator', 'sourceAccount', 'assetAccount', 'accumulatedAccount', 'expenseAccount', 'gainLossAccount', 'transitions.actor']);
        $asset->setAttribute('net_book_value', $this->netBookValue($asset));
        $asset->setAttribute('schedule_summary', $this->scheduleSummary($asset));

        return $asset;
    }

    public function sod(FixedAsset $asset, string $userId): array
    {
        return ['capitalize' => $this->sod->documentAllowed($asset, $userId, $this->workflow->profile())['post']];
    }

    /** The stored schedule of a capitalized asset, or the computed preview of a draft's terms. @return array{preview:bool,rows:list<array<string,mixed>>} */
    public function schedule(FixedAsset $asset): array
    {
        if ($asset->status === FixedAsset::DRAFT) {
            $strategy = DepreciationMethods::for($asset->method);

            return ['preview' => true, 'rows' => ScheduleBuilder::build(
                $asset->method, (string) $asset->acquisition_cost, (string) $asset->residual_value, $asset->useful_life_months, $asset->capitalization_date->toDateString(),
                $asset->start_policy, (int) $this->workflow->profile()->currency_scale, $strategy->params($asset->method_params),
            )];
        }

        return ['preview' => false, 'rows' => DepreciationScheduleRow::query()->where('fixed_asset_id', $asset->id)->orderBy('sequence_no')->get()
            ->map(fn ($r) => $r->only(['id', 'sequence_no', 'period_start', 'period_end', 'amount', 'accumulated_after', 'book_value_after', 'status']))->all()];
    }

    // ------------------------------------------------------------------------------------------------ draft

    public function create(array $data, User $actor): FixedAsset
    {
        return DB::transaction(function () use ($data, $actor) {
            $asset = new FixedAsset;
            $asset->forceFill($this->prepare($data, null, $actor));
            $asset->status = FixedAsset::DRAFT;
            $asset->currency = $this->workflow->profile()->functional_currency;
            $asset->created_by = $actor->id;
            $asset->save();
            $this->workflow->log($asset, null, FixedAsset::DRAFT, $actor->id);
            $this->audit->record('fixed_asset.asset.created', 'fixed_asset', $asset->id, null, $this->summary($asset));

            return $this->load($asset->refresh());
        });
    }

    public function update(FixedAsset $asset, array $data, User $actor): FixedAsset
    {
        return DB::transaction(function () use ($asset, $data, $actor) {
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);
            $before = $this->summary($asset);

            if ($asset->status === FixedAsset::INACTIVE) {
                throw new DomainException('A discarded or reversed asset can no longer be edited.', 'ASSET_INACTIVE', 409, ['status' => $asset->status]);
            }
            if ($asset->status !== FixedAsset::DRAFT) {
                // A capitalized asset keeps its financial terms (the database refuses a change); only its labels can be edited.
                if (array_diff(array_keys($data), ['name', 'description']) !== []) {
                    throw new DomainException('The financial terms of a capitalized asset cannot be changed.', 'ASSET_NOT_EDITABLE', 409, ['status' => $asset->status]);
                }
                $asset->forceFill(array_intersect_key($data, array_flip(['name', 'description'])))->save();
            } else {
                $asset->forceFill($this->prepare($data, $asset, $actor))->save();
            }
            $this->audit->record('fixed_asset.asset.updated', 'fixed_asset', $asset->id, $before, $this->summary($asset->refresh()));

            return $this->load($asset);
        });
    }

    /** A draft nobody capitalized is put aside (INACTIVE), never deleted from the history. */
    public function discard(FixedAsset $asset, User $actor, string $reason): FixedAsset
    {
        return DB::transaction(function () use ($asset, $actor, $reason) {
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);
            if ($asset->status !== FixedAsset::DRAFT) {
                throw new DomainException('Only a draft asset can be discarded; a capitalized asset is undone by reversing its capitalization.', 'ASSET_NOT_DRAFT', 409, ['status' => $asset->status]);
            }
            $asset->forceFill(['status' => FixedAsset::INACTIVE, 'inactive_reason' => $reason])->save();
            $this->workflow->log($asset, FixedAsset::DRAFT, FixedAsset::INACTIVE, $actor->id, $reason);
            $this->audit->record('fixed_asset.asset.discarded', 'fixed_asset', $asset->id, ['status' => FixedAsset::DRAFT], ['status' => FixedAsset::INACTIVE, 'reason' => $reason]);

            return $this->load($asset);
        });
    }

    // ------------------------------------------------------------------------------------------------ capitalization

    public function capitalize(FixedAsset $asset, User $actor): FixedAsset
    {
        return DB::transaction(function () use ($asset, $actor) {
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);
            if ($asset->status !== FixedAsset::DRAFT) {
                throw new DomainException($asset->status === FixedAsset::INACTIVE ? 'This asset was discarded.' : 'This asset has already been capitalized.', $asset->status === FixedAsset::INACTIVE ? 'ASSET_INACTIVE' : 'ASSET_ALREADY_CAPITALIZED', 409, ['status' => $asset->status]);
            }
            $this->authority->assertLedgerWritable($actor);
            $profile = $this->workflow->profile();
            $this->sod->assertDocumentMayPost($asset, $actor->id, $profile);

            $category = $this->categories->usable($asset->asset_category_id);
            $strategy = DepreciationMethods::for($asset->method);
            $scale = (int) $profile->currency_scale;
            $date = $asset->capitalization_date->toDateString();
            $cost = Money::parse($asset->acquisition_cost, $scale, 'acquisition_cost');
            $dims = ['branch_id' => $asset->branch_id, 'business_unit_id' => $asset->business_unit_id, 'cost_center_id' => $asset->cost_center_id];
            $this->dimensions->resolve($asset->branch_id, $asset->business_unit_id, $asset->cost_center_id);

            // The accounts are resolved once, here, and frozen on the asset.
            $line = null;
            if ($asset->capitalization_mode === 'REGISTER_ONLY') {
                $line = $this->assertApLine((string) $asset->source_id, $cost, $asset->id, true);
                $assetAccount = $this->accounts->usable($line->account_id, ['ASSET'], false, 'source_id');
            } else {
                $assetAccount = $this->accountFor('FIXED_ASSET', $category->asset_account_id, ['ASSET'], $asset->branch_id, $asset->business_unit_id, 'asset_account_id');
                if ($asset->source_account_id === null) {
                    throw new DomainException('Name the account the cost is credited to (payable, bank or clearing) before capitalizing.', 'ASSET_SOURCE_ACCOUNT_REQUIRED', 422, ['field' => 'source_account_id']);
                }
                $this->accounts->usable($asset->source_account_id, self::POST_ACCOUNT_TYPES, false, 'source_account_id');
            }
            $accumulated = $expense = null;
            if ($strategy->needsLife()) {
                $accumulated = $this->accountFor('ACCUMULATED_DEPRECIATION', $category->accumulated_account_id, ['ASSET'], $asset->branch_id, $asset->business_unit_id, 'accumulated_account_id');
                $expense = $this->accountFor('DEPRECIATION_EXPENSE', $category->expense_account_id, ['EXPENSE'], $asset->branch_id, $asset->business_unit_id, 'expense_account_id');
            }
            $gainLoss = $category->gain_loss_account_id ? $this->accounts->usable($category->gain_loss_account_id, ['REVENUE', 'EXPENSE'], false, 'gain_loss_account_id') : null;

            $this->readiness->assertCanPost($date, JournalEntry::SYSTEM);
            $this->periods->resolveForPosting($date, $this->authority->canPostSoftClosed($actor));

            $journal = $event = null;
            if ($asset->capitalization_mode === 'POST') {
                $event = $this->engine->postEvent(
                    'ASSET_CAPITALIZED', FixedAsset::DOCUMENT_TYPE, $asset->id, $date,
                    ['cost' => Money::str($cost), 'role_accounts' => ['FIXED_ASSET' => $assetAccount->id, 'DOCUMENT_ACCOUNT' => $asset->source_account_id]],
                    $dims, 'POST', mb_substr("Kapitalisasi aset: {$asset->name}", 0, 500), $asset->source_reference, $actor, $this->authority->canPostSoftClosed($actor),
                );
                $journal = JournalEntry::query()->findOrFail($event->journal_entry_id);
                $this->assertJournal($journal, $cost, $assetAccount->id);
            }

            $year = $journal ? FiscalYear::query()->findOrFail($journal->fiscal_year_id) : $this->fiscalYearOf($date);
            $residual = Money::parse($asset->residual_value, $scale, 'residual_value');
            $params = $strategy->params($asset->method_params);
            $rows = ScheduleBuilder::build($asset->method, Money::str($cost), Money::str($residual), $asset->useful_life_months, $date, $asset->start_policy, $scale, $params);
            $snapshot = [
                'version' => 1, 'method' => $asset->method, 'method_params' => $params, 'start_policy' => $asset->start_policy, 'cost' => Money::str($cost), 'residual' => Money::str($residual),
                'basis' => Money::str($cost->minus($residual)), 'life_months' => $asset->useful_life_months, 'capitalization_date' => $date, 'currency_scale' => $scale,
                'rounding' => 'HALF_UP', 'rows' => count($rows), 'first_period' => $rows[0]['period_start'] ?? null, 'last_period' => $rows ? end($rows)['period_end'] : null,
                'total' => Money::str(Money::sum(array_column($rows, 'amount'))),
            ];

            $asset->forceFill([
                'status' => FixedAsset::ACTIVE, 'asset_number' => $this->numbering->issue('FIXED_ASSET', $year->id, 'FA', $year->code),
                'asset_account_id' => $assetAccount->id, 'accumulated_account_id' => $accumulated?->id, 'expense_account_id' => $expense?->id, 'gain_loss_account_id' => $gainLoss?->id,
                'depreciable_basis' => Money::str($cost->minus($residual)), 'schedule_snapshot' => $snapshot,
                'capitalized_by' => $actor->id, 'capitalized_at' => now(), 'capitalization_journal_id' => $journal?->id, 'capitalization_event_id' => $event?->id,
            ])->save();
            $this->insertSchedule($asset, $rows);
            $this->workflow->log($asset, FixedAsset::DRAFT, FixedAsset::ACTIVE, $actor->id);
            $this->audit->record('fixed_asset.asset.capitalized', 'fixed_asset', $asset->id, ['status' => FixedAsset::DRAFT], [
                'status' => FixedAsset::ACTIVE, 'asset_number' => $asset->asset_number, 'mode' => $asset->capitalization_mode, 'cost' => Money::str($cost),
                'journal_number' => $journal?->journal_number, 'capitalization_date' => $date, 'schedule_rows' => count($rows), 'schedule_total' => $snapshot['total'],
            ]);

            return $this->load($asset->refresh());
        });
    }

    /** Undo a capitalization by reversal. Allowed only while nothing was depreciated, held by a run, or disposed. */
    public function reverseCapitalization(FixedAsset $asset, User $actor, string $reason, ?string $postingDate = null): FixedAsset
    {
        return DB::transaction(function () use ($asset, $actor, $reason, $postingDate) {
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);
            if ($asset->status !== FixedAsset::ACTIVE) {
                throw new DomainException('Only an active asset that has not been depreciated or disposed can have its capitalization reversed.', 'ASSET_CAPITALIZATION_NOT_REVERSIBLE', 409, ['status' => $asset->status]);
            }
            $this->authority->assertLedgerWritable($actor);
            $touched = DepreciationScheduleRow::query()->where('fixed_asset_id', $asset->id)->whereNotIn('status', [DepreciationScheduleRow::PLANNED])->exists();
            if ($touched || BigDecimal::of($asset->accumulated_depreciation)->isPositive()) {
                throw new DomainException('Depreciation has been posted or is held by a depreciation run; reverse that first.', 'ASSET_HAS_DEPRECIATION', 409);
            }

            $reversal = null;
            if ($asset->capitalization_mode === 'POST') {
                $original = JournalEntry::query()->findOrFail($asset->capitalization_journal_id);
                $reversal = $this->reversals->reverse($original, $actor, $reason, $postingDate, $asset->asset_number);
            }
            DB::table('asset_depreciation_schedules')->where('tenant_id', $asset->tenant_id)->where('fixed_asset_id', $asset->id)->where('status', DepreciationScheduleRow::PLANNED)
                ->update(['status' => DepreciationScheduleRow::CANCELLED, 'updated_at' => now()]);
            $asset->forceFill([
                'status' => FixedAsset::INACTIVE, 'inactive_reason' => $reason, 'capitalization_reversal_journal_id' => $reversal?->id,
                'capitalization_reversed_by' => $actor->id, 'capitalization_reversed_at' => now(), 'capitalization_reversal_reason' => $reason,
            ])->save();
            $this->workflow->log($asset, FixedAsset::ACTIVE, FixedAsset::INACTIVE, $actor->id, $reason);
            $this->audit->record('fixed_asset.asset.capitalization_reversed', 'fixed_asset', $asset->id, ['status' => FixedAsset::ACTIVE], [
                'status' => FixedAsset::INACTIVE, 'asset_number' => $asset->asset_number, 'reason' => $reason, 'reversal_journal_number' => $reversal?->journal_number,
            ]);

            return $this->load($asset->refresh());
        });
    }

    // ------------------------------------------------------------------------------------------------ helpers

    /**
     * @return array<string,mixed> the columns to write: the request merged over the existing draft, validated as a whole
     */
    private function prepare(array $data, ?FixedAsset $existing, User $actor): array
    {
        $profile = $this->workflow->profile();
        $scale = (int) $profile->currency_scale;
        $pick = fn (string $key, mixed $default = null) => array_key_exists($key, $data) ? $data[$key] : ($existing?->{$key} ?? $default);

        $category = $this->categories->usable($pick('asset_category_id'));
        $acquisition = $pick('acquisition_date');
        $capitalization = $pick('capitalization_date', $acquisition);
        if ($acquisition === null || $capitalization === null) {
            throw new DomainException('The acquisition date is required.', 'ASSET_DATE_REQUIRED', 422, ['field' => 'acquisition_date']);
        }
        $acquisition = substr((string) $acquisition, 0, 10);
        $capitalization = substr((string) $capitalization, 0, 10);
        if ($capitalization < $acquisition) {
            throw new DomainException('The capitalization date cannot be before the acquisition date.', 'ASSET_DATE_INVALID', 422, ['field' => 'capitalization_date']);
        }

        $cost = Money::parse($pick('acquisition_cost'), $scale, 'acquisition_cost');
        if (! $cost->isPositive()) {
            throw new DomainException('The acquisition cost must be greater than zero.', 'ASSET_COST_INVALID', 422, ['field' => 'acquisition_cost']);
        }
        $method = $pick('method', $category->default_method);
        $strategy = DepreciationMethods::for($method);
        $life = $strategy->needsLife() ? $pick('useful_life_months', $category->default_useful_life_months) : null;
        if ($strategy->needsLife() && ($life === null || (int) $life < 1 || (int) $life > 1200)) {
            throw new DomainException('The useful life must be between 1 and 1200 months.', 'ASSET_LIFE_INVALID', 422, ['field' => 'useful_life_months']);
        }
        $residual = array_key_exists('residual_value', $data) || $existing !== null
            ? Money::parse($pick('residual_value', 0), $scale, 'residual_value')
            : $this->defaultResidual($category, $cost, $scale);
        if ($residual->isGreaterThan($cost)) {
            throw new DomainException('The residual value cannot exceed the acquisition cost.', 'ASSET_RESIDUAL_INVALID', 422, ['field' => 'residual_value']);
        }
        $policy = $pick('start_policy', $category->default_start_policy);
        if (! in_array($policy, ['CAPITALIZATION_MONTH', 'NEXT_MONTH'], true)) {
            throw new DomainException('The start policy is not supported.', 'ASSET_START_POLICY_INVALID', 422, ['field' => 'start_policy']);
        }

        $dims = $this->dimensions->resolve($pick('branch_id'), $pick('business_unit_id'), $pick('cost_center_id'));
        $this->scope->assertWritable($dims['branch_id'], $dims['business_unit_id'], $existing?->created_by ?? $actor->id);

        $mode = $pick('capitalization_mode', 'POST');
        if (! in_array($mode, ['POST', 'REGISTER_ONLY'], true)) {
            throw new DomainException('The capitalization mode is not supported.', 'ASSET_MODE_INVALID', 422, ['field' => 'capitalization_mode']);
        }
        $source = ['source_account_id' => null, 'source_type' => null, 'source_id' => null];
        if ($mode === 'REGISTER_ONLY') {
            $lineId = $data['ap_invoice_line_id'] ?? ($existing?->source_type === 'AP_INVOICE_LINE' ? $existing->source_id : null);
            if ($lineId === null) {
                throw new DomainException('Name the AP invoice line whose cost is already in the ledger.', 'ASSET_SOURCE_REQUIRED', 422, ['field' => 'ap_invoice_line_id']);
            }
            $this->assertApLine((string) $lineId, $cost, $existing?->id, false);
            $source = ['source_account_id' => null, 'source_type' => 'AP_INVOICE_LINE', 'source_id' => (string) $lineId];
        } else {
            $accountId = $pick('source_account_id');
            if ($accountId !== null) {
                $this->accounts->usable($accountId, self::POST_ACCOUNT_TYPES, false, 'source_account_id');
            }
            $source = ['source_account_id' => $accountId, 'source_type' => $pick('source_reference') !== null ? 'EXTERNAL' : null, 'source_id' => null];
        }

        return [
            'asset_category_id' => $category->id, 'name' => $this->requiredName($pick('name')), 'description' => $pick('description'),
            'acquisition_date' => $acquisition, 'capitalization_date' => $capitalization,
            'acquisition_cost' => Money::str($cost), 'residual_value' => Money::str($residual),
            'useful_life_months' => $life === null ? null : (int) $life, 'method' => $method, 'method_params' => $strategy->params($pick('method_params')), 'start_policy' => $policy,
            'capitalization_mode' => $mode, 'source_reference' => $pick('source_reference'),
        ] + $dims + $source;
    }

    private function requiredName(mixed $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            throw new DomainException('The asset needs a name.', 'ASSET_NAME_REQUIRED', 422, ['field' => 'name']);
        }

        return $name;
    }

    private function defaultResidual(AssetCategory $category, BigDecimal $cost, int $scale): BigDecimal
    {
        return match ($category->default_residual_type) {
            'AMOUNT' => BigDecimal::of($category->default_residual_value)->toScale(4, RoundingMode::Unnecessary),
            'PERCENT' => $cost->multipliedBy($category->default_residual_value)->dividedBy(100, $scale, RoundingMode::HalfUp)->toScale(4, RoundingMode::Unnecessary),
            default => BigDecimal::zero()->toScale(4),
        };
    }

    private function accountFor(string $role, ?string $explicit, array $types, ?string $branchId, ?string $unitId, string $field): Account
    {
        $id = $explicit ?? $this->mappings->resolve($role, $branchId, $unitId)['account']->id;

        return $this->accounts->usable($id, $types, false, $field);
    }

    /**
     * A cost that is already in the ledger through a posted AP invoice line. The line must be posted, name its account, and the cost
     * registered from it (other live assets included) may not exceed its amount. With $lock the line is locked first, so two assets
     * claiming the same line serialize.
     */
    private function assertApLine(string $lineId, BigDecimal $cost, ?string $exceptAssetId, bool $lock): ApInvoiceLine
    {
        $line = preg_match('/^[0-9a-f-]{36}$/i', $lineId) ? ApInvoiceLine::query()->when($lock, fn ($q) => $q->lockForUpdate())->find($lineId) : null;
        if (! $line) {
            throw new DomainException('The AP invoice line does not exist.', 'ASSET_SOURCE_NOT_FOUND', 422, ['field' => 'ap_invoice_line_id']);
        }
        $invoice = ApInvoice::query()->find($line->ap_invoice_id);
        if (! $invoice || $invoice->status !== ApInvoice::POSTED) {
            throw new DomainException('The cost can be registered only from a posted AP invoice.', 'ASSET_SOURCE_NOT_POSTED', 409, ['field' => 'ap_invoice_line_id']);
        }
        if ($invoice->exchange_rate_id !== null) {
            // the line amount is in the invoice currency while an asset is kept in the functional one: capitalize with a source account instead
            throw new DomainException('The cost of a foreign-currency invoice line cannot be registered as an asset; capitalize the asset with a source account instead.', 'ASSET_SOURCE_FOREIGN', 422, ['field' => 'ap_invoice_line_id', 'currency' => $invoice->currency]);
        }
        if ($line->account_id === null) {
            throw new DomainException('The invoice line must name an asset account to be registered as an asset.', 'ASSET_SOURCE_ACCOUNT_MISSING', 422, ['field' => 'ap_invoice_line_id']);
        }
        $used = Money::sum(FixedAsset::query()->where('source_type', 'AP_INVOICE_LINE')->where('source_id', $line->id)->where('status', '<>', FixedAsset::INACTIVE)
            ->when($exceptAssetId, fn ($q) => $q->where('id', '<>', $exceptAssetId))->pluck('acquisition_cost'));
        if ($used->plus($cost)->isGreaterThan(BigDecimal::of($line->amount))) {
            throw new DomainException('The assets registered from this invoice line would exceed its amount.', 'ASSET_SOURCE_EXCEEDED', 409, [
                'line_amount' => Money::str($line->amount), 'already_registered' => Money::str($used), 'requested' => Money::str($cost),
            ]);
        }

        return $line;
    }

    private function assertJournal(JournalEntry $journal, BigDecimal $cost, string $assetAccountId): void
    {
        $debit = $journal->lines()->where('account_id', $assetAccountId)->sum('debit');
        if (! BigDecimal::of((string) $journal->total_debit)->isEqualTo($cost) || ! BigDecimal::of((string) $debit)->isGreaterThanOrEqualTo($cost)) {
            throw new DomainException('The capitalization journal does not match the asset cost.', 'ASSET_JOURNAL_MISMATCH', 500, ['journal' => $journal->journal_number]);
        }
    }

    private function fiscalYearOf(string $date): FiscalYear
    {
        return FiscalYear::query()->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->first()
            ?? throw new DomainException("No fiscal year covers {$date}.", 'PERIOD_NOT_FOUND', 422, ['date' => $date]);
    }

    /** @param list<array<string,string|int>> $rows */
    private function insertSchedule(FixedAsset $asset, array $rows): void
    {
        $now = now();
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('asset_depreciation_schedules')->insert(array_map(fn ($r) => $r + [
                'id' => (string) Str::uuid7(), 'tenant_id' => $asset->tenant_id, 'fixed_asset_id' => $asset->id, 'status' => DepreciationScheduleRow::PLANNED,
                'created_at' => $now, 'updated_at' => $now,
            ], $chunk));
        }
    }

    public function netBookValue(FixedAsset $asset): string
    {
        return Money::str(BigDecimal::of($asset->acquisition_cost)->minus($asset->accumulated_depreciation));
    }

    /** @return array{rows:int,planned:int,in_run:int,posted:int,cancelled:int,next_period:?string} */
    private function scheduleSummary(FixedAsset $asset): array
    {
        $by = DepreciationScheduleRow::query()->where('fixed_asset_id', $asset->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $next = DepreciationScheduleRow::query()->where('fixed_asset_id', $asset->id)->where('status', DepreciationScheduleRow::PLANNED)->min('period_start');

        return [
            'rows' => (int) $by->sum(), 'planned' => (int) ($by['PLANNED'] ?? 0), 'in_run' => (int) ($by['IN_RUN'] ?? 0), 'posted' => (int) ($by['POSTED'] ?? 0),
            'cancelled' => (int) ($by['CANCELLED'] ?? 0), 'next_period' => $next ? substr((string) $next, 0, 10) : null,
        ];
    }

    private function summary(FixedAsset $asset): array
    {
        return $asset->only(['asset_number', 'name', 'status', 'asset_category_id', 'acquisition_date', 'capitalization_date', 'acquisition_cost', 'residual_value', 'useful_life_months', 'method',
            'start_policy', 'capitalization_mode', 'branch_id', 'business_unit_id', 'cost_center_id', 'source_type', 'source_id', 'source_account_id']);
    }
}
