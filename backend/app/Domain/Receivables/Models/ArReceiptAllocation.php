<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The part of a customer receipt applied to one customer invoice. Created and made effective only by CustomerReceiptService; nothing is mass-assignable. */
class ArReceiptAllocation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_effective' => 'boolean', 'effective_at' => 'datetime', 'released_at' => 'datetime'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(CustomerReceipt::class, 'customer_receipt_id')->select(['id', 'document_number', 'receipt_date', 'posting_date', 'status']);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class, 'ar_invoice_id')->select(['id', 'document_number', 'customer_reference', 'due_date', 'total_amount', 'status']);
    }
}
