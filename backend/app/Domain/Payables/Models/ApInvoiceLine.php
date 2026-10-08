<?php

namespace App\Domain\Payables\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Expense\Models\ExpenseCategory;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApInvoiceLine extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['description', 'quantity', 'unit_price', 'amount', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class)->select(['id', 'code', 'name']);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->select(['id', 'code', 'name']);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class)->select(['id', 'code', 'name']);
    }
}
