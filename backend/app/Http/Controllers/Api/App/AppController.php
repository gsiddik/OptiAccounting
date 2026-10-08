<?php

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Validation\Rules\Password;

/** Shared helpers for tenant-portal controllers; the tenant always comes from the context, never the request. */
abstract class AppController extends Controller
{
    public function __construct(protected readonly TenantContext $context) {}

    protected function tenantId(): string
    {
        return $this->context->tenantId() ?? abort(403);
    }

    protected function passwordRule(): Password
    {
        return Password::min(12)->mixedCase()->numbers();
    }
}
