<?php

namespace App\Console\Commands;

use App\Domain\Integration\Optinexus\OptinexusEventRelay;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use Illuminate\Console\Command;

class NexusRelayEvents extends Command
{
    protected $signature = 'optiaccounting:nexus:relay-events {--retry-failed : Put FAILED rows back in the queue first} {--tenant= : Only this local tenant id}';

    protected $description = 'Deliver pending outbox events and audit records to OptiNexus';

    public function handle(OptinexusEventRelay $relay): int
    {
        if (! OptinexusSettings::active()) {
            $this->components->info('Identity mode is not optinexus; nothing to relay.');

            return self::SUCCESS;
        }

        $tenant = $this->option('tenant') ?: null;
        if ($this->option('retry-failed')) {
            $this->components->info('Re-queued '.$relay->retryFailed($tenant).' failed row(s).');
        }

        $result = $relay->relay($tenant);
        $this->components->info("Delivered {$result['delivered']}, retrying {$result['retrying']}, failed {$result['failed']}.");

        return self::SUCCESS;
    }
}
