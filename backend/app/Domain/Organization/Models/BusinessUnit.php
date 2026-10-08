<?php

namespace App\Domain\Organization\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessUnit extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['code', 'name'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
