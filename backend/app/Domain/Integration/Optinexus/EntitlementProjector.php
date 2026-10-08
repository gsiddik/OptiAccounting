<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Entitlement\Services\CapacityService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Services\TenantService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Projects OptiNexus's commercial state of a tenant into the local entitlement tables (`source = OPTINEXUS`) so the
 * one EffectiveAccess resolver keeps working unchanged (docs/architecture/OPTINEXUS_ADAPTER.md §5).
 *
 * OptiNexus capability codes equal the local module and feature codes. A module entitlement carries all of the
 * module's active features unless OptiNexus names a feature explicitly as false (same as a local bundle). A module
 * whose required modules are not granted is dropped. Subscription: the most restrictive live OptiNexus subscription
 * wins (PAST_DUE => READ_ONLY), because the machine API does not say which product a subscription belongs to.
 * One OPTINEXUS row per tenant+module/feature changes state ACTIVE/DISABLED instead of being deleted (no overlapping
 * windows; the change history is in the audit log). Idempotent: a run that finds nothing to change writes nothing.
 */
class EntitlementProjector
{
    private const LIVE = ['TRIAL', 'ACTIVE', 'GRACE_PERIOD', 'PAST_DUE'];

    public function __construct(
        private readonly NexusApi $api,
        private readonly AccessCache $cache,
        private readonly TenantService $tenants,
        private readonly AuditService $audit,
    ) {}

    /**
     * The commercial context of one OptiNexus tenant, or null when OptiNexus does not know the tenant.
     *
     * @return array<string,mixed>|null
     *
     * @throws IdentityProviderUnavailable
     */
    public function fetch(string $nexusTenantId): ?array
    {
        $response = $this->api->send('GET', "/tenants/{$nexusTenantId}/commercial-context");

        return $response->status() === 404 ? null : $this->api->data($response);
    }

    /** Re-reads the tenant's commercial state when the projection is older than the configured TTL. */
    public function syncIfStale(Tenant $tenant): void
    {
        $age = $tenant->optinexus_synced_at ? now()->diffInSeconds($tenant->optinexus_synced_at, true) : null;

        if ($age === null || $age >= (int) config('optiaccounting.optinexus.entitlement_ttl_seconds')) {
            $this->sync($tenant);
        }
    }

    /**
     * @return array{changed:bool,changes:list<string>,skipped?:string}
     *
     * @throws IdentityProviderUnavailable
     */
    public function sync(Tenant $tenant): array
    {
        if (! $tenant->optinexus_tenant_id) {
            return ['changed' => false, 'changes' => []];
        }

        $context = $this->fetch((string) $tenant->optinexus_tenant_id);
        // OptiNexus no longer knows the tenant: treat as terminated there.
        $context ??= ['tenant_status' => 'TERMINATED', 'subscriptions' => [], 'applications_enabled' => [], 'effective_entitlements' => []];

        return $this->project($tenant, $context);
    }

