<?php

namespace App\Domain\Closing\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One year-end closing run of a fiscal year. The closing journal is the financial fact; this row only records which journal closed which year (one POSTED run per year). */
class YearEndClosing extends Model
{
    use BelongsToTenant, HasUuids;

    public const POSTED = 'POSTED';

    public const REVERSED = 'REVERSED';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['closing_date' => 'date:Y-m-d', 'run_at' => 'datetime', 'reversed_at' => 'datetime', 'run_no' => 'integer', 'closed_account_count' => 'integer'];
    }
}
