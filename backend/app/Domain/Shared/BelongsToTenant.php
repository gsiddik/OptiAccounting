<?php

namespace App\Domain\Shared;

use App\Domain\Identity\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Marks a model as tenant-owned: global TenantScope on reads, and on create the
 * tenant_id is filled from the context or must equal it. `tenant_id` is never
 * mass-assignable, so request payloads cannot set it.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            $context = app(TenantContext::class);
            $contextTenant = $context->tenantId();

            if ($model->tenant_id === null) {
                if ($contextTenant === null) {
                    throw new LogicException(static::class.' requires a tenant context.');
                }
                $model->tenant_id = $contextTenant;

                return;
            }

            if (! $context->isBypassed() && $contextTenant !== null && $model->tenant_id !== $contextTenant) {
                throw new LogicException('Cross-tenant write refused for '.static::class.'.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
