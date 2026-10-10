<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OA2 adds features to modules that already exist (VENDOR and AP_PAYMENT to ACCOUNTING_AP, CASH_BANK_ACCOUNT to ACCOUNTING_CASH_BANK).
 * Tenants whose bundle already entitled the module must keep the whole module: the new feature gets the same bundle-sourced windows
 * as the module's first feature. On a fresh database the modules do not exist yet (the catalog seeder creates them, with the features),
 * so nothing happens here. Operator-managed overrides and other sources are never touched.
 */
return new class extends Migration
{
    private const FEATURES = [
        'ACCOUNTING_AP' => ['VENDOR' => 'Vendor', 'AP_PAYMENT' => 'Vendor payment'],
        'ACCOUNTING_CASH_BANK' => ['CASH_BANK_ACCOUNT' => 'Cash and bank account'],
    ];

    public function up(): void
    {
        foreach (self::FEATURES as $moduleCode => $features) {
            $module = DB::table('modules')->where('code', $moduleCode)->first();
            if (! $module) {
                continue;
            }

            $anchor = DB::table('features')->where('module_id', $module->id)->whereNotIn('code', array_keys($features))->orderBy('sort_order')->first();
            $order = (int) DB::table('features')->where('module_id', $module->id)->max('sort_order');

            foreach ($features as $code => $name) {
                $feature = DB::table('features')->where('code', $code)->first();
                if (! $feature) {
                    $order += 10;
                    $id = (string) Str::uuid7();
                    DB::table('features')->insert(['id' => $id, 'module_id' => $module->id, 'code' => $code, 'name' => $name, 'status' => 'ACTIVE', 'sort_order' => $order, 'created_at' => now(), 'updated_at' => now()]);
                    $feature = (object) ['id' => $id];
                }
                if (! $anchor) {
                    continue;
                }

                $rows = DB::table('tenant_feature_entitlements as e')->where('e.feature_id', $anchor->id)->where('e.source', 'BUNDLE')
                    ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('tenant_feature_entitlements as n')->whereColumn('n.tenant_id', 'e.tenant_id')->where('n.feature_id', $feature->id))
                    ->get(['tenant_id', 'state', 'effective_from', 'effective_until']);
                foreach ($rows as $row) {
                    DB::table('tenant_feature_entitlements')->insert([
                        'id' => (string) Str::uuid7(), 'tenant_id' => $row->tenant_id, 'feature_id' => $feature->id, 'state' => $row->state, 'source' => 'BUNDLE',
                        'effective_from' => $row->effective_from, 'effective_until' => $row->effective_until, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        // Entitlement rows are commercial facts; a rollback never deletes them.
    }
};
