<?php

namespace App\Domain\Identity\Models;

use App\Domain\AccessControl\Models\DataScope;
use App\Domain\AccessControl\Models\Role;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A user's membership of one tenant (status, roles and data scope are tenant-specific). */
class TenantUser extends Model
{
    use BelongsToTenant, HasUuids;

    public const INVITED = 'INVITED';

    public const ACTIVE = 'ACTIVE';

    public const SUSPENDED = 'SUSPENDED';

    public const INACTIVE = 'INACTIVE';

    public const STATUSES = [self::INVITED, self::ACTIVE, self::SUSPENDED, self::INACTIVE];

    protected $fillable = [];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'tenant_user_roles', 'tenant_user_id', 'role_id')
            ->withPivot('tenant_id')->withTimestamps();
    }

    public function dataScopes(): HasMany
    {
        return $this->hasMany(DataScope::class);
    }
}
