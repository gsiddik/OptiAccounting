<?php

namespace App\Domain\Payables\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant-owned vendor master. Identity fields are mass-assignable; the financial profile (payment term, payable account
 * override, expense hint) and the status are set only by VendorService, which validates and audits each of them.
 */
class Vendor extends Model
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

    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id')->select(['id', 'code', 'name']);
    }

    public function defaultExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_expense_account_id')->select(['id', 'code', 'name']);
    }
}
