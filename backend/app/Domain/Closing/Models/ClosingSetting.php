<?php

namespace App\Domain\Closing\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** The tenant's closing settings (one row per tenant, created on first change). Without a row the code defaults apply (ClosingPolicyService::settings). */
class ClosingSetting extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['reconciliation_tolerance', 'reopen_max_periods_back', 'reopen_requires_later_open', 'separate_closer_and_reopener', 'require_soft_close_first'];

    protected function casts(): array
    {
        return [
            'reopen_max_periods_back' => 'integer', 'reopen_requires_later_open' => 'boolean',
            'separate_closer_and_reopener' => 'boolean', 'require_soft_close_first' => 'boolean',
        ];
    }
}
