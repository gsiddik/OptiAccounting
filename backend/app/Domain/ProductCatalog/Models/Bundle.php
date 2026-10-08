<?php

namespace App\Domain\ProductCatalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bundle extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'name', 'description'];

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'bundle_modules')->withTimestamps();
    }

    public function capacities(): HasMany
    {
        return $this->hasMany(BundleCapacity::class);
    }
}
