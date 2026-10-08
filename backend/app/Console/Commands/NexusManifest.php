<?php

namespace App\Console\Commands;

use App\Domain\Integration\Optinexus\OptinexusManifest;
use Illuminate\Console\Command;

class NexusManifest extends Command
{
    protected $signature = 'optiaccounting:nexus:manifest {--pretty : Indent the JSON}';

    protected $description = 'Print what an OptiNexus administrator must register for OptiAccounting (application, capabilities, permissions, events, scopes, OIDC client)';

    public function handle(OptinexusManifest $manifest): int
    {
        $this->line((string) json_encode($manifest->build(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($this->option('pretty') ? JSON_PRETTY_PRINT : 0)));

        return self::SUCCESS;
    }
}
