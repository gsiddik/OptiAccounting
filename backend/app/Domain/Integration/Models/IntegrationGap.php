<?php

namespace App\Domain\Integration\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The period in which a connection was not listening (paused or disconnected). It opens with the source sequence at that moment and closes
 * when the connection is switched on again, with the source's head at that moment: the source events in (sequence_at_open, ceiling_sequence]
 * are the gap. What happens to them is decided by the gap policy the connection had when the gap opened (ConnectionService, EventInbox),
 * never by whatever the connection says later.
 */
class IntegrationGap extends Model
{
    use BelongsToTenant, HasUuids;

    public const OPEN = 'OPEN';

    public const CLOSED = 'CLOSED';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime', 'ceiling_sequence' => 'integer', 'sequence_at_open' => 'integer', 'new_cutover_date' => 'date:Y-m-d'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(IntegrationConnection::class, 'connection_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ExternalEvent::class, 'gap_id');
    }
}
