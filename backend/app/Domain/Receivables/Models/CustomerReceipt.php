<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Accounting\Concerns\HasDocumentHistory;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\CostCenter;
use App\Domain\CashBank\Models\CashBankAccount;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money received from a customer, settling posted receivables through its allocations. Amount, status, number, actors and the journal link are set by CustomerReceiptService only. */
class CustomerReceipt extends Model
{
    use BelongsToTenant, HasDocumentHistory, HasUuids;

    public const DOCUMENT_TYPE = 'customer_receipt';

    public const AUDIT_PREFIX = 'receivables.customer_receipt';

    public const METHODS = ['TRANSFER', 'CASH', 'CHEQUE', 'GIRO', 'OTHER'];

    protected $fillable = ['receipt_date', 'posting_date', 'receipt_method', 'reference', 'description'];

    protected function casts(): array
    {
        return [
            'receipt_date' => 'date:Y-m-d', 'posting_date' => 'date:Y-m-d', 'reversal_posting_date' => 'date:Y-m-d',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime',
            'posted_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->select(['id', 'code', 'name', 'status']);
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class)->select(['id', 'code', 'name', 'kind', 'bank_name', 'account_number_masked', 'status']);
    }

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'gl_account_id')->select(['id', 'code', 'name']);
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

    public function allocations(): HasMany
    {
        return $this->hasMany(ArReceiptAllocation::class)->orderBy('created_at')->orderBy('id');
    }
}
