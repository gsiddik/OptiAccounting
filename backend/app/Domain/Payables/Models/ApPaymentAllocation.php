<?php

namespace App\Domain\Payables\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The part of a vendor payment applied to one vendor invoice. Created and made effective only by VendorPaymentService; nothing is mass-assignable. */
class ApPaymentAllocation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_effective' => 'boolean', 'effective_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(VendorPayment::class, 'vendor_payment_id')->select(['id', 'document_number', 'payment_date', 'posting_date', 'status']);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ApInvoice::class, 'ap_invoice_id')->select(['id', 'document_number', 'vendor_invoice_number', 'due_date', 'total_amount', 'status']);
    }
}
