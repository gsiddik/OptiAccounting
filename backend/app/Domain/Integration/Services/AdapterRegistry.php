<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Contracts\IntegrationAdapter;

/**
 * The adapters of this installation, found by source system. Adapters are tagged in the service container with TAG; with none registered
 * every event of every connection ends in NEEDS_REVIEW (NO_ADAPTER) instead of being guessed at, and manual accounting is unaffected.
 */
class AdapterRegistry
{
    public const TAG = 'integration.adapters';

    /** @var array<string,IntegrationAdapter> */
    private array $bySource = [];

    /** @param iterable<IntegrationAdapter> $adapters */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->bySource[$adapter->sourceSystem()] = $adapter;
        }
    }

    public function for(string $sourceSystem): ?IntegrationAdapter
    {
        return $this->bySource[$sourceSystem] ?? null;
    }
}
