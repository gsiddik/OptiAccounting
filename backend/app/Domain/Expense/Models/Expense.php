<?php

namespace App\Domain\Expense\Models;

use App\Domain\Accounting\Concerns\HasDocumentHistory;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\CostCenter;
use App\Domain\CashBank\Models\CashBankAccount;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Payables\Models\ApInvoice;
use App\Domain\Payables\Models\PaymentTerm;
use App\Domain\Payables\Models\Vendor;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A classified cost with an explicit financial path (PAYABLE or DIRECT_PAID). Only descriptive text and dates are mass-assignable;
 * amounts, status, number, actors and the journal link are set by ExpenseService.
 */
class Expense extends Model
{
    use BelongsToTenant, HasDocumentHistory, HasUuids;

    public const DOCUMENT_TYPE = 'expense';

    public const AUDIT_PREFIX = 'expense.expense';

    public const PAYABLE = 'PAYABLE';

    public const DIRECT_PAID = 'DIRECT_PAID';

    protected $fillable = ['expense_date', 'posting_date', 'due_date', 'description', 'reference', 'payee_name', 'payment_method', 'supporting_document'];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date:Y-m-d', 'posting_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'reversal_posting_date' => 'date:Y-m-d',
            'due_date_overridden' => 'boolean', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->select(['id', 'code', 'name', 'status']);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id')->select(['id', 'code', 'name', 'status']);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->select(['id', 'code', 'name']);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class)->select(['id', 'code', 'name']);
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

    /** The payable this expense created when it was posted (PAYABLE path): one row in the ordinary AP subledger. */
    public function payable(): HasOne
    {
        return $this->hasOne(ApInvoice::class, 'source_id')->where('source_type', self::DOCUMENT_TYPE);
    }
}
