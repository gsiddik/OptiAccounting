<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OA3 catalog data for installations that already exist (a fresh database gets all of it from the catalog seeders):
 *  - the new ACCOUNTING_AR features (CUSTOMER, AR_RECEIPT) go to tenants whose bundle already entitled the module, with the same
 *    windows as the module's first feature (operator overrides and other sources are never touched);
 *  - the receivable control role may be used only by the rules of the receivables events, so no rule can move the AR control
 *    account without its subledger knowing (the same protection OA2 gave the payables role);
 *  - the credit note event type and the REVENUE_ADJUSTMENT role (insert-if-missing; a fresh database gets the same rows from the seeder).
 */
return new class extends Migration
{
    private const FEATURES = ['CUSTOMER' => 'Customer', 'AR_RECEIPT' => 'Customer receipt'];

    public function up(): void
    {
        $module = DB::table('modules')->where('code', 'ACCOUNTING_AR')->first();
        if ($module) {
            $anchor = DB::table('features')->where('module_id', $module->id)->whereNotIn('code', array_keys(self::FEATURES))->orderBy('sort_order')->first();
            $order = (int) DB::table('features')->where('module_id', $module->id)->max('sort_order');

            foreach (self::FEATURES as $code => $name) {
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

        $now = now();
        DB::table('account_roles')->insertOrIgnore(['code' => 'REVENUE_ADJUSTMENT', 'name' => 'Pengurang pendapatan (retur dan potongan penjualan)', 'status' => 'ACTIVE', 'sort_order' => 110, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('accounting_event_types')->insertOrIgnore([
            'code' => 'AR_CREDIT_NOTE_RECOGNIZED', 'name' => 'Nota kredit pelanggan diakui', 'description' => 'Komponen: net, tax, total. Diaktifkan oleh OA3.',
            'components' => json_encode(['net', 'tax', 'total']), 'status' => 'ACTIVE', 'sort_order' => 55, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('account_roles')->where('code', 'ACCOUNTS_RECEIVABLE')->update([
            'restricted_events' => json_encode(['AR_INVOICE_RECOGNIZED', 'CUSTOMER_RECEIPT', 'AR_CREDIT_NOTE_RECOGNIZED']),
        ]);
    }

    public function down(): void
    {
        // Entitlement rows are commercial facts; a rollback never deletes them. The role restriction is lifted.
        $now = now();
        DB::table('account_roles')->insertOrIgnore(['code' => 'REVENUE_ADJUSTMENT', 'name' => 'Pengurang pendapatan (retur dan potongan penjualan)', 'status' => 'ACTIVE', 'sort_order' => 110, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('accounting_event_types')->insertOrIgnore([
            'code' => 'AR_CREDIT_NOTE_RECOGNIZED', 'name' => 'Nota kredit pelanggan diakui', 'description' => 'Komponen: net, tax, total. Diaktifkan oleh OA3.',
            'components' => json_encode(['net', 'tax', 'total']), 'status' => 'ACTIVE', 'sort_order' => 55, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('account_roles')->where('code', 'ACCOUNTS_RECEIVABLE')->update(['restricted_events' => null]);
    }
};
