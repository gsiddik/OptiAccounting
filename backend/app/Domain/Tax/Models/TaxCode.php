<?php

namespace App\Domain\Tax\Models;

use App\Domain\Accounting\Models\Account;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned tax code: a generic classification (input, output, withholding, other), how it is calculated (exclusive or inclusive of
 * the amount entered), its treatment (standard, zero rated, exempt), whether an input tax is recoverable and the account role or account
 * it posts to. The rate lives in effective-dated TaxRate rows; nothing here is a statutory rule. Written by TaxCodeService only.
 */
class TaxCode extends Model
{
    use BelongsToTenant, HasUuids;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    public const INPUT_TAX = 'INPUT_TAX';

    public const OUTPUT_TAX = 'OUTPUT_TAX';

    public const WITHHOLDING = 'WITHHOLDING';

    public const OTHER = 'OTHER';

    public const TYPES = [self::INPUT_TAX, self::OUTPUT_TAX, self::WITHHOLDING, self::OTHER];

    public const EXCLUSIVE = 'EXCLUSIVE';

    public const INCLUSIVE = 'INCLUSIVE';

    public const STANDARD = 'STANDARD';

    public const ZERO_RATED = 'ZERO_RATED';

    public const EXEMPT = 'EXEMPT';

    protected $fillable = ['code', 'name', 'description', 'metadata'];

    protected function casts(): array
    {
        return ['is_recoverable' => 'boolean', 'metadata' => 'array'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->select(['id', 'code', 'name']);
    }

    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class)->orderByDesc('effective_from');
    }
}
