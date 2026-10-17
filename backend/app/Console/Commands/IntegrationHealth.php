<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Integration\Services\ConnectionService;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Monitors every listening integration connection (ACTIVE or ERROR) of every tenant and reports what a person has to look at: a source that
 * refuses or does not answer, a feed that fell behind our cursor, a recorded event that never arrived (a payment or correction waiting for an
 * invoice), quarantined events, dead letters, a stalled queue (ecosystem audit A17 / A19, consumer side). Read-only: it never moves a cursor,
 * an event or a document, so it can never touch posted history. Every ALERT is written to the log at critical level (the channel an operator
 * watches); the exit code is non-zero while there is one, so a scheduler or a deployment check can act on it. `--json` prints the findings.
 */
class IntegrationHealth extends Command
{
    protected $signature = 'integration:health {--json : Print the findings as JSON}';

    protected $description = 'Report integration connections that need attention (refused or silent source, feed behind the cursor, missing recorded events, quarantine); logs ALERTs, exits non-zero on one';

    public function handle(TenantContext $context, ConnectionService $connections): int
    {
        $findings = [];
        $tenantIds = $context->withoutTenantScope(fn () => IntegrationConnection::query()->whereIn('status', [IntegrationConnection::ACTIVE, IntegrationConnection::ERROR])->distinct()->pluck('tenant_id'));

        foreach ($tenantIds as $tenantId) {
            $context->runAs($tenantId, function () use (&$findings, $connections, $tenantId) {
                $code = Tenant::query()->whereKey($tenantId)->value('code');
                foreach (IntegrationConnection::query()->whereIn('status', [IntegrationConnection::ACTIVE, IntegrationConnection::ERROR])->orderBy('id')->get() as $connection) {
                    $result = $connections->summary($connection->id)['health'];
                    if ($result['alerts'] === []) {
                        continue;
                    }
                    $finding = ['tenant' => $code, 'connection_id' => $connection->id, 'name' => $connection->name, 'level' => $result['level'], 'alerts' => $result['alerts']];
                    $findings[] = $finding;
                    if ($result['level'] === 'ALERT') {
                        Log::critical('integration.health', $finding);
                    }
                }
            });
        }

        if ($this->option('json')) {
            $this->line(json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($findings === []) {
            $this->components->info('Every listening integration connection is healthy.');
        } else {
            foreach ($findings as $f) {
                $this->components->warn("{$f['tenant']} / {$f['name']}: {$f['level']} ".implode(', ', array_map(fn (array $a) => $a['code'], $f['alerts'])));
            }
        }

        return in_array('ALERT', array_column($findings, 'level'), true) ? self::FAILURE : self::SUCCESS;
    }
}
