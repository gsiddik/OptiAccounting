<?php

namespace App\Domain\Closing\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One row of the append-only closing history (the database refuses UPDATE and DELETE). Written by ClosingHistory only. */
class ClosingEvent extends Model
{
    use BelongsToTenant, HasUuids;

    public const PRE_CLOSE = 'PRE_CLOSE';

    public const SOFT_CLOSE = 'SOFT_CLOSE';

    public const HARD_CLOSE = 'HARD_CLOSE';

    public const REOPEN = 'REOPEN';

    public const YEAR_END_RUN = 'YEAR_END_RUN';

    public const YEAR_END_REVERSE = 'YEAR_END_REVERSE';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['txid'];

    protected function casts(): array
    {
        return ['readiness_checks' => 'array', 'created_at' => 'datetime'];
    }
}
