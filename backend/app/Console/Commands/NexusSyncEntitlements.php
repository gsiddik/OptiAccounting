<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Optinexus\EntitlementProjector;
use App\Domain\Integration\Optinexus\IdentityProviderUnavailable;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use Illuminate\Console\Command;

class NexusSyncEntitlements extends Command
{
    protected $signature = 'optiaccounting:nexus:sync-entitlements {--tenant= : Only this local tenant id}';

    protected $description = 'Project the OptiNexus subscription, modules, features and capacity of linked tenants into the local entitlement tables';

    public function handle(EntitlementProjector $projector): int
    {
        if (! OptinexusSettings::active()) {
            $this->components->info('Identity mode is not optinexus; nothing to sync.');

            return self::SUCCESS;
        }

        $failed = 0;
        $tenants = Tenant::query()->whereNotNull('optinexus_tenant_id')->when($this->option('tenant'), fn ($q, $id) => $q->whereKey($id))->orderBy('code')->get();

        foreach ($tenants as $tenant) {
            try {
                $result = $projector->sync($tenant);
                $this->line("{$tenant->code}: ".(isset($result['skipped']) ? "skipped, {$result['skipped']} present (migrate it first)" : ($result['changed'] ? implode('; ', $result['changes']) : 'no change')));
            } catch (IdentityProviderUnavailable $e) {
                $failed++;
                $this->components->warn("{$tenant->code}: OptiNexus unavailable (".($e->details['reason'] ?? '').'); the last projection stays.');
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
