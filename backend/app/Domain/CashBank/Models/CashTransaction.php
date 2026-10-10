<?php

namespace App\Domain\CashBank\Models;

use App\Domain\Accounting\Concerns\HasDocumentHistory;
use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A controlled cash/bank payment or receipt outside AP and AR. Only descriptive text and dates are mass-assignable; the kind, accounts,
 * amount, status, number, actors and the journal link are set by CashTransactionService.
 */
class CashTransaction extends Model
{
    use BelongsToTenant, HasDocumentHistory, HasUuids;

    public const DOCUMENT_TYPE = 'cash_transaction';

    public const AUDIT_PREFIX = 'cash_bank.transaction';

    public const PAYMENT = 'PAYMENT';

    public const RECEIPT = 'RECEIPT';

    protected $fillable = ['transaction_date', 'posting_date', 'purpose', 'description', 'reference', 'counterparty_name'];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date:Y-m-d', 'posting_date' => 'date:Y-m-d', 'reversal_posting_date' => 'date:Y-m-d',
            'cancelled_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(CashBankAccount::class)->select(['id', 'code', 'name', 'kind', 'bank_name', 'account_number_masked', 'status']);
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_id')->select(['id', 'code', 'name', 'account_type']);
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
}
