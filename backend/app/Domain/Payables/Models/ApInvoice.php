<?php

namespace App\Domain\Payables\Models;

use App\Domain\Accounting\Concerns\HasDocumentHistory;
use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A payable: a vendor invoice, or the payable an expense created (origin EXPENSE). Only the header text and dates are
 * client-writable; status, number, actors, totals and the journal link are set by the services.
 */
class ApInvoice extends Model
{
    use BelongsToTenant, HasDocumentHistory, HasUuids;

    public const DOCUMENT_TYPE = 'ap_invoice';

    public const AUDIT_PREFIX = 'payables.ap_invoice';

    public const ORIGIN_INVOICE = 'INVOICE';

    public const ORIGIN_EXPENSE = 'EXPENSE';

    protected $fillable = ['vendor_invoice_number', 'document_date', 'posting_date', 'due_date', 'description', 'reference'];

    protected function casts(): array
    {
        return [
            'document_date' => 'date:Y-m-d', 'posting_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d', 'reversal_posting_date' => 'date:Y-m-d',
            'due_date_overridden' => 'boolean', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime',
            'cancelled_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    protected $hidden = ['vendor_invoice_key'];

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->select(['id', 'code', 'name', 'status']);
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
        return $this->hasMany(ApInvoiceLine::class)->orderBy('line_number');
    }
}
