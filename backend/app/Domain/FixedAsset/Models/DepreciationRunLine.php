<?php

namespace App\Domain\FixedAsset\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepreciationRunLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asset_depreciation_run_lines';

    protected $guarded = ['*'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id')->select(['id', 'asset_number', 'name', 'status']);
    }
}
