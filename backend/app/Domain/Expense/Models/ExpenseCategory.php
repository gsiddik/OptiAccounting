<?php

namespace App\Domain\Expense\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tenant-configurable expense classification. It points at a semantic account role (tenant mapping) or a default account; it carries no debit/credit logic. */
class ExpenseCategory extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['code', 'name', 'description'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->select(['id', 'code', 'name']);
    }
}
