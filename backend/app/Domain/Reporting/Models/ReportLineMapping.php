<?php

namespace App\Domain\Reporting\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Puts one chart-of-accounts account (or, with `include_descendants`, a header account and every posting account below it) on a report line. */
class ReportLineMapping extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['account_id', 'include_descendants'];

    protected function casts(): array
    {
        return ['include_descendants' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(ReportLine::class, 'report_line_id');
    }
}
