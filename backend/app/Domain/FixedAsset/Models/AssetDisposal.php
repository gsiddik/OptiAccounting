<?php

namespace App\Domain\FixedAsset\Models;

use App\Domain\Accounting\Concerns\HasDocumentHistory;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Disposal (sale, scrap or write-off) of one asset as a source document with approval. The snapshot, number, status and journal link are set by AssetDisposalService only. */
class AssetDisposal extends Model
{
    use BelongsToTenant, HasDocumentHistory, HasUuids;

    public const DOCUMENT_TYPE = 'asset_disposal';

    public const AUDIT_PREFIX = 'fixed_asset.disposal';

    protected $fillable = ['disposal_type', 'document_date', 'disposal_date', 'posting_date', 'proceeds_amount', 'reason', 'reference'];

    protected function casts(): array
    {
        return [
            'document_date' => 'date:Y-m-d', 'disposal_date' => 'date:Y-m-d', 'posting_date' => 'date:Y-m-d', 'reversal_posting_date' => 'date:Y-m-d',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id')->select(['id', 'asset_number', 'name', 'status', 'acquisition_cost', 'accumulated_depreciation']);
    }

    public function proceedsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'proceeds_account_id')->select(['id', 'code', 'name']);
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
}
