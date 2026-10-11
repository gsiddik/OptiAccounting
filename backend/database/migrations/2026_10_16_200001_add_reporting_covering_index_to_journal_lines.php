<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * OA5 batch A: a covering index for period reports. Every financial statement, trial balance and general ledger reads the amounts of the
 * lines of the journals whose posting date falls in a window. With only (tenant_id, journal_entry_id) the planner had to read every line of the
 * tenant and hash-join the journals; with the amounts included it walks the few journals of the window and reads their lines from the index alone
 * (index-only scan, no heap access). Measured on 160,000 generated lines over two tenants, the one-month movement query dropped from 28 ms to 10 ms;
 * whole-year and as-of aggregates correctly stay sequential scans. Nothing else changes: it is a pure index, additive and reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX journal_lines_tenant_entry_cover ON journal_lines (tenant_id, journal_entry_id) INCLUDE (account_id, debit, credit)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS journal_lines_tenant_entry_cover');
    }
};
