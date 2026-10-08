<?php

namespace App\Http\Controllers\Api\App;

use App\Domain\AccessControl\AccessRequest;
use App\Domain\AccessControl\Services\EffectiveAccess;
use App\Support\IdentityMode;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the current user can see and do in this tenant; the SPA navigation is built from this. */
class CapabilityController extends AppController
{
    public function __construct(TenantContext $context, private readonly EffectiveAccess $access)
    {
        parent::__construct($context);
    }

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json([...$this->access->capabilities(new AccessRequest($request->user(), $this->tenantId())), 'identity' => IdentityMode::describe()]);
    }
}
