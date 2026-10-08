<?php

namespace App\Domain\ProductCatalog\Models;

use Illuminate\Database\Eloquent\Model;

class BundleCapacity extends Model
{
    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['bundle_id', 'limit_code', 'limit_value'];
}
