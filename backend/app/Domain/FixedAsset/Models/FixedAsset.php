<?php

namespace App\Domain\FixedAsset\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Accounting\Models\DocumentTransition;
use App\Domain\Identity\Models\User;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One asset of the register. The register is subledger metadata; the general ledger stays the financial truth. Status, number, the frozen
 * accounts, the schedule snapshot and every journal link are written by FixedAssetService only (the database refuses any change of the
 * financial terms once the asset is capitalized).
 */
class FixedAsset extends Model
{
    use BelongsToTenant, HasUuids;

    public const DOCUMENT_TYPE = 'fixed_asset';

    public const AUDIT_PREFIX = 'fixed_asset.asset';

    public const DRAFT = 'DRAFT';

    public const ACTIVE = 'ACTIVE';

    public const FULLY_DEPRECIATED = 'FULLY_DEPRECIATED';

    public const DISPOSED = 'DISPOSED';

    public const INACTIVE = 'INACTIVE';

    /** The statuses of a capitalized asset that is still on the books. */
    public const ON_BOOKS = [self::ACTIVE, self::FULLY_DEPRECIATED];

    protected $fillable = ['name', 'description', 'acquisition_date', 'capitalization_date', 'acquisition_cost', 'residual_value', 'useful_life_months', 'method', 'start_policy', 'source_reference'];

    protected function casts(): array
    {
        return [
            'acquisition_date' => 'date:Y-m-d', 'capitalization_date' => 'date:Y-m-d', 'disposed_on' => 'date:Y-m-d',
            'method_params' => 'array', 'schedule_snapshot' => 'array',
            'capitalized_at' => 'datetime', 'capitalization_reversed_at' => 'datetime',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id')->select(['id', 'code', 'name', 'status']);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->select(['id', 'name']);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->select(['id', 'code', 'name']);
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class)->select(['id', 'code', 'name']);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->select(['id', 'code', 'name']);
    }

    public function sourceAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'source_account_id')->select(['id', 'code', 'name']);
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id')->select(['id', 'code', 'name']);
    }

    public function accumulatedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_account_id')->select(['id', 'code', 'name']);
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id')->select(['id', 'code', 'name']);
    }

    public function gainLossAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gain_loss_account_id')->select(['id', 'code', 'name']);
    }

    public function schedule(): HasMany
    {
        return $this->hasMany(DepreciationScheduleRow::class)->orderBy('sequence_no');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(DocumentTransition::class, 'document_id')->where('document_type', self::DOCUMENT_TYPE)->orderBy('occurred_at');
    }
}
