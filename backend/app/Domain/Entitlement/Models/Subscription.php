<?php

namespace App\Domain\Entitlement\Models;

use App\Domain\ProductCatalog\Models\Bundle;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model
{
    use BelongsToTenant, HasUuids;

    public const PENDING = 'PENDING';

    public const ACTIVE = 'ACTIVE';

    public const PAST_DUE = 'PAST_DUE';

    public const SUSPENDED = 'SUSPENDED';

    public const EXPIRED = 'EXPIRED';

    public const CANCELLED = 'CANCELLED';

    public const LIVE = [self::PENDING, self::ACTIVE, self::PAST_DUE, self::SUSPENDED];

    public const TRANSITIONS = [
        self::PENDING => [self::ACTIVE, self::CANCELLED],
        self::ACTIVE => [self::PAST_DUE, self::SUSPENDED, self::EXPIRED, self::CANCELLED],
        self::PAST_DUE => [self::ACTIVE, self::SUSPENDED, self::EXPIRED, self::CANCELLED],
        self::SUSPENDED => [self::ACTIVE, self::EXPIRED, self::CANCELLED],
        self::EXPIRED => [],
        self::CANCELLED => [],
    ];

    protected $fillable = ['starts_on', 'ends_on', 'notes', 'external_reference'];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }
}
