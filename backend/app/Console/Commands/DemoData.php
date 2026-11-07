<?php

namespace App\Console\Commands;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Optinexus\EntitlementProjector;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo books of the OptiEntry part of the demo ecosystem (see `ecosystem:seed-demo` in OptiNexus): the organization with two branches,
 * the chart of accounts, an opening balance, posted and pending journals, payables, expenses, cash and bank, and receivables, all
 * through the services of the application. The tenant is linked to the OptiNexus tenant, its local demo subscription is retired and the
 * entitlements are projected from OptiNexus, so the first single sign-on of the administrator finds the books already there. The users
 * that appear in the books cannot sign in. Safe to run again. Local installations only.
 */
class DemoData extends Command
{
    protected $signature = 'ecosystem:demo-data {--nexus-tenant-id= : Id of the tenant in OptiNexus} {--code=demo-ekosistem}';

    protected $description = 'Load the OptiEntry part of the demo ecosystem (organization and demo books) into a tenant linked to OptiNexus';

    public function handle(EntitlementProjector $projector): int
    {
        if (! app()->environment(['local', 'testing']) && ! filter_var(env('ECOSYSTEM_ALLOW_DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->error('Demo data is for local installations. Set ECOSYSTEM_ALLOW_DEMO_SEED=true to allow it elsewhere.');

            return self::FAILURE;
        }
        $nexusId = (string) $this->option('nexus-tenant-id');
        if ($nexusId === '') {
            $this->error('Give --nexus-tenant-id (the id printed by ecosystem:seed-demo in OptiNexus).');

            return self::FAILURE;
        }
        $code = (string) $this->option('code');
        $linked = Tenant::query()->where('optinexus_tenant_id', $nexusId)->first();
        if ($linked && $linked->code !== $code) {
            $this->error("The OptiNexus tenant is already linked to the tenant {$linked->code}.");

            return self::FAILURE;
        }
        if (! Tenant::query()->where('code', $code)->exists()) {
            // In optinexus mode roles and permissions come from OptiNexus; the demo books need the local ones while they are written.
            $mode = config('optientry.identity_mode');
            config(['optientry.identity_mode' => 'standalone']);
            try {
                app(DemoSeeder::class)->seedLinked($code, 'Demo Ekosistem', 'demo-ekosistem.test', Str::random(40));
            } finally {
                config(['optientry.identity_mode' => $mode]);
            }
            $this->line('demo books created');
        }
        $tenant = Tenant::query()->where('code', $code)->firstOrFail();
        DB::table('tenants')->where('id', $tenant->id)->update(['optinexus_tenant_id' => $nexusId]);
        // The local demo subscription would block the projection from OptiNexus ("a live subscription that is not from OptiNexus").
        DB::table('subscriptions')->where('tenant_id', $tenant->id)->where('source', '<>', 'OPTINEXUS')->whereIn('status', ['PENDING', 'ACTIVE', 'PAST_DUE', 'SUSPENDED'])
            ->update(['status' => 'CANCELLED', 'updated_at' => now()]);
        // The bundle's own entitlement windows would overlap the projected ones (exclusion constraint); OptiNexus decides from here on.
        foreach (['tenant_module_entitlements', 'tenant_feature_entitlements', 'tenant_capacity_limits'] as $table) {
            DB::table($table)->where('tenant_id', $tenant->id)->where('source', '<>', 'OPTINEXUS')->delete();
        }
        $result = $projector->sync($tenant->refresh());
        $this->line('entitlements from OptiNexus: '.(isset($result['skipped']) ? 'skipped ('.$result['skipped'].')' : ($result['changed'] ? 'projected' : 'unchanged')));
        $this->info('OptiEntry demo data ready for '.$tenant->name.'.');

        return self::SUCCESS;
    }
}
