<?php

namespace App\Domain\Accounting\Models;

use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalYear extends Model
{
    use BelongsToTenant, HasUuids;

    public const DRAFT = 'DRAFT';

    public const OPEN = 'OPEN';

    public const CLOSED = 'CLOSED';

    protected $fillable = ['code', 'name', 'start_date', 'end_date'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d', 'end_date' => 'date:Y-m-d'];
    }

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class)->orderBy('number');
    }
}
