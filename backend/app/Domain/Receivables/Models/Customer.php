<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Payables\Models\PaymentTerm;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned customer master. Identity fields are mass-assignable; the financial profile (payment term, receivable account
 * override, revenue hint, credit limit metadata) and the status are set only by CustomerService, which validates and audits each of them.
 */
class Customer extends Model
{
    use BelongsToTenant, HasUuids;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    protected $fillable = [
        'code', 'name', 'legal_name', 'contact_name', 'email', 'phone', 'address', 'tax_id', 'tax_registered',
        'default_currency', 'external_source', 'external_id', 'notes',
    ];

    protected function casts(): array
    {
        return ['tax_registered' => 'boolean'];
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'receivable_account_id')->select(['id', 'code', 'name']);
    }

    public function defaultRevenueAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_revenue_account_id')->select(['id', 'code', 'name']);
    }
}
