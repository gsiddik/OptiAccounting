<?php

namespace App\Domain\Closing\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A manual closing step the tenant wants on every period (an ACTIVE template becomes one checklist item per period). */
class ClosingChecklistTemplate extends Model
{
    use BelongsToTenant, HasUuids;

    public const ACTIVE = 'ACTIVE';

    public const INACTIVE = 'INACTIVE';

    protected $fillable = ['code', 'title', 'description', 'required', 'sort_order'];

    protected function casts(): array
    {
        return ['required' => 'boolean', 'sort_order' => 'integer'];
    }
}
