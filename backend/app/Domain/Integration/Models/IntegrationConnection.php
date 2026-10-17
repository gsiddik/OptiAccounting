<?php

namespace App\Domain\Integration\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One link between this tenant and one source tenant of one source system (e.g. an OptiFleet company). The lifecycle lives in
 * ConnectionService; the connection is independent of the accounting subscription. The credential is stored encrypted (Eloquent `encrypted`
 * cast) and is hidden from every serialization: the API only ever says whether one is set.
 */
class IntegrationConnection extends Model
{
    use BelongsToTenant, HasUuids;

    public const DRAFT = 'DRAFT';

    public const CONFIGURING = 'CONFIGURING';

    public const READY = 'READY';

    public const ACTIVE = 'ACTIVE';

    public const PAUSED = 'PAUSED';

    public const ERROR = 'ERROR';

    public const DISCONNECTED = 'DISCONNECTED';

    public const STATUSES = [self::DRAFT, self::CONFIGURING, self::READY, self::ACTIVE, self::PAUSED, self::ERROR, self::DISCONNECTED];

    public const OPENING_BALANCE_ONLY = 'OPENING_BALANCE_ONLY';

    public const START_FROM_CUTOVER_DATE = 'START_FROM_CUTOVER_DATE';

    public const HISTORICAL_BACKFILL = 'HISTORICAL_BACKFILL';

    public const CUTOVER_POLICIES = [self::OPENING_BALANCE_ONLY, self::START_FROM_CUTOVER_DATE, self::HISTORICAL_BACKFILL];

    public const GAP_POLICIES = ['REQUIRES_REVIEW', 'IGNORE_GAP', 'BACKFILL_GAP', 'START_NEW_CUTOVER'];

    protected $guarded = ['*'];

    protected $hidden = ['credential'];

    protected function casts(): array
    {
        return [
            'credential' => 'encrypted', 'cutover_date' => 'date:Y-m-d', 'last_pulled_at' => 'datetime',
            'pull_interval_minutes' => 'integer', 'consecutive_failures' => 'integer', 'settings' => 'array', 'lifecycle_version' => 'integer',
            'feed_access_since' => 'datetime', 'feed_checked_at' => 'datetime', 'feed_next_attempt_at' => 'datetime',
            'source_high_water' => 'integer', 'source_high_water_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(ExternalEvent::class, 'connection_id');
    }

    public function gaps(): HasMany
    {
        return $this->hasMany(IntegrationGap::class, 'connection_id');
    }
}
