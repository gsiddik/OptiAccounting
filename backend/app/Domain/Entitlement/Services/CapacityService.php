<?php

namespace App\Domain\Entitlement\Services;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Models\TenantUser;
use Illuminate\Support\Facades\DB;

/**
 * Server-authoritative capacity checks. Limits are generic `limit_code => value`
 * rows (null = unlimited); a new limit type only needs a meter here. Creation
 * paths call reserve() inside their own transaction: it locks the tenant row, so
 * two concurrent creations near a limit are serialized and cannot both pass.
 */
class CapacityService
{
    public const USER_LIMIT = 'USER_LIMIT';

    public const BRANCH_LIMIT = 'BRANCH_LIMIT';

    public const BUSINESS_UNIT_LIMIT = 'BUSINESS_UNIT_LIMIT';

    /** @return list<string> */
    public static function codes(): array
    {
        return [self::USER_LIMIT, self::BRANCH_LIMIT, self::BUSINESS_UNIT_LIMIT];
    }

    /** Throws CapacityException when adding $adding more would exceed the limit. Call inside a transaction. */
    public function reserve(string $tenantId, string $limitCode, int $adding = 1): void
    {
        DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->value('id');

        $limit = $this->limit($tenantId, $limitCode);
        if ($limit === null) {
            return;
        }

        $used = $this->used($tenantId, $limitCode);
        if ($used + $adding > $limit) {
            throw new CapacityException($limitCode, $limit, $used);
        }
    }

    /** null = unlimited (also when no limit row exists). */
    public function limit(string $tenantId, string $limitCode): ?int
    {
        $value = DB::table('tenant_capacity_limits')->where('tenant_id', $tenantId)->where('limit_code', $limitCode)->value('limit_value');

        return $value === null ? null : (int) $value;
    }

    public function used(string $tenantId, string $limitCode): int
    {
        return match ($limitCode) {
            self::USER_LIMIT => DB::table('tenant_users')->where('tenant_id', $tenantId)
                ->where('status', '<>', TenantUser::INACTIVE)->count(),
            self::BRANCH_LIMIT => DB::table('branches')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->count(),
            self::BUSINESS_UNIT_LIMIT => DB::table('business_units')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->count(),
            default => 0,
        };
    }

    /** @return list<array{code:string,limit:?int,used:int,remaining:?int}> */
    public function usage(Tenant|string $tenant): array
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;
        $codes = array_values(array_unique([
            ...self::codes(),
            ...DB::table('tenant_capacity_limits')->where('tenant_id', $tenantId)->pluck('limit_code')->all(),
        ]));

        return array_map(function (string $code) use ($tenantId) {
            $limit = $this->limit($tenantId, $code);
            $used = $this->used($tenantId, $code);

            return ['code' => $code, 'limit' => $limit, 'used' => $used, 'remaining' => $limit === null ? null : max(0, $limit - $used)];
        }, $codes);
    }
}
