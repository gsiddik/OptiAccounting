<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AccountingEvent extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'posting_date' => 'date:Y-m-d', 'processed_at' => 'datetime'];
    }
}
