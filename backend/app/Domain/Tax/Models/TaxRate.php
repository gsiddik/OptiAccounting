<?php

namespace App\Domain\Tax\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One effective-dated rate (percent) of a tax code. A rate is history: only its end can be set; a change is a new rate. */
class TaxRate extends Model
{
    use BelongsToTenant, HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'effective_until' => 'date:Y-m-d'];
    }
}
