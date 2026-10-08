<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CostCenter extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['code', 'name', 'description', 'branch_id', 'business_unit_id'];
}
