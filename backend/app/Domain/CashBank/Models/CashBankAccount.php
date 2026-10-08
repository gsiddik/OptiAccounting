<?php

namespace App\Domain\CashBank\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A cash box or bank account mapped to one GL account. Descriptive fields are mass-assignable; the kind, the GL mapping, the currency,
 * the status and the masked number are set by CashBankAccountService, which validates and audits them.
 */
class CashBankAccount extends Model
{
    use BelongsToTenant, HasUuids;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    public const CASH = 'CASH';

    public const BANK = 'BANK';

    protected $fillable = ['code', 'name', 'bank_name', 'account_holder', 'notes'];

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id')->select(['id', 'code', 'name', 'account_type', 'status']);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->select(['id', 'code', 'name']);
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class)->select(['id', 'code', 'name']);
    }
}
