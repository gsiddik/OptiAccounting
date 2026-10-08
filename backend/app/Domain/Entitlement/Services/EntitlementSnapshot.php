<?php

namespace App\Domain\Entitlement\Services;

use App\Domain\AccessControl\Services\AccessCache;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Effective commercial state of one tenant on one business date. Request-time
 * evaluation on the tenant's local date is the single source of truth: a
 * scheduler may later mark rows EXPIRED but can never disagree with this.
 *
 * Modes: NONE (denied) < READ_ONLY (reads only) < FULL.
 */
class EntitlementSnapshot
{
    public const NONE = 'NONE';

    public const READ_ONLY = 'READ_ONLY';

    public const FULL = 'FULL';

    public function __construct(private readonly AccessCache $cache) {}

    /**
     * @return array{date:string,subscription:array{status:?string,mode:string,starts_on:?string,ends_on:?string},modules:array<string,array{state:string,mode:string}>,features:array<string,bool>,capacity:array<string,?int>}
     */
    public function forTenant(Tenant $tenant): array
    {
        $date = $tenant->businessDate();

        return $this->cache->rememberForTenant($tenant->id, "entitlements:{$date}", fn () => $this->build($tenant->id, $date));
    }

    /** Effective mode of a module code: the weaker of subscription and module entitlement. */
    public static function moduleMode(array $snapshot, string $moduleCode): string
    {
        $module = $snapshot['modules'][$moduleCode]['mode'] ?? self::NONE;

        return self::weaker($snapshot['subscription']['mode'], $module);
    }

    public static function weaker(string $a, string $b): string
    {
        $rank = [self::NONE => 0, self::READ_ONLY => 1, self::FULL => 2];

        return $rank[$a] <= $rank[$b] ? $a : $b;
    }

    private function build(string $tenantId, string $date): array
    {
        $subscription = DB::table('subscriptions')->where('tenant_id', $tenantId)
            ->whereIn('status', ['PENDING', 'ACTIVE', 'PAST_DUE', 'SUSPENDED'])->first();

        $subMode = self::NONE;
        if ($subscription && $subscription->starts_on <= $date && ($subscription->ends_on === null || $subscription->ends_on >= $date)) {
            $subMode = match ($subscription->status) {
                'ACTIVE' => self::FULL,
                'PAST_DUE' => self::READ_ONLY,
                default => self::NONE,
            };
        }

        $modules = [];
        $rows = DB::table('tenant_module_entitlements as e')
            ->join('modules as m', 'm.id', '=', 'e.module_id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('e.effective_until')->orWhere('e.effective_until', '>=', $date))
            ->get(['m.code', 'm.status as module_status', 'e.state']);
        foreach ($rows as $row) {
            $mode = $row->module_status !== 'ACTIVE' ? self::NONE : match ($row->state) {
                'ACTIVE' => self::FULL,
                'READ_ONLY' => self::READ_ONLY,
                default => self::NONE,
            };
            $modules[$row->code] = ['state' => $row->state, 'mode' => $mode];
        }

        $features = [];
        $rows = DB::table('tenant_feature_entitlements as e')
            ->join('features as f', 'f.id', '=', 'e.feature_id')
            ->join('modules as m', 'm.id', '=', 'f.module_id')
            ->where('e.tenant_id', $tenantId)
            ->where('e.effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('e.effective_until')->orWhere('e.effective_until', '>=', $date))
            ->get(['f.code', 'f.status as feature_status', 'm.code as module_code', 'e.state']);
        foreach ($rows as $row) {
            $features[$row->code] = [
                'enabled' => $row->state === 'ACTIVE' && $row->feature_status === 'ACTIVE',
                'module' => $row->module_code,
            ];
        }

        $capacity = DB::table('tenant_capacity_limits')->where('tenant_id', $tenantId)
            ->pluck('limit_value', 'limit_code')->map(fn ($v) => $v === null ? null : (int) $v)->all();

        return [
            'date' => $date,
            'subscription' => [
                'status' => $subscription->status ?? null,
                'mode' => $subMode,
                'starts_on' => $subscription->starts_on ?? null,
                'ends_on' => $subscription->ends_on ?? null,
            ],
            'modules' => $modules,
            'features' => $features,
            'capacity' => $capacity,
        ];
    }
}
