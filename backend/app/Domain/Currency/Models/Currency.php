<?php

namespace App\Domain\Currency\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A foreign currency a tenant may transact in, identified by its ISO 4217 code (never by symbol). The functional currency of the accounting
 * profile needs no row: it is always usable. `decimal_places` is the precision documents in this currency are entered with. Written by
 * CurrencyService only.
 */
class Currency extends Model
{
    use BelongsToTenant, HasUuids;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    protected $fillable = ['name', 'symbol'];

    protected function casts(): array
    {
        return ['decimal_places' => 'integer'];
    }
}
