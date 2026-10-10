<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OA3 batch G: a cash or bank account that a customer receipt uses is "in use" like one that vendor payments, expenses, cash
 * transactions or statements use: its GL account, currency and kind cannot change. The guard function is replaced with the
 * longer list of tables (the trigger that calls it is unchanged).
 */
return new class extends Migration
{
    private const BEFORE = ['vendor_payments', 'expenses', 'cash_transactions', 'bank_statements'];

    public function up(): void
    {
        $this->define([...self::BEFORE, 'customer_receipts']);
    }

    public function down(): void
    {
        $this->define(self::BEFORE);
    }

    /** @param list<string> $tables */
    private function define(array $tables): void
    {
        $list = implode(', ', array_map(fn (string $t) => "'{$t}'", $tables));
        DB::unprepared(<<<SQL
            CREATE OR REPLACE FUNCTION cash_bank_account_in_use(p_tenant uuid, p_id uuid) RETURNS boolean AS \$\$
            DECLARE t text; used boolean;
            BEGIN
                FOREACH t IN ARRAY ARRAY[{$list}] LOOP
                    IF to_regclass(t) IS NOT NULL THEN
                        EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I WHERE tenant_id = \$1 AND cash_bank_account_id = \$2)', t) INTO used USING p_tenant, p_id;
                        IF used THEN RETURN true; END IF;
                    END IF;
                END LOOP;
                RETURN false;
            END; \$\$ LANGUAGE plpgsql
            SQL);
    }
};
