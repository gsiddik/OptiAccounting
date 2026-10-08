<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class JournalLineDimension extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [];
}
