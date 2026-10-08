<?php

namespace App\Domain\Entitlement\Services;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Entitlement\Models\Subscription;
use App\Domain\Entitlement\Models\TenantFeatureEntitlement;
use App\Domain\Entitlement\Models\TenantModuleEntitlement;
use App\Domain\Identity\Models\Tenant;
use App\Domain\ProductCatalog\Models\Bundle;
use App\Domain\ProductCatalog\Services\ModuleDependencyService;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Tenant subscription foundation. A subscription can provision entitlements from a
 * bundle (source BUNDLE) for its own window; ending it ends those windows but keeps the rows.
 * No pricing/billing here: that is a later commercial expansion (or OptiNexus in SaaS mode).
 */
class SubscriptionService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly AccessCache $cache,
        private readonly EntitlementService $entitlements,
        private readonly ModuleDependencyService $dependencies,
        private readonly TenantContext $context,
    ) {}

    public function create(Tenant $tenant, ?Bundle $bundle, string $startsOn, ?string $endsOn, string $status = Subscription::PENDING, ?string $notes = null): Subscription
    {
        if (! in_array($status, [Subscription::PENDING, Subscription::ACTIVE], true)) {
            throw new DomainException('A subscription starts as PENDING or ACTIVE.', 'INVALID_STATUS');
        }
        if ($endsOn !== null && $endsOn < $startsOn) {
            throw new DomainException('ends_on cannot be before starts_on.', 'INVALID_WINDOW');
        }
        if ($bundle && $bundle->status !== 'ACTIVE') {
            throw new DomainException('This bundle is not active.', 'BUNDLE_INACTIVE');
        }

        return DB::transaction(fn () => $this->context->runAs($tenant->id, function () use ($tenant, $bundle, $startsOn, $endsOn, $status, $notes) {
            $subscription = new Subscription(['starts_on' => $startsOn, 'ends_on' => $endsOn, 'notes' => $notes]);
            $subscription->tenant_id = $tenant->id;
            $subscription->bundle_id = $bundle?->id;
            $subscription->status = $status;
            $subscription->source = 'LOCAL';

            try {
                $subscription->save();
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23505') {
                    throw new DomainException('The tenant already has a live subscription.', 'SUBSCRIPTION_EXISTS', 409);
                }
                throw $e;
            }

            if ($bundle) {
                $this->provision($tenant, $subscription, $bundle);
            }

            $this->cache->touchTenant($tenant->id);
            $this->audit->record('subscription.created', 'subscription', $subscription->id, null,
                ['bundle' => $bundle?->code, 'status' => $status, 'starts_on' => $startsOn, 'ends_on' => $endsOn], $tenant->id);

            return $subscription;
        }));
    }

    public function transition(Subscription $subscription, string $to, ?string $reason = null): Subscription
    {
        return DB::transaction(fn () => $this->context->runAs($subscription->tenant_id, function () use ($subscription, $to, $reason) {
            $subscription = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $from = $subscription->status;

            if (! in_array($to, Subscription::TRANSITIONS[$from] ?? [], true)) {
                throw new DomainException("A {$from} subscription cannot become {$to}.", 'INVALID_TRANSITION');
            }

            $subscription->status = $to;
            $subscription->save();

            if (in_array($to, [Subscription::EXPIRED, Subscription::CANCELLED], true)) {
                $this->endProvisionedEntitlements($subscription);
            }

            $this->cache->touchTenant($subscription->tenant_id);
            $this->audit->record('subscription.status_changed', 'subscription', $subscription->id,
                ['status' => $from], ['status' => $to, 'reason' => $reason], $subscription->tenant_id);

            return $subscription;
        }));
    }

    /** Change the subscription window; bundle-provisioned entitlements follow it. */
    public function reschedule(Subscription $subscription, string $startsOn, ?string $endsOn): Subscription
    {
        if ($endsOn !== null && $endsOn < $startsOn) {
            throw new DomainException('ends_on cannot be before starts_on.', 'INVALID_WINDOW');
        }

        return DB::transaction(fn () => $this->context->runAs($subscription->tenant_id, function () use ($subscription, $startsOn, $endsOn) {
            if (! in_array($subscription->status, Subscription::LIVE, true)) {
                throw new DomainException('Only a live subscription can be rescheduled.', 'INVALID_STATUS');
            }

            $before = ['starts_on' => $subscription->starts_on->toDateString(), 'ends_on' => $subscription->ends_on?->toDateString()];
            $subscription->starts_on = $startsOn;
            $subscription->ends_on = $endsOn;
            $subscription->save();

            TenantModuleEntitlement::query()->where('subscription_id', $subscription->id)
                ->update(['effective_from' => $startsOn, 'effective_until' => $endsOn]);

            $this->cache->touchTenant($subscription->tenant_id);
            $this->audit->record('subscription.rescheduled', 'subscription', $subscription->id, $before,
                ['starts_on' => $startsOn, 'ends_on' => $endsOn], $subscription->tenant_id);

            return $subscription;
        }));
    }

    private function provision(Tenant $tenant, Subscription $subscription, Bundle $bundle): void
    {
        $modules = $bundle->modules()->with('features')->get()->keyBy('id');
        $from = $subscription->starts_on->toDateString();
        $until = $subscription->ends_on?->toDateString();

        foreach ($this->dependencies->orderByDependencies($modules->keys()->all()) as $id) {
            $module = $modules[$id];
            $this->entitlements->grantModule($tenant, $module, 'ACTIVE', 'BUNDLE', $from, $until, $subscription->id);
            foreach ($module->features->where('status', 'ACTIVE') as $feature) {
                $this->entitlements->grantFeature($tenant, $feature, 'ACTIVE', 'BUNDLE', $from, $until);
            }
        }

        foreach ($bundle->capacities as $capacity) {
            $this->entitlements->setCapacity($tenant, $capacity->limit_code, $capacity->limit_value, 'BUNDLE');
        }
    }

    /** Close the windows this subscription provisioned (history stays); a window that has not begun is disabled. */
    private function endProvisionedEntitlements(Subscription $subscription): void
    {
        $tenant = Tenant::query()->findOrFail($subscription->tenant_id);
        $today = $tenant->businessDate();

        $modules = TenantModuleEntitlement::query()->where('subscription_id', $subscription->id)->get();
        $moduleIds = $modules->pluck('module_id')->all();

        foreach ($modules as $row) {
            $this->endWindow($row, $today);
        }

        if ($moduleIds !== []) {
            $features = TenantFeatureEntitlement::query()->where('source', 'BUNDLE')
                ->whereHas('feature', fn ($q) => $q->whereIn('module_id', $moduleIds))->get();
            foreach ($features as $row) {
                $this->endWindow($row, $today);
            }
        }
    }

    private function endWindow(TenantModuleEntitlement|TenantFeatureEntitlement $row, string $today): void
    {
        if ($row->effective_until !== null && $row->effective_until->toDateString() < $today) {
            return;
        }

        if ($row->effective_from->toDateString() >= $today) {
            $row->state = 'DISABLED';
            $row->effective_until = $row->effective_from;
        } else {
            $row->effective_until = Carbon::parse($today)->subDay()->toDateString();
        }
        $row->save();
    }
}
