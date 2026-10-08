<?php

namespace App\Domain\ProductCatalog\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feature extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'name', 'description', 'sort_order'];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
