<?php

namespace App\Domain\Entitlement\Models;

use App\Domain\ProductCatalog\Models\Module;
use App\Domain\Shared\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantModuleEntitlement extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'tenant_module_entitlements';

    protected $fillable = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date:Y-m-d', 'effective_until' => 'date:Y-m-d'];
    }

    /** True when $date (Y-m-d, tenant business date) lies inside the inclusive window. */
    public function coversDate(string $date): bool
    {
        return $this->effective_from->toDateString() <= $date
            && ($this->effective_until === null || $this->effective_until->toDateString() >= $date);
    }

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
