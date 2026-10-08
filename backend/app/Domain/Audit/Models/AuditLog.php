<?php

namespace App\Domain\Audit\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Append-only (database trigger rejects UPDATE/DELETE). Written only by AuditService. */
class AuditLog extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['changes' => 'array', 'context' => 'array', 'occurred_at' => 'datetime'];
    }
}
