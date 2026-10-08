<?php

namespace App\Domain\ProductCatalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    use HasUuids;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    protected $fillable = ['code', 'name', 'description', 'commercially_available', 'sort_order'];

    protected function casts(): array
    {
        return ['commercially_available' => 'boolean', 'sort_order' => 'integer'];
    }

    public function features(): HasMany
    {
        return $this->hasMany(Feature::class)->orderBy('sort_order');
    }

    /** Modules this module directly requires. */
    public function requires(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'module_dependencies', 'module_id', 'requires_module_id')->withTimestamps();
    }

    /** Modules that directly require this module. */
    public function requiredBy(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'module_dependencies', 'requires_module_id', 'module_id')->withTimestamps();
    }
}
