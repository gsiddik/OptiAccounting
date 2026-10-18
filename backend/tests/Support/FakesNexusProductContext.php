<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Http;

/**
 * `POST /api/v1/authorization/product-context` of OptiNexus (docs/ecosystem/GOVERNANCE_CONTRACTS.md §3) built from the documented
 * JSON. The application is the service account's, the answer lists only products the tenant subscribes to AND the user was assigned,
 * and `allowed=false` carries the logout vocabulary in `deny_reason`. State lives in $this->nexus['pc'].
 */
trait FakesNexusProductContext
{
    protected function nexusProductContextReset(): void
    {
        $this->nexus['pc'] = [
            'products' => [
                ['code' => 'optiaccounting', 'name' => 'OptiEntry', 'launch_url' => self::FRONTEND],
                ['code' => 'optifleet', 'name' => 'OptiFleet', 'launch_url' => 'https://fleet.test/app'],
                ['code' => 'optiradar', 'name' => 'OptiRadar', 'launch_url' => 'https://radar.test/?lang=id'],
            ],
            'deny' => [],   // "{sub}|{tenant}" => deny_reason
            'missing' => false,
            'ttl' => 300,
            'override' => [], // merged over `data` (a malformed or misleading answer)
        ];
    }

    /** @param array<string,mixed> $body */
    protected function nexusProductContext(array $body): mixed
    {
        $pc = $this->nexus['pc'];
        if ($pc['missing']) {
            return Http::response(['success' => false, 'error' => ['code' => 'NOT_FOUND']], 404);
        }
        if (! isset($body['user_id'], $body['tenant_id']) || ! preg_match('/^[0-9a-f-]{36}$/', (string) $body['user_id']) || ! preg_match('/^[0-9a-f-]{36}$/', (string) $body['tenant_id'])) {
            return Http::response(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'The given data was invalid.', 'details' => ['user_id' => ['required']]]], 422);
        }

        $tenant = $this->nexus['tenants'][$body['tenant_id']] ?? null;
        $reason = $pc['deny']["{$body['user_id']}|{$body['tenant_id']}"] ?? (! $tenant ? 'tenant_not_found' : null);
        if ($reason === null && ! in_array('optiaccounting', $tenant['applications'], true)) {
            $reason = 'application_not_assigned';
        }

        $products = $reason === null ? array_map(fn (array $p) => $p + ['current' => $p['code'] === 'optiaccounting'], $pc['products']) : [];

        return Http::response(['success' => true, 'data' => $pc['override'] + [
            'schema_version' => '1.0',
            'user_id' => $body['user_id'],
            'tenant' => $reason === null ? ['id' => $body['tenant_id'], 'code' => 'ACME-ID', 'name' => 'PT Acme Indonesia'] : null,
            'application_code' => 'optiaccounting',
            'allowed' => $reason === null,
            'deny_reason' => $reason,
            'products' => $products,
            'evaluated_at' => now()->toIso8601String(),
            'ttl_seconds' => $pc['ttl'],
        ]]);
    }
}
