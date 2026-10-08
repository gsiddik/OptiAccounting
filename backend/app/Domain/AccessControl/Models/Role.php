<?php

namespace App\Domain\AccessControl\Models;

use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Platform roles (tenant_id null) and tenant roles (tenant-owned) share the table.
 * Tenant queries must go through scopeForTenant(); platform queries through scopePlatform().
 * Roles are permission collections only: no code path may authorize by role name.
 */
class Role extends Model
{
    use HasUuids;

    public const PLATFORM = 'platform';

    public const TENANT = 'tenant';

    protected $fillable = ['name', 'description'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function scopePlatform(Builder $query): Builder
    {
        return $query->where('scope', self::PLATFORM)->whereNull('tenant_id');
    }

    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('scope', self::TENANT)->where('tenant_id', $tenantId);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions', 'role_id', 'permission_id')
            ->withPivot('scope')->withTimestamps();
    }
}