    /**
     * Applies an already fetched context. Pure database work; safe to call concurrently (tenant row lock).
     *
     * @param  array<string,mixed>  $context
     * @return array{changed:bool,changes:list<string>,skipped?:string}
     */
    public function project(Tenant $tenant, array $context): array
    {
        $changes = DB::transaction(function () use ($tenant, $context) {
            DB::table('tenants')->where('id', $tenant->id)->lockForUpdate()->value('id');
            $tenant->refresh();

            $changes = [];
            $date = $tenant->businessDate();

            // Leftover local commercial data (a mode switch without its migration): never mix it with the projection.
            $local = DB::table('subscriptions')->where('tenant_id', $tenant->id)->whereIn('status', ['PENDING', 'ACTIVE', 'PAST_DUE', 'SUSPENDED'])->where('source', '<>', 'OPTINEXUS')->first();
            if ($local) {
                Log::warning('optinexus: a live subscription that is not from OptiNexus blocks the projection', ['tenant_id' => $tenant->id, 'subscription_id' => $local->id]);
                DB::table('tenants')->where('id', $tenant->id)->update(['optinexus_synced_at' => now()]);

                return ['skipped' => 'local subscription'];
            }

            $desired = $this->desired($context);

            $subscriptionId = $this->projectSubscription($tenant, $desired['subscription'], $date, $changes);
            $this->projectModules($tenant, $desired['modules'], $subscriptionId, $date, $changes);
            $this->projectFeatures($tenant, $desired['features'], $date, $changes);
            $this->projectCapacity($tenant, $desired['capacity'], $changes);
            $this->mirrorTenantStatus($tenant, (string) ($context['tenant_status'] ?? 'ACTIVE'), $changes);

            DB::table('tenants')->where('id', $tenant->id)->update(['optinexus_synced_at' => now()]);

            if ($changes !== []) {
                $this->cache->touchTenant($tenant->id);
                $this->audit->record('entitlement.projected', 'tenant', $tenant->id, null, ['source' => 'OPTINEXUS', 'changes' => $changes], $tenant->id);
            }

            return $changes;
        });

        $tenant->refresh();

        if (isset($changes['skipped'])) {
            return ['changed' => false, 'changes' => [], 'skipped' => $changes['skipped']];
        }

        return ['changed' => $changes !== [], 'changes' => $changes];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{subscription:?array{status:string,reference:?string},modules:array<string,bool>,features:array<string,bool>,capacity:array<string,?int>}
     */
    private function desired(array $context): array
    {
        $application = OptinexusSettings::applicationCode();
        $enabled = in_array($application, (array) ($context['applications_enabled'] ?? []), true);

        $values = [];
        foreach ((array) ($context['effective_entitlements'] ?? []) as $row) {
            if (isset($row['entitlement_key'])) {
                $values[(string) $row['entitlement_key']] = ['type' => $row['entitlement_type'] ?? null, 'value' => $row['value'] ?? null];
            }
        }

        // Subscription: the most restrictive live one.
        $subscription = null;
        if ($enabled) {
            foreach ((array) ($context['subscriptions'] ?? []) as $sub) {
                $status = (string) ($sub['status'] ?? '');
                if (! in_array($status, self::LIVE, true)) {
                    continue;
                }
                $mapped = $status === 'PAST_DUE' ? 'PAST_DUE' : 'ACTIVE';
                if ($subscription === null || ($mapped === 'PAST_DUE' && $subscription['status'] !== 'PAST_DUE')) {
                    $subscription = ['status' => $mapped, 'reference' => isset($sub['id']) ? (string) $sub['id'] : null];
                }
            }
        }

        $moduleRows = DB::table('modules')->where('status', 'ACTIVE')->pluck('code', 'id');
        $granted = [];
        if ($subscription !== null) {
            foreach ($moduleRows as $code) {
                if (($values[$code]['type'] ?? null) === 'CAPABILITY' && ($values[$code]['value'] ?? null) === true) {
                    $granted[$code] = true;
                }
            }
        }

        // Drop modules whose requirements are not granted, until stable.
        $requires = DB::table('module_dependencies as d')
            ->join('modules as m', 'm.id', '=', 'd.module_id')->join('modules as r', 'r.id', '=', 'd.requires_module_id')
            ->get(['m.code as module', 'r.code as required'])->groupBy('module');
        do {
            $dropped = false;
            foreach (array_keys($granted) as $code) {
                foreach ($requires->get($code, collect()) as $dependency) {
                    if (! isset($granted[$dependency->required])) {
                        unset($granted[$code]);
                        $dropped = true;
                        break;
                    }
                }
            }
        } while ($dropped);

        $modules = [];
        foreach ($moduleRows as $code) {
            $modules[$code] = isset($granted[$code]);
        }

        $features = [];
        foreach (DB::table('features as f')->join('modules as m', 'm.id', '=', 'f.module_id')->where('f.status', 'ACTIVE')->get(['f.code', 'm.code as module']) as $feature) {
            $explicitOff = ($values[$feature->code]['type'] ?? null) === 'CAPABILITY' && ($values[$feature->code]['value'] ?? null) === false;
            $features[$feature->code] = isset($granted[$feature->module]) && ! $explicitOff;
        }

        $capacity = [];
        foreach (CapacityService::codes() as $code) {
            $row = $values[strtolower($code)] ?? null;
            $value = $row['value'] ?? null;
            $capacity[$code] = ($row === null || $value === 'unlimited') ? null : max(0, (int) round((float) $value));
        }

        return ['subscription' => $subscription, 'modules' => $modules, 'features' => $features, 'capacity' => $capacity];
    }

    /** @param  list<string>  $changes */
    private function projectSubscription(Tenant $tenant, ?array $desired, string $date, array &$changes): ?string
    {
        $live = DB::table('subscriptions')->where('tenant_id', $tenant->id)->whereIn('status', ['PENDING', 'ACTIVE', 'PAST_DUE', 'SUSPENDED'])->first();

        if ($desired === null) {
            if ($live) {
                DB::table('subscriptions')->where('id', $live->id)->update([
                    'status' => 'EXPIRED', 'ends_on' => max($date, (string) $live->starts_on), 'updated_at' => now(),
                ]);
                $changes[] = "subscription: {$live->status} -> EXPIRED";
            }

            return null;
        }

        if (! $live) {
            $id = (string) Str::uuid7();
            DB::table('subscriptions')->insert([
                'id' => $id, 'tenant_id' => $tenant->id, 'bundle_id' => null, 'status' => $desired['status'], 'source' => 'OPTINEXUS',
                'external_reference' => $desired['reference'], 'starts_on' => $date, 'ends_on' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $changes[] = "subscription: created {$desired['status']}";

            return $id;
        }

        if ($live->status !== $desired['status'] || $live->external_reference !== $desired['reference']) {
            DB::table('subscriptions')->where('id', $live->id)->update([
                'status' => $desired['status'], 'external_reference' => $desired['reference'], 'updated_at' => now(),
            ]);
            $changes[] = "subscription: {$live->status} -> {$desired['status']}";
        }

        return $live->id;
    }

    /**
     * @param  array<string,bool>  $modules
     * @param  list<string>  $changes
     */
    private function projectModules(Tenant $tenant, array $modules, ?string $subscriptionId, string $date, array &$changes): void
    {
        $ids = DB::table('modules')->pluck('id', 'code');
        $rows = DB::table('tenant_module_entitlements')->where('tenant_id', $tenant->id)->where('source', 'OPTINEXUS')->get()->keyBy('module_id');

        foreach ($modules as $code => $on) {
            $state = $on ? 'ACTIVE' : 'DISABLED';
            $row = $rows->get($ids[$code]);

            if (! $row) {
                if ($on && $this->blockedByLocalRow('tenant_module_entitlements', 'module_id', $tenant->id, $ids[$code], $date)) {
                    continue;
                }
                if ($on) {
                    DB::table('tenant_module_entitlements')->insert([
                        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'module_id' => $ids[$code], 'state' => 'ACTIVE', 'source' => 'OPTINEXUS',
                        'subscription_id' => $subscriptionId, 'effective_from' => $date, 'effective_until' => null, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $changes[] = "module {$code}: granted";
                }
            } elseif ($row->state !== $state || $row->subscription_id !== $subscriptionId) {
                DB::table('tenant_module_entitlements')->where('id', $row->id)->update(['state' => $state, 'subscription_id' => $subscriptionId, 'updated_at' => now()]);
                if ($row->state !== $state) {
                    $changes[] = "module {$code}: {$row->state} -> {$state}";
                }
            }
        }
    }

    /**
     * @param  array<string,bool>  $features
     * @param  list<string>  $changes
     */
    private function projectFeatures(Tenant $tenant, array $features, string $date, array &$changes): void
    {
        $ids = DB::table('features')->pluck('id', 'code');
        $rows = DB::table('tenant_feature_entitlements')->where('tenant_id', $tenant->id)->where('source', 'OPTINEXUS')->get()->keyBy('feature_id');

        foreach ($features as $code => $on) {
            $state = $on ? 'ACTIVE' : 'DISABLED';
            $row = $rows->get($ids[$code]);

            if (! $row) {
                if ($on && $this->blockedByLocalRow('tenant_feature_entitlements', 'feature_id', $tenant->id, $ids[$code], $date)) {
                    continue;
                }
                if ($on) {
                    DB::table('tenant_feature_entitlements')->insert([
                        'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'feature_id' => $ids[$code], 'state' => 'ACTIVE', 'source' => 'OPTINEXUS',
                        'effective_from' => $date, 'effective_until' => null, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $changes[] = "feature {$code}: granted";
                }
            } elseif ($row->state !== $state) {
                DB::table('tenant_feature_entitlements')->where('id', $row->id)->update(['state' => $state, 'updated_at' => now()]);
                $changes[] = "feature {$code}: {$row->state} -> {$state}";
            }
        }
    }

    /** A non-OptiNexus entitlement window that is still open would overlap the new row (database exclusion constraint). */
    private function blockedByLocalRow(string $table, string $column, string $tenantId, string $id, string $date): bool
    {
        $blocked = DB::table($table)->where('tenant_id', $tenantId)->where($column, $id)->where('source', '<>', 'OPTINEXUS')
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date))->exists();

        if ($blocked) {
            Log::warning('optinexus: a local entitlement window blocks the projection', ['tenant_id' => $tenantId, 'table' => $table, 'id' => $id]);
        }

        return $blocked;
    }

    /**
     * @param  array<string,?int>  $capacity
     * @param  list<string>  $changes
     */
    private function projectCapacity(Tenant $tenant, array $capacity, array &$changes): void
    {
        $rows = DB::table('tenant_capacity_limits')->where('tenant_id', $tenant->id)->get()->keyBy('limit_code');

        foreach ($capacity as $code => $value) {
            $row = $rows->get($code);

            if (! $row) {
                DB::table('tenant_capacity_limits')->insert([
                    'id' => (string) Str::uuid7(), 'tenant_id' => $tenant->id, 'limit_code' => $code, 'limit_value' => $value,
                    'source' => 'OPTINEXUS', 'created_at' => now(), 'updated_at' => now(),
                ]);
                if ($value !== null) {
                    $changes[] = "capacity {$code}: set {$value}";
                }
            } elseif ($row->source !== 'OPTINEXUS' || ($row->limit_value === null ? null : (int) $row->limit_value) !== $value) {
                DB::table('tenant_capacity_limits')->where('id', $row->id)->update(['limit_value' => $value, 'source' => 'OPTINEXUS', 'updated_at' => now()]);
                $changes[] = "capacity {$code}: ".($row->limit_value ?? 'unlimited').' -> '.($value ?? 'unlimited');
            }
        }
    }

    /**
     * OptiNexus is the authority for whether the organization exists and is in good standing. It switches a tenant
     * off here and back on again only when it was OptiNexus that switched it off (marker), never an operator's own suspension.
     *
     * @param  list<string>  $changes
     */
    private function mirrorTenantStatus(Tenant $tenant, string $nexusStatus, array &$changes): void
    {
        $off = match ($nexusStatus) {
            'SUSPENDED' => Tenant::SUSPENDED,
            'TERMINATED', 'ARCHIVED' => Tenant::INACTIVE,
            default => null,
        };

        if ($off !== null && in_array($tenant->status, [Tenant::ACTIVE, Tenant::SUSPENDED], true) && $tenant->status !== $off) {
            $this->tenants->transition($tenant, $off, "OptiNexus tenant status {$nexusStatus}");
            DB::table('tenants')->where('id', $tenant->id)->update(['optinexus_deactivated_at' => now()]);
            $changes[] = "tenant: {$tenant->status} -> {$off}";
        } elseif ($off !== null && $tenant->status === $off && $tenant->optinexus_deactivated_at === null) {
            DB::table('tenants')->where('id', $tenant->id)->update(['optinexus_deactivated_at' => now()]);
        } elseif ($off === null && $nexusStatus === 'ACTIVE' && $tenant->optinexus_deactivated_at !== null
            && in_array($tenant->status, [Tenant::SUSPENDED, Tenant::INACTIVE], true)) {
            $this->tenants->transition($tenant, Tenant::ACTIVE, 'OptiNexus tenant active again');
            DB::table('tenants')->where('id', $tenant->id)->update(['optinexus_deactivated_at' => null]);
            $changes[] = 'tenant: reactivated';
        }
    }
}
