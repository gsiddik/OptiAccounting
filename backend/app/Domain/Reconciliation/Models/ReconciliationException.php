<?php

namespace App\Domain\Reconciliation\Models;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The open work item of a reconciliation that does not match, one live row per (type, as_of). Its numbers and `calculated_status` are written
 * only from a recomputation (ReconciliationRunService); `status` (OPEN > EXPLAINED > RESOLVED) is the human side and can reach RESOLVED only
 * after a recomputation shows MATCHED. Nothing here is a ledger figure.
 */
class ReconciliationException extends Model
{
    use BelongsToTenant, HasUuids;

    public const OPEN = 'OPEN';

    public const EXPLAINED = 'EXPLAINED';

    public const RESOLVED = 'RESOLVED';

    public const STATUSES = [self::OPEN, self::EXPLAINED, self::RESOLVED];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['as_of_date' => 'date:Y-m-d', 'explained_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id')->select(['id', 'code', 'start_date', 'end_date', 'status']);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->select(['id', 'name']);
    }
}
