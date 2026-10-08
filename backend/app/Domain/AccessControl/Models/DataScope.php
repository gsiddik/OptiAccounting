<?php

namespace App\Domain\AccessControl\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DataScope extends Model
{
    use BelongsToTenant, HasUuids;

    public const TENANT = 'TENANT';

    public const BRANCH = 'BRANCH';

    public const BUSINESS_UNIT = 'BUSINESS_UNIT';

    public const OWN = 'OWN';

    public const TYPES = [self::TENANT, self::BRANCH, self::BUSINESS_UNIT, self::OWN];

    protected $fillable = [];
}
