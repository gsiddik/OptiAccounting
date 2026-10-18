<?php

namespace App\Console\Commands;

use App\Domain\Integration\Services\IntegrationRunner;
use Illuminate\Console\Command;

class IntegrationPull extends Command
{
    protected $signature = 'integration:pull';

    protected $description = 'Pull the event feed of every ACTIVE integration connection that is due (entitled, writable tenants only)';

    public function handle(IntegrationRunner $runner): int
    {
        $totals = $runner->pullDue();
        $this->components->info("Due {$totals['connections']}, pulled {$totals['pulled']}, new events {$totals['received']}, completed {$totals['posted']}, journals posted {$totals['journals']}.");

        return self::SUCCESS;
    }
}
