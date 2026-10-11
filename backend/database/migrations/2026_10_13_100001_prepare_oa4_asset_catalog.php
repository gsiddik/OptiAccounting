<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OA4 catalog data for installations that already exist (a fresh database gets the same rows from AccountingCatalogSeeder; every insert
 * is insert-if-missing, so operator edits survive): the account roles and accounting event types of the fixed asset module, and the
 * rule that a fixed asset control role may be used only by the rules of the asset events, so no ordinary rule can move the register's
 * control accounts without the register knowing about it (the protection OA2 gave the payables role).
 */
return new class extends Migration
{
    private const ROLES = [
        ['FIXED_ASSET', 'Aset tetap (harga perolehan)', 120, ['ASSET_CAPITALIZED', 'ASSET_DISPOSED']],
        ['ACCUMULATED_DEPRECIATION', 'Akumulasi penyusutan', 130, ['DEPRECIATION_RECOGNIZED', 'ASSET_DISPOSED']],
        ['DEPRECIATION_EXPENSE', 'Beban penyusutan', 140, null],
        ['ASSET_DISPOSAL_GAIN_LOSS', 'Laba (rugi) pelepasan aset', 150, ['ASSET_DISPOSED']],
    ];

    private const EVENTS = [
        ['ASSET_CAPITALIZED', 'Aset tetap dikapitalisasi', 'Komponen: cost. Diaktifkan oleh OA4.', ['cost'], 120],
        ['DEPRECIATION_RECOGNIZED', 'Penyusutan diakui', 'Komponen: amount. Diaktifkan oleh OA4.', ['amount'], 130],
        ['ASSET_DISPOSED', 'Aset tetap dilepas', 'Komponen: cost, accumulated, proceeds, gain, loss. Diaktifkan oleh OA4.', ['cost', 'accumulated', 'proceeds', 'gain', 'loss'], 140],
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::ROLES as [$code, $name, $order, $restricted]) {
            DB::table('account_roles')->insertOrIgnore(['code' => $code, 'name' => $name, 'status' => 'ACTIVE', 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
            if ($restricted !== null) {
                DB::table('account_roles')->where('code', $code)->whereNull('restricted_events')->update(['restricted_events' => json_encode($restricted)]);
            }
        }
        foreach (self::EVENTS as [$code, $name, $description, $components, $order]) {
            DB::table('accounting_event_types')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'description' => $description, 'components' => json_encode($components),
                'status' => 'ACTIVE', 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Catalog rows may already be referenced by posting rules, mappings and posted events; a rollback never deletes them.
        // The role restriction is lifted so the rows behave like any other role again.
        DB::table('account_roles')->whereIn('code', array_column(self::ROLES, 0))->update(['restricted_events' => null]);
    }
};
