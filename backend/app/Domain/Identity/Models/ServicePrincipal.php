<?php

namespace App\Domain\Identity\Models;

use App\Domain\Shared\DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A machine identity of one tenant (registry, ecosystem audit A23). Rows are issued by the platform operator only and never change, except
 * for their revocation, which is final: the service layer (here) and the database (trigger service_principals_guard) both refuse anything
 * else. Not tenant-scoped on purpose: tenants never read or write this table; the registry service and the platform API address a tenant
 * explicitly.
 */
class ServicePrincipal extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [];

    private const REVOCATION = ['revoked_at', 'revoked_by', 'revoked_by_label', 'revoke_reason'];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $principal) {
            if ($principal->getOriginal('revoked_at') !== null || array_diff(array_keys($principal->getDirty()), self::REVOCATION) !== []) {
                throw new DomainException('A service principal cannot be edited; it can only be revoked.', 'SERVICE_PRINCIPAL_IMMUTABLE', 409);
            }
        });
        static::deleting(function () {
            throw new DomainException('A service principal is never removed; revoke it instead.', 'SERVICE_PRINCIPAL_IMMUTABLE', 409);
        });
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
