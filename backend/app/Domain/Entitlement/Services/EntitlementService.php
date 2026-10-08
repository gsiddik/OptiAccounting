<?php

namespace App\Domain\Entitlement\Services;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Entitlement\Models\TenantCapacityLimit;
use App\Domain\Entitlement\Models\TenantFeatureEntitlement;
use App\Domain\Entitlement\Models\TenantModuleEntitlement;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Feature;
use App\Domain\ProductCatalog\Models\Module;
use App\Domain\ProductCatalog\Services\ModuleDependencyService;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Platform-side management of tenant entitlements (module, feature, capacity).
 * Windows are inclusive business dates in the tenant timezone and never overlap
 * per tenant+module / tenant+feature (database exclusion constraint); history is
 * kept by ending windows, never deleting them. Dependency rules are enforced both
 * ways: activation needs the required modules, deactivation needs no active dependents.
 */
class EntitlementService
{
    public const SOURCES = ['BUNDLE', 'ADD_ON', 'CUSTOM_CONTRACT', 'MANUAL_OVERRIDE', 'OPTINEXUS'];

    public function __construct(
        private readonly AuditService $audit,
        private readonly AccessCache $cache,
        private readonly ModuleDependencyService $dependencies,
        private readonly TenantContext $context,
    ) {}

    public function grantModule(Tenant $tenant, Module $module, string $state, string $source, string $from, ?string $until, ?string $subscriptionId = null): TenantModuleEntitlement
    {
        return DB::transaction(fn () => $this->context->runAs($tenant->id, function () use ($tenant, $module, $state, $source, $from, $until, $subscriptionId) {
            if ($module->status !== Module::ACTIVE) {
                throw new DomainException("Module {$module->code} is inactive.", 'MODULE_INACTIVE');
            }
            if (in_array($source, ['BUNDLE', 'ADD_ON'], true) && ! $module->commercially_available) {
                throw new DomainException("Module {$module->code} is not commercially available.", 'MODULE_NOT_AVAILABLE');
            }
            if ($until !== null && $until < $from) {
                throw new DomainException('effective_until cannot be before effective_from.', 'INVALID_WINDOW');
            }

            if (in_array($state, ['ACTIVE', 'READ_ONLY'], true)) {
                $this->assertRequirementsMet($tenant, $module, $state, $from);
            }

            $row = new TenantModuleEntitlement;
            $row->tenant_id = $tenant->id;
            $row->module_id = $module->id;
            $row->state = $state;
            $row->source = $source;
            $row->subscription_id = $subscriptionId;
            $row->effective_from = $from;
            $row->effective_until = $until;
            $this->saveOrOverlap($row);

            $this->cache->touchTenant($tenant->id);
            $this->audit->record('entitlement.module_granted', 'tenant_module_entitlement', $row->id, null,
                ['module' => $module->code, 'state' => $state, 'source' => $source, 'from' => $from, 'until' => $until], $tenant->id);

            return $row;
        }));
    }

    public function updateModule(TenantModuleEntitlement $row, ?string $state, bool $untilGiven, ?string $until): TenantModuleEntitlement
    {
        return DB::transaction(fn () => $this->context->runAs($row->tenant_id, function () use ($row, $state, $untilGiven, $until) {
            $tenant = Tenant::query()->findOrFail($row->tenant_id);
            $module = Module::query()->findOrFail($row->module_id);
            $before = ['state' => $row->state, 'until' => $row->effective_until?->toDateString()];

            $newState = $state ?? $row->state;
            $newUntil = $untilGiven ? $until : $row->effective_until?->toDateString();
            if ($newUntil !== null && $newUntil < $row->effective_from->toDateString()) {
                throw new DomainException('effective_until cannot be before effective_from.', 'INVALID_WINDOW');
            }

            $today = $tenant->businessDate();
            $weaker = $this->rank($newState) < $this->rank($row->state);
            $shorter = $newUntil !== null && ($row->effective_until === null || $newUntil < $row->effective_until->toDateString());

            if ($weaker || $shorter) {
                // Dependents that stay usable after this change would be left without their requirement.
                $from = $shorter && ! $weaker
                    ? Carbon::parse($newUntil)->addDay()->toDateString()
                    : max($today, $row->effective_from->toDateString());
                $this->assertNoBlockingDependents($tenant->id, $module, $from, $weaker && $newState === 'READ_ONLY');
            } elseif ($this->rank($newState) > $this->rank($row->state) && in_array($newState, ['ACTIVE', 'READ_ONLY'], true)) {
                $this->assertRequirementsMet($tenant, $module, $newState, max($today, $row->effective_from->toDateString()));
            }

            $row->state = $newState;
            $row->effective_until = $newUntil;
            $this->saveOrOverlap($row);

            $this->cache->touchTenant($row->tenant_id);
            $this->audit->record('entitlement.module_updated', 'tenant_module_entitlement', $row->id, $before,
                ['state' => $newState, 'until' => $newUntil], $row->tenant_id);

            return $row;
        }));
    }

