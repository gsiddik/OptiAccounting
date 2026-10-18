<?php

namespace App\Domain\Integration\Optinexus\Delegation;

use App\Domain\Shared\DomainException;

/**
 * A delegation call that did not succeed, already translated into this API's vocabulary (docs/architecture/OPTINEXUS_ADAPTER.md §10).
 * `errorCode` is the stable machine-readable code the SPA turns into English or Indonesian text; `details` carries the facts that
 * text needs (the permissions or roles OptiNexus refused, the correlation reference). `upstream` is the code OptiNexus answered with.
 */
class DelegationFailure extends DomainException
{
    /**
     * @param  array<string,mixed>  $details
     */
    public function __construct(string $message, string $errorCode, int $status, array $details = [], public readonly ?string $upstream = null)
    {
        parent::__construct($message, $errorCode, $status, $details);
    }
}
