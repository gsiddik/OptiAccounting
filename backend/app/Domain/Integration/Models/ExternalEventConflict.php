<?php

namespace App\Domain\Integration\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evidence of a payload that arrived with the id of a stored event but different content. The stored event is never changed; a person decides. */
class ExternalEventConflict extends Model
{
    use BelongsToTenant, HasUuids;

    public const USE_ORIGINAL = 'USE_ORIGINAL';

    public const REJECT = 'REJECT';

    public const RESOLUTIONS = [self::USE_ORIGINAL, self::REJECT];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['envelope' => 'array', 'received_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ExternalEvent::class, 'external_event_id');
    }
}
