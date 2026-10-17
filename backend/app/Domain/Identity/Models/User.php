<?php

namespace App\Domain\Identity\Models;

use App\Domain\AccessControl\Models\Role;
use App\Domain\Identity\Services\ReservedAddress;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasUuids;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    public const SUSPENDED = 'SUSPENDED';

    protected $fillable = ['name', 'email'];

    protected $hidden = ['password'];

    protected static function booted(): void
    {
        // The reserved address range belongs to service principals (ServicePrincipalRegistry); no person is created on, or moved to, it.
        static::saving(function (self $user) {
            if (! $user->exists || $user->isDirty('email')) {
                ReservedAddress::assertHuman($user->email);
            }
        });
    }

    protected function casts(): array
    {
        return ['password' => 'hashed', 'last_login_at' => 'datetime', 'optinexus_deactivated_at' => 'datetime'];
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function platformRoles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'platform_role_assignments', 'user_id', 'role_id')->withTimestamps();
    }
}
