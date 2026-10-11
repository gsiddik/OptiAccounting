<?php

namespace App\Domain\FixedAsset\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One month of an asset's depreciation schedule. Amount and period never change; only the claim columns move (see the database guard). */
class DepreciationScheduleRow extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'asset_depreciation_schedules';

    public const PLANNED = 'PLANNED';

    public const IN_RUN = 'IN_RUN';

    public const POSTED = 'POSTED';

    public const CANCELLED = 'CANCELLED';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d'];
    }
}
