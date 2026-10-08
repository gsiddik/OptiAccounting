<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountMapping extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['account_role', 'account_id', 'branch_id', 'business_unit_id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
