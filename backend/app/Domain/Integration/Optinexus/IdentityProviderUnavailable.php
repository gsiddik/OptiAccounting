<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Shared\DomainException;

/**
 * OptiNexus could not answer. Access decisions fail closed; nothing is cached from a failed call.
 * `$retryAfter` (seconds) is set when OptiNexus throttled us and said when to come back; `$transient` marks a failure
 * (no connection, 5xx) that is worth one repetition when the call is safe to repeat; `$configuration` marks a failure that
 * waiting does not cure (the service account is not configured, or OptiNexus refused its credentials or scope).
 */
class IdentityProviderUnavailable extends DomainException
{
    public function __construct(string $reason = 'OptiNexus did not answer.', public readonly ?int $retryAfter = null, public readonly bool $transient = false, public readonly bool $configuration = false)
    {
        parent::__construct(
            'The identity provider is temporarily unavailable. Please try again shortly.',
            'IDENTITY_PROVIDER_UNAVAILABLE',
            503,
            ['reason' => $reason] + ($retryAfter !== null ? ['retry_after' => $retryAfter] : []),
        );
    }
}
