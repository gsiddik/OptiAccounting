<?php

namespace App\Domain\Organization\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['code', 'name', 'address'];

    public function businessUnits(): HasMany
    {
        return $this->hasMany(BusinessUnit::class);
    }
}
