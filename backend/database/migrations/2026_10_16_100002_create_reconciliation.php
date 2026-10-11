<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA5 batch F: the central reconciliation framework. A reconciliation compares a subledger / control source with the general ledger at
 * one cut-off date (never two different dates) and always RECOMPUTES the difference from authoritative data. Two tables hold the human
 * side of it:
 *
 *   reconciliation_runs        append-only evidence of an explicit "run" (who ran what, as of when, and what came out);
 *   reconciliation_exceptions  the open work item for a mismatch. Its numbers are refreshed by every run; an explanation or an owner
 *                              never changes the calculated status, and RESOLVED needs a recomputed difference of zero.
 *
 * Statuses are accounting reconciliation statuses (MATCHED, MISMATCH, INCOMPLETE, NOT_APPLICABLE), deliberately distinct from any
 * future integration (OA6) reconciliation vocabulary.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('reconciliation_type', 20);
            $table->date('as_of_date');
            $table->uuid('accounting_period_id')->nullable();
            $table->string('status', 14);
            $table->decimal('source_balance', 20, 4)->nullable();
            $table->decimal('gl_balance', 20, 4)->nullable();
            $table->decimal('difference', 20, 4)->nullable();
            $table->decimal('tolerance', 20, 4)->default(0);
            $table->boolean('scope_complete')->default(true);
            $table->string('purpose', 16)->default('MANUAL');
            $table->jsonb('components')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->timestampTz('run_at');
            $table->timestampTz('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'accounting_period_id'])->references(['tenant_id', 'id'])->on('accounting_periods')->restrictOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'reconciliation_type', 'as_of_date']);
        });
        $types = "'AP_GL','AR_GL','CASH_BANK_GL','FIXED_ASSET_GL','TAX_GL','FX_GL'";
        DB::statement("ALTER TABLE reconciliation_runs ADD CONSTRAINT reconciliation_runs_type_check CHECK (reconciliation_type IN ({$types}))");
        DB::statement("ALTER TABLE reconciliation_runs ADD CONSTRAINT reconciliation_runs_status_check CHECK (status IN ('MATCHED','MISMATCH','INCOMPLETE','NOT_APPLICABLE'))");
        DB::statement("ALTER TABLE reconciliation_runs ADD CONSTRAINT reconciliation_runs_purpose_check CHECK (purpose IN ('MANUAL','CLOSE_CHECK'))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION oa5_append_only() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION '% is append-only: rows are never changed or removed', TG_TABLE_NAME USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER reconciliation_runs_append_only BEFORE UPDATE OR DELETE ON reconciliation_runs FOR EACH ROW EXECUTE FUNCTION oa5_append_only()');

        Schema::create('reconciliation_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('reconciliation_type', 20);
            $table->date('as_of_date');
            $table->uuid('accounting_period_id')->nullable();
            $table->decimal('source_balance', 20, 4)->nullable();
            $table->decimal('gl_balance', 20, 4)->nullable();
            $table->decimal('difference', 20, 4)->nullable();
            $table->string('calculated_status', 14);        // as of the latest run; never set by a person
            $table->string('status', 10)->default('OPEN');  // the work item: OPEN > EXPLAINED > RESOLVED
            $table->uuid('owner_id')->nullable();
            $table->string('notes', 1000)->nullable();
            $table->uuid('explained_by')->nullable();
            $table->timestampTz('explained_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->string('resolution_note', 1000)->nullable();
            $table->uuid('last_run_id')->nullable();
            $table->uuid('opened_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'accounting_period_id'])->references(['tenant_id', 'id'])->on('accounting_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'last_run_id'])->references(['tenant_id', 'id'])->on('reconciliation_runs')->restrictOnDelete();
            foreach (['owner_id', 'explained_by', 'resolved_by', 'opened_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->restrictOnDelete();
            }
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'reconciliation_type']);
        });
        // One live work item per type and cut-off; a resolved one stays as history and a new mismatch opens a new item.
        DB::statement("CREATE UNIQUE INDEX reconciliation_exceptions_live_unique ON reconciliation_exceptions (tenant_id, reconciliation_type, as_of_date) WHERE status <> 'RESOLVED'");
        DB::statement("ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_type_check CHECK (reconciliation_type IN ({$types}))");
        DB::statement("ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_calc_check CHECK (calculated_status IN ('MATCHED','MISMATCH','INCOMPLETE','NOT_APPLICABLE'))");
        DB::statement("ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_status_check CHECK (status IN ('OPEN','EXPLAINED','RESOLVED'))");
        DB::statement("ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_resolved_check CHECK (status <> 'RESOLVED' OR resolved_at IS NOT NULL)");
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_exceptions');
        Schema::dropIfExists('reconciliation_runs');
        DB::unprepared('DROP FUNCTION IF EXISTS oa5_append_only()');
    }
};
