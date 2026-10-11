<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OA6 batch B: per-connection settings of a source system's adapter (additive). The OptiFleet adapter keeps the references it needs to book
 * on the tenant's behalf here: the cash/bank account that vendor payments credit and the expense category its invoice lines are classified
 * to. They are ids of tenant-owned rows validated when written (the adapter checks them again when it books), never account numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE integration_connections ADD COLUMN settings jsonb NOT NULL DEFAULT '{}'::jsonb");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE integration_connections DROP COLUMN settings');
    }
};
