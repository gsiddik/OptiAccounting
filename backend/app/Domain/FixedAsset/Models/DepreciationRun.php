<?php

namespace App\Domain\FixedAsset\Models;

use App\Domain\Accounting\Concerns\HasDocumentHistory;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The depreciation of one accounting period as a source document: DRAFT (calculated, under review) > POSTED > REVERSED, or CANCELLED. Written by DepreciationRunService only. */
class DepreciationRun extends Model
{
    use BelongsToTenant, HasDocumentHistory, HasUuids;

    public const DOCUMENT_TYPE = 'depreciation_run';

    public const AUDIT_PREFIX = 'fixed_asset.depreciation_run';

    protected $table = 'asset_depreciation_runs';

    protected $fillable = ['description', 'reference'];

    protected function casts(): array
    {
        return ['posting_date' => 'date:Y-m-d', 'reversal_posting_date' => 'date:Y-m-d', 'cancelled_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id')->select(['id', 'code', 'start_date', 'end_date', 'status']);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DepreciationRunLine::class, 'run_id')->orderBy('line_number');
    }
}
