<?php

namespace App\Domain\Identity\Models;

use App\Domain\AccessControl\Models\Role;
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

    protected function casts(): array
    {
        return ['password' => 'hashed', 'last_login_at' => 'datetime'];
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
