<?php

namespace App\Domain\Reporting\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One line of a report definition tree. HEADER only labels; ACCOUNT / ACCOUNT_GROUP take their amount from the accounts mapped to them
 * (`balance_side` DEBIT = debit minus credit, CREDIT = credit minus debit); SUBTOTAL sums the amounts below it; CALCULATED_TOTAL combines other
 * lines by code with a plus or minus sign (a declarative operand list, never a formula); SYSTEM is one of a closed list of built-in figures.
 */
class ReportLine extends Model
{
    use BelongsToTenant, HasUuids;

    public const HEADER = 'HEADER';

    public const ACCOUNT = 'ACCOUNT';

    public const ACCOUNT_GROUP = 'ACCOUNT_GROUP';

    public const SUBTOTAL = 'SUBTOTAL';

    public const CALCULATED_TOTAL = 'CALCULATED_TOTAL';

    public const SYSTEM = 'SYSTEM';

    public const TYPES = [self::HEADER, self::ACCOUNT, self::ACCOUNT_GROUP, self::SUBTOTAL, self::CALCULATED_TOTAL, self::SYSTEM];

    /** Lines whose amount comes from mapped accounts. */
    public const MAPPED_TYPES = [self::ACCOUNT, self::ACCOUNT_GROUP];

    public const CATEGORIES = ['OPERATING', 'INVESTING', 'FINANCING', 'NON_CASH', 'UNCLASSIFIED'];

    /** The closed list of built-in figures and the statement types that may use each. */
    public const SYSTEM_KEYS = [
        'CURRENT_RESULT' => [ReportDefinition::BALANCE_SHEET],
        'NET_RESULT' => [ReportDefinition::PROFIT_LOSS, ReportDefinition::CASH_FLOW],
        'CASH_OPENING' => [ReportDefinition::CASH_FLOW],
        'CASH_CLOSING' => [ReportDefinition::CASH_FLOW],
        'NET_CASH_CHANGE' => [ReportDefinition::CASH_FLOW],
        'UNCLASSIFIED_CASH_FLOW' => [ReportDefinition::CASH_FLOW],
    ];

    protected $fillable = ['code', 'label', 'line_type', 'display_order', 'balance_side', 'cash_flow_category', 'system_key', 'operands', 'show_zero', 'emphasis', 'notes'];

    protected function casts(): array
    {
        return ['operands' => 'array', 'show_zero' => 'boolean', 'emphasis' => 'boolean', 'display_order' => 'integer'];
    }

    public function mappings(): HasMany
    {
        return $this->hasMany(ReportLineMapping::class);
    }

    /** Does the line carry an amount of its own (every type but HEADER)? */
    public function hasAmount(): bool
    {
        return $this->line_type !== self::HEADER;
    }
}
