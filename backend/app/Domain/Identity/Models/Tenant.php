<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class Tenant extends Model
{
    use HasUuids;

    public const DRAFT = 'DRAFT';

    public const ACTIVE = 'ACTIVE';

    public const SUSPENDED = 'SUSPENDED';

    public const INACTIVE = 'INACTIVE';

    public const TERMINATED = 'TERMINATED';

    /** Allowed lifecycle transitions; TERMINATED is final. */
    public const TRANSITIONS = [
        self::DRAFT => [self::ACTIVE],
        self::ACTIVE => [self::SUSPENDED, self::INACTIVE],
        self::SUSPENDED => [self::ACTIVE, self::INACTIVE],
        self::INACTIVE => [self::ACTIVE, self::TERMINATED],
        self::TERMINATED => [],
    ];

    // status is deliberately not fillable: it only changes through TenantService::transition().
    protected $fillable = [
        'code', 'name', 'legal_name', 'timezone', 'default_locale', 'default_currency',
        'contact_name', 'contact_email', 'contact_phone',
    ];

    protected function casts(): array
    {
        return ['optinexus_deactivated_at' => 'datetime', 'optinexus_synced_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    /** Today's business date in the tenant's timezone (entitlement windows are evaluated on it). */
    public function businessDate(?Carbon $now = null): string
    {
        return ($now ?? now())->copy()->setTimezone($this->timezone)->toDateString();
    }
}
