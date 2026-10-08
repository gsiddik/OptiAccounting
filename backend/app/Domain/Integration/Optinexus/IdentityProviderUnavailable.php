<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Shared\DomainException;

/** OptiNexus could not answer. Access decisions fail closed; nothing is cached from a failed call. */
class IdentityProviderUnavailable extends DomainException
{
    public function __construct(string $reason = 'OptiNexus did not answer.')
    {
        parent::__construct('The identity provider is temporarily unavailable. Please try again shortly.', 'IDENTITY_PROVIDER_UNAVAILABLE', 503, ['reason' => $reason]);
    }
}
