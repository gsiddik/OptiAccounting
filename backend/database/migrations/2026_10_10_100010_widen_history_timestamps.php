<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit and transition history were stored with one-second resolution, so two events of the same document inside one second
 * (submit and approve through the API, or a seeded workflow) had no defined order when read back by `occurred_at`. The column
 * keeps its meaning and its values; only the stored precision grows to microseconds, which PostgreSQL applies without
 * rewriting the table. Rows written before this migration keep their whole-second values.
 */
return new class extends Migration
{
    private const TABLES = ['audit_logs', 'journal_transitions'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN occurred_at TYPE timestamp(6) with time zone");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ALTER COLUMN occurred_at TYPE timestamp(0) with time zone");
        }
    }
};
