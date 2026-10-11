<?php

namespace App\Domain\Reconciliation\Models;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evidence of one explicit reconciliation run: what was compared, as of when, who asked and what came out. Append-only (the database refuses
 * UPDATE and DELETE) and never read back as truth: every report, exception and close check recomputes from the posted ledger.
 */
class ReconciliationRun extends Model
{
    use BelongsToTenant, HasUuids;

    public const MANUAL = 'MANUAL';

    public const CLOSE_CHECK = 'CLOSE_CHECK';

    public const PURPOSES = [self::MANUAL, self::CLOSE_CHECK];

    public const UPDATED_AT = null; // append-only: the table has no updated_at

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['as_of_date' => 'date:Y-m-d', 'scope_complete' => 'boolean', 'components' => 'array', 'run_at' => 'datetime'];
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id')->select(['id', 'code', 'start_date', 'end_date', 'status']);
    }
}
