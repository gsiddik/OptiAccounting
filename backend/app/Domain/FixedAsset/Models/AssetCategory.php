<?php

namespace App\Domain\FixedAsset\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned asset category: the accounting defaults a new asset starts from (useful life, method, residual policy) and the optional
 * accounts it books to. A category with no account falls back to the role mapping (FIXED_ASSET, ACCUMULATED_DEPRECIATION, ...).
 * Changing a category never touches an asset that is already capitalized: the asset froze its own accounts and terms.
 */
class AssetCategory extends Model
{
    use BelongsToTenant, HasUuids;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    protected $fillable = [
        'code', 'name', 'description', 'default_method', 'default_useful_life_months', 'default_residual_type', 'default_residual_value', 'default_start_policy',
    ];

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
}
