<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountingPeriod extends Model
{
    use BelongsToTenant, HasUuids;

    public const FUTURE = 'FUTURE';

    public const OPEN = 'OPEN';

    public const SOFT_CLOSED = 'SOFT_CLOSED';

    public const CLOSED = 'CLOSED';

    /** Status moves allowed in OA1. CLOSED is final until OA5 adds the controlled reopen. */
    public const TRANSITIONS = [
        self::FUTURE => [self::OPEN],
        self::OPEN => [self::SOFT_CLOSED, self::CLOSED],
        self::SOFT_CLOSED => [self::OPEN, self::CLOSED],
        self::CLOSED => [],
    ];

    protected $fillable = [];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d', 'closed_at' => 'datetime'];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class);
    }
}
