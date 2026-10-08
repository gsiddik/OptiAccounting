<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Identity\Models\Tenant;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Closure;
use Illuminate\Validation\Rules\Password;

/** Shared helpers for platform controllers (platform scope has no tenant context of its own). */
abstract class PlatformController extends Controller
{
    public function __construct(protected readonly TenantContext $context) {}

    /** Run a tenant-owned query/mutation for one explicitly chosen tenant. */
    protected function inTenant(Tenant $tenant, Closure $callback): mixed
    {
        return $this->context->runAs($tenant->id, $callback);
    }

    protected function passwordRule(): Password
    {
        return Password::min(12)->mixedCase()->numbers();
    }
}
