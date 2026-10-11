<?php

namespace App\Domain\Reporting\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned layout of one financial statement (balance sheet, profit and loss or cash flow) for a tenant: lines, their calculation rules and the
 * mapping of chart-of-accounts accounts to lines. Configuration only; it never holds an amount. DRAFT (editable) > ACTIVE (published, immutable,
 * effective from a date) > ARCHIVED (superseded, still serves the dates it was effective). The database enforces the lifecycle and that exactly one
 * version applies on any date, so the type, code and version never change and a published definition is final.
 */
class ReportDefinition extends Model
{
    use BelongsToTenant, HasUuids;

    public const DRAFT = 'DRAFT';

    public const ACTIVE = 'ACTIVE';

    public const ARCHIVED = 'ARCHIVED';

    public const BALANCE_SHEET = 'BALANCE_SHEET';

    public const PROFIT_LOSS = 'PROFIT_LOSS';

    public const CASH_FLOW = 'CASH_FLOW';

    public const TYPES = [self::BALANCE_SHEET, self::PROFIT_LOSS, self::CASH_FLOW];

    protected $fillable = ['code', 'statement_type', 'name', 'description'];

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'published_at' => 'datetime', 'archived_at' => 'datetime', 'version' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReportLine::class)->orderBy('display_order')->orderBy('code');
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ReportLineMapping::class);
    }

    public function basedOn(): BelongsTo
    {
        return $this->belongsTo(self::class, 'based_on_id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }
}
