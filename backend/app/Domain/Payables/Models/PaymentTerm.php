<?php

namespace App\Domain\Payables\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Tenant-configurable payment term. The due date it yields is computed once and stored on the document, so editing a term never moves a due date. */
class PaymentTerm extends Model
{
    use BelongsToTenant, HasUuids;

    public const NET_DAYS = 'NET_DAYS';

    public const END_OF_MONTH = 'END_OF_MONTH';

    public const CUSTOM = 'CUSTOM';

    public const TYPES = [self::NET_DAYS, self::END_OF_MONTH, self::CUSTOM];

    protected $fillable = ['code', 'name', 'term_type', 'due_days', 'allows_due_date_override', 'description'];

    protected function casts(): array
    {
        return ['allows_due_date_override' => 'boolean', 'due_days' => 'integer'];
    }
}
