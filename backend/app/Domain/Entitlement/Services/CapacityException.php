<?php

namespace App\Domain\Entitlement\Services;

use RuntimeException;

class CapacityException extends RuntimeException
{
    public function __construct(public readonly string $limitCode, public readonly ?int $limit, public readonly int $used)
    {
        parent::__construct("Capacity {$limitCode} reached ({$used} of {$limit}).");
    }
}
