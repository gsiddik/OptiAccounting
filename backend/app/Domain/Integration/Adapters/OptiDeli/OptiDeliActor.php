<?php

namespace App\Domain\Integration\Adapters\OptiDeli;

use App\Domain\Identity\Services\ServicePrincipalKinds;
use App\Domain\Integration\Adapters\OptiFleet\OptiFleetActor;

/**
 * The identity that prepares and posts what the OptiDeli adapter books: the same rules as OptiFleetActor (no password, no role, a TENANT
 * scope, issued only by the platform operator and never created here), under its own principal kind OPTIDELI_INTEGRATION.
 */
class OptiDeliActor extends OptiFleetActor
{
    protected function kind(): string
    {
        return ServicePrincipalKinds::OPTIDELI_INTEGRATION;
    }
}
