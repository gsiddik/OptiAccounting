<?php

namespace App\Domain\Shared;

use RuntimeException;

/** A business-rule refusal that maps to an HTTP status with a stable code (rendered in bootstrap/app.php). */
class DomainException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 422, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}
