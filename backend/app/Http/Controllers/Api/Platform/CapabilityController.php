<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\AccessControl\AccessRequest;
use App\Domain\AccessControl\Services\EffectiveAccess;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The platform permissions of the current operator; the Platform Portal navigation is built from this. */
class CapabilityController extends PlatformController
{
    public function __construct(TenantContext $context, private readonly EffectiveAccess $access)
    {
        parent::__construct($context);
    }

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json($this->access->capabilities(new AccessRequest($request->user(), null)));
    }
}
