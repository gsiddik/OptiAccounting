<?php

namespace App\Domain\Budget\Models;

use App\Domain\Accounting\Concerns\HasDocumentHistory;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of a budget (Original, Revision 1, ... are labels, never code). DRAFT > SUBMITTED > APPROVED > ACTIVE > SUPERSEDED, with
 * REJECTED (back to DRAFT) and CANCELLED. Only a draft has editable lines; an approved version is history that revisions never rewrite.
 */
class BudgetVersion extends Model
{
    use BelongsToTenant, HasDocumentHistory, HasUuids;

    public const DOCUMENT_TYPE = 'budget_version';

    public const AUDIT_PREFIX = 'budget.version';

    public const ACTIVE = 'ACTIVE';

    public const SUPERSEDED = 'SUPERSEDED';

    protected $fillable = ['label', 'description'];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d', 'effective_until' => 'date:Y-m-d',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'rejected_at' => 'datetime', 'cancelled_at' => 'datetime',
            'activated_at' => 'datetime', 'superseded_at' => 'datetime',
        ];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->orderBy('accounting_period_id');
    }
}
