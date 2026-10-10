<?php

namespace App\Domain\Currency\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * One rate of a foreign currency into the functional currency on an effective date: 1 unit of `from_currency` = `rate` units of `to_currency`.
 * A rate is a fact entered once: its currency, value, date and type never change (a correction deactivates it and enters another), so a
 * document that cites it keeps pointing at what it used. Written by ExchangeRateService only.
 */
class ExchangeRate extends Model
{
    use BelongsToTenant, HasUuids;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    public const SPOT = 'SPOT';

    public const DAILY = 'DAILY';

    public const MONTH_END = 'MONTH_END';

    public const MANUAL = 'MANUAL';

    /** In the order a rate of the same date wins when no type is asked for. */
    public const TYPES = [self::MANUAL, self::SPOT, self::DAILY, self::MONTH_END];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['effective_date' => 'date:Y-m-d', 'metadata' => 'array'];
    }
}
