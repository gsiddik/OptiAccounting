<?php

namespace App\Console\Commands;

use App\Domain\Integration\Services\IntegrationRunner;
use Illuminate\Console\Command;

class IntegrationProcess extends Command
{
    protected $signature = 'integration:process';

    protected $description = 'Process received integration events that are due, including failed ones whose backoff has elapsed';

    public function handle(IntegrationRunner $runner): int
    {
        $totals = $runner->processDue();
        $this->components->info("Processed {$totals['events']}, posted {$totals['posted']}.");

        return self::SUCCESS;
    }
}
