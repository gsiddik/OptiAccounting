<?php

namespace App\Domain\Receivables\Models;

use App\Domain\Accounting\Concerns\HasDocumentHistory;
use App\Domain\Accounting\Models\CostCenter;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\BusinessUnit;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A credit note: reduces the receivable of one posted customer invoice without touching the invoice. Totals, status, number and the journal link are set by ArCreditNoteService only. */
class ArCreditNote extends Model
{
    use BelongsToTenant, HasDocumentHistory, HasUuids;

    public const DOCUMENT_TYPE = 'ar_credit_note';

    public const AUDIT_PREFIX = 'receivables.ar_credit_note';

    protected $fillable = ['document_date', 'posting_date', 'reason', 'reference'];

    protected function casts(): array
    {
        return [
            'document_date' => 'date:Y-m-d', 'posting_date' => 'date:Y-m-d', 'reversal_posting_date' => 'date:Y-m-d',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime',
            'posted_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->select(['id', 'code', 'name', 'status']);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(ArInvoice::class, 'ar_invoice_id')->select(['id', 'document_number', 'customer_reference', 'due_date', 'total_amount', 'status', 'posting_date']);
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
        return $this->hasMany(ArCreditNoteLine::class)->orderBy('line_number');
    }
}
