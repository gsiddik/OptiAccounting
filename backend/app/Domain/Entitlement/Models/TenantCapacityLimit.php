<?php

namespace App\Domain\Entitlement\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TenantCapacityLimit extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['limit_value' => 'integer'];
    }
}
