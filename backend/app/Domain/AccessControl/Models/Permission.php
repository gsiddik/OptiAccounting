<?php

namespace App\Domain\AccessControl\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'scope', 'group', 'description'];
}
