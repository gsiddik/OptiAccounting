<?php

namespace App\Domain\Budget\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A planned amount for one account (or account group) in one period, optionally split by branch, business unit and cost center. */
class BudgetLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['description'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->select(['id', 'code', 'name', 'account_type', 'normal_balance', 'is_postable']);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id')->select(['id', 'code', 'name', 'number', 'start_date', 'end_date']);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->select(['id', 'code', 'name']);
    }

    public function businessUnit(): BelongsTo
    {
        return $this->belongsTo(BusinessUnit::class)->select(['id', 'code', 'name']);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->select(['id', 'code', 'name']);
    }
}
