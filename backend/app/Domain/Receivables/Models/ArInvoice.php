<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Accounting\Concerns\HasDocumentHistory;
use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Payables\Models\PaymentTerm;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A receivable: a customer invoice. Only the header text and dates are client-writable; status, number, actors, totals and the
 * journal link are set by the services.
 */
class ArInvoice extends Model
{
    use BelongsToTenant, HasDocumentHistory, HasUuids;

    public const DOCUMENT_TYPE = 'ar_invoice';

    public const AUDIT_PREFIX = 'receivables.ar_invoice';

    protected $fillable = ['customer_reference', 'document_date', 'posting_date', 'due_date', 'description', 'reference'];

    protected function casts(): array
    {
        return [
            'document_date' => 'date:Y-m-d', 'posting_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'reversal_posting_date' => 'date:Y-m-d',
            'due_date_overridden' => 'boolean', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->select(['id', 'code', 'name', 'status']);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class)->select(['id', 'code', 'name']);
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

    public function lines(): HasMany
    {
        return $this->hasMany(ArInvoiceLine::class)->orderBy('line_number');
    }

    /** Effective receipt allocations (the receipts that currently settle this invoice). */
    public function allocations(): HasMany
    {
        return $this->hasMany(ArReceiptAllocation::class)->where('is_effective', true)->orderBy('effective_at');
    }

    /** Posted credit notes that currently reduce this invoice. */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(ArCreditNote::class)->where('status', self::POSTED)->orderBy('posting_date');
    }
}