    public function grantFeature(Tenant $tenant, Feature $feature, string $state, string $source, string $from, ?string $until): TenantFeatureEntitlement
    {
        return DB::transaction(fn () => $this->context->runAs($tenant->id, function () use ($tenant, $feature, $state, $source, $from, $until) {
            if ($until !== null && $until < $from) {
                throw new DomainException('effective_until cannot be before effective_from.', 'INVALID_WINDOW');
            }

            $row = new TenantFeatureEntitlement;
            $row->tenant_id = $tenant->id;
            $row->feature_id = $feature->id;
            $row->state = $state;
            $row->source = $source;
            $row->effective_from = $from;
            $row->effective_until = $until;
            $this->saveOrOverlap($row);

            $this->cache->touchTenant($tenant->id);
            $this->audit->record('entitlement.feature_granted', 'tenant_feature_entitlement', $row->id, null,
                ['feature' => $feature->code, 'state' => $state, 'source' => $source, 'from' => $from, 'until' => $until], $tenant->id);

            return $row;
        }));
    }

    public function updateFeature(TenantFeatureEntitlement $row, ?string $state, bool $untilGiven, ?string $until): TenantFeatureEntitlement
    {
        return DB::transaction(fn () => $this->context->runAs($row->tenant_id, function () use ($row, $state, $untilGiven, $until) {
            $before = ['state' => $row->state, 'until' => $row->effective_until?->toDateString()];
            $newUntil = $untilGiven ? $until : $row->effective_until?->toDateString();
            if ($newUntil !== null && $newUntil < $row->effective_from->toDateString()) {
                throw new DomainException('effective_until cannot be before effective_from.', 'INVALID_WINDOW');
            }
            $row->state = $state ?? $row->state;
            $row->effective_until = $newUntil;
            $this->saveOrOverlap($row);

            $this->cache->touchTenant($row->tenant_id);
            $this->audit->record('entitlement.feature_updated', 'tenant_feature_entitlement', $row->id, $before,
                ['state' => $row->state, 'until' => $newUntil], $row->tenant_id);

            return $row;
        }));
    }

    public function setCapacity(Tenant $tenant, string $limitCode, ?int $value, string $source): TenantCapacityLimit
    {
        if (! in_array($limitCode, CapacityService::codes(), true)) {
            throw new DomainException("Unknown capacity code {$limitCode}.", 'UNKNOWN_CAPACITY');
        }

        return DB::transaction(fn () => $this->context->runAs($tenant->id, function () use ($tenant, $limitCode, $value, $source) {
            $row = TenantCapacityLimit::query()->where('limit_code', $limitCode)->first();
            $before = $row ? ['value' => $row->limit_value, 'source' => $row->source] : null;

            if (! $row) {
                $row = new TenantCapacityLimit;
                $row->tenant_id = $tenant->id;
                $row->limit_code = $limitCode;
            }
            $row->limit_value = $value;
            $row->source = $source;
            $row->save();

            $this->cache->touchTenant($tenant->id);
            $this->audit->record('entitlement.capacity_set', 'tenant_capacity_limit', $row->id, $before,
                ['code' => $limitCode, 'value' => $value, 'source' => $source], $tenant->id);

            return $row;
        }));
    }

    private function rank(string $state): int
    {
        return match ($state) {
            'ACTIVE' => 2,
            'READ_ONLY' => 1,
            default => 0,
        };
    }

    /** Every module (transitively) required by $module must be usable on $date at a level at least as strong. */
    private function assertRequirementsMet(Tenant $tenant, Module $module, string $state, string $date): void
    {
        $required = $this->dependencies->requiredClosure($module->id);
        if ($required === []) {
            return;
        }

        $acceptable = $state === 'ACTIVE' ? ['ACTIVE'] : ['ACTIVE', 'READ_ONLY'];
        $satisfied = TenantModuleEntitlement::query()->whereIn('module_id', $required)->whereIn('state', $acceptable)
            ->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date))
            ->pluck('module_id')->all();

        $missing = array_diff($required, $satisfied);
        if ($missing) {
            $codes = Module::query()->whereIn('id', $missing)->pluck('code')->implode(', ');
            throw new DomainException("{$module->code} requires {$codes}.", 'DEPENDENCY_MISSING', 409, ['missing' => explode(', ', $codes)]);
        }
    }

    /**
     * Refuse to weaken a module while a dependent stays usable from $fromDate onward.
     * With $keepsReadOnly (module drops to READ_ONLY) only ACTIVE dependents conflict.
     */
    private function assertNoBlockingDependents(string $tenantId, Module $module, string $fromDate, bool $keepsReadOnly): void
    {
        $dependents = $this->dependencies->directDependents($module->id);
        if ($dependents === []) {
            return;
        }

        $active = TenantModuleEntitlement::query()->whereIn('module_id', $dependents)
            ->whereIn('state', $keepsReadOnly ? ['ACTIVE'] : ['ACTIVE', 'READ_ONLY'])
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $fromDate))
            ->pluck('module_id')->all();

        if ($active) {
            $codes = Module::query()->whereIn('id', $active)->pluck('code')->all();
            throw new DomainException(
                "{$module->code} is required by active module(s): ".implode(', ', $codes).'.',
                'ACTIVE_DEPENDENTS', 409, ['dependents' => $codes],
            );
        }
    }

    private function saveOrOverlap(TenantModuleEntitlement|TenantFeatureEntitlement $row): void
    {
        try {
            $row->save();
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) === '23P01') {
                throw new DomainException('This entitlement window overlaps an existing one.', 'ENTITLEMENT_OVERLAP', 409);
            }
            throw $e;
        }
    }
}
