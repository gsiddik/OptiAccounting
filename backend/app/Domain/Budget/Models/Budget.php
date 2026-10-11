<?php

namespace App\Domain\Budget\Models;

use App\Domain\Accounting\Concerns\HasDocumentHistory;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned budget: the planning container of one fiscal year. It owns the lifecycle of the budget itself (DRAFT > ACTIVE > CLOSED,
 * or CANCELLED); the figures live in its versions. Status and actor columns are written by BudgetService only.
 */
class Budget extends Model
{
    use BelongsToTenant, HasDocumentHistory, HasUuids;

    public const DOCUMENT_TYPE = 'budget';

    public const AUDIT_PREFIX = 'budget';

    public const ACTIVE = 'ACTIVE';

    public const CLOSED = 'CLOSED';

    protected $fillable = ['code', 'name', 'description'];

    protected function casts(): array
    {
        return ['activated_at' => 'datetime', 'closed_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function fiscalYear(): BelongsTo
    {
        return $this->belongsTo(FiscalYear::class)->select(['id', 'code', 'name', 'start_date', 'end_date']);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id')->select(['id', 'name']);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(BudgetVersion::class)->orderBy('version_number');
    }
}
