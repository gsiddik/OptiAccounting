<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA5 batches I-K: closing policy, closing checklist, the append-only closing history, year-end closing and the controlled reopen.
 *
 *   closing_settings / closing_policy_rules   data-driven policy (what blocks a close, tolerance, reopen limits); code defaults apply when no row exists
 *   closing_checklist_templates / _items      manual checklist items per period (system checks are computed live and never stored as PASS)
 *   closing_events                            append-only history: pre-close runs, soft close, hard close, reopen, year-end run / reverse
 *   year_end_closings                         one POSTED year-end closing per fiscal year (partial unique index); the journal itself is the financial fact
 *
 * The OA1 trigger that made CLOSED terminal is replaced (additively) by one that lets a period leave CLOSED only inside a transaction that
 * also wrote a matching REOPEN row in closing_events: a raw UPDATE, or a code path that forgot the history, is still refused by the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('closing_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->decimal('reconciliation_tolerance', 20, 4)->default(0);  // strict by default; never used for the trial balance
            $table->unsignedSmallInteger('reopen_max_periods_back')->nullable(); // null = no limit; counted from the latest closed period
            $table->boolean('reopen_requires_later_open')->default(true);       // later periods must not be CLOSED when an earlier one is reopened
            $table->boolean('separate_closer_and_reopener')->default(false);    // segregation of duties: who hard-closed a period may not reopen it
            $table->boolean('require_soft_close_first')->default(false);
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique('tenant_id');
        });
        DB::statement('ALTER TABLE closing_settings ADD CONSTRAINT closing_settings_tolerance_check CHECK (reconciliation_tolerance >= 0)');

        Schema::create('closing_policy_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('check_code', 40);
            $table->string('severity', 5);
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'check_code']);
        });
        DB::statement("ALTER TABLE closing_policy_rules ADD CONSTRAINT closing_policy_rules_severity_check CHECK (severity IN ('BLOCK','WARN','OFF'))");

        Schema::create('closing_checklist_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 40);
            $table->string('title', 200);
            $table->string('description', 500)->nullable();
            $table->boolean('required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->string('status', 8)->default('ACTIVE');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE closing_checklist_templates ADD CONSTRAINT closing_checklist_templates_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");

        Schema::create('closing_checklist_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('accounting_period_id');
            $table->uuid('template_id')->nullable();
            $table->string('code', 40);
            $table->string('title', 200);
            $table->boolean('required')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(100);
            $table->string('status', 9)->default('PENDING');
            $table->string('notes', 1000)->nullable();
            $table->uuid('completed_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('waived_by')->nullable();
            $table->timestampTz('waived_at')->nullable();
            $table->string('waive_reason', 500)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'accounting_period_id'])->references(['tenant_id', 'id'])->on('accounting_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'template_id'])->references(['tenant_id', 'id'])->on('closing_checklist_templates')->restrictOnDelete();
            $table->foreign('completed_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('waived_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'accounting_period_id', 'code']);
        });
        DB::statement("ALTER TABLE closing_checklist_items ADD CONSTRAINT closing_checklist_items_status_check CHECK (status IN ('PENDING','COMPLETED','WAIVED'))");
        DB::statement("ALTER TABLE closing_checklist_items ADD CONSTRAINT closing_checklist_items_waive_check CHECK (status <> 'WAIVED' OR (waive_reason IS NOT NULL AND waived_by IS NOT NULL))");
        DB::statement("ALTER TABLE closing_checklist_items ADD CONSTRAINT closing_checklist_items_done_check CHECK (status <> 'COMPLETED' OR (completed_by IS NOT NULL AND completed_at IS NOT NULL))");

        Schema::create('closing_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('accounting_period_id')->nullable();
            $table->uuid('fiscal_year_id')->nullable();
            $table->string('event_type', 16);
            $table->string('from_status', 12)->nullable();
            $table->string('to_status', 12)->nullable();
            $table->string('readiness', 9)->nullable();
            $table->jsonb('readiness_checks')->nullable();  // compact snapshot of the evaluated checks, as evidence of what the actor saw
            $table->string('reason', 500)->nullable();
            $table->string('reference', 100)->nullable();
            $table->uuid('actor_id')->nullable();
            $table->uuid('journal_entry_id')->nullable();   // the closing journal of a year-end run / reversal
            $table->bigInteger('txid')->default(DB::raw('txid_current()'));
            $table->timestampTz('created_at');

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'accounting_period_id'])->references(['tenant_id', 'id'])->on('accounting_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'fiscal_year_id'])->references(['tenant_id', 'id'])->on('fiscal_years')->restrictOnDelete();
            $table->foreign(['tenant_id', 'journal_entry_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'accounting_period_id', 'created_at']);
            $table->index(['tenant_id', 'fiscal_year_id', 'created_at']);
        });
        DB::statement("ALTER TABLE closing_events ADD CONSTRAINT closing_events_type_check CHECK (event_type IN ('PRE_CLOSE','SOFT_CLOSE','HARD_CLOSE','REOPEN','YEAR_END_RUN','YEAR_END_REVERSE'))");
        DB::statement("ALTER TABLE closing_events ADD CONSTRAINT closing_events_readiness_check CHECK (readiness IS NULL OR readiness IN ('READY','NOT_READY'))");
        DB::statement("ALTER TABLE closing_events ADD CONSTRAINT closing_events_reopen_reason CHECK (event_type NOT IN ('REOPEN','YEAR_END_REVERSE') OR reason IS NOT NULL)");
        DB::unprepared('CREATE TRIGGER closing_events_append_only BEFORE UPDATE OR DELETE ON closing_events FOR EACH ROW EXECUTE FUNCTION oa5_append_only()');

        Schema::create('year_end_closings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('fiscal_year_id');
            $table->unsignedSmallInteger('run_no');
            $table->string('status', 8)->default('POSTED');
            $table->date('closing_date');
            $table->uuid('journal_entry_id');
            $table->uuid('retained_earnings_account_id');
            $table->unsignedInteger('closed_account_count')->default(0);
            $table->uuid('reversal_journal_id')->nullable();
            $table->uuid('run_by')->nullable();
            $table->timestampTz('run_at');
            $table->uuid('reversed_by')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->string('reverse_reason', 500)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'fiscal_year_id'])->references(['tenant_id', 'id'])->on('fiscal_years')->restrictOnDelete();
            $table->foreign(['tenant_id', 'journal_entry_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reversal_journal_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'retained_earnings_account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign('run_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reversed_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'fiscal_year_id', 'run_no']);
            $table->unique(['tenant_id', 'journal_entry_id']);
        });
        // Duplicate year-end closing is impossible: one POSTED run per fiscal year. A correction reverses it (status REVERSED) and runs again.
        DB::statement("CREATE UNIQUE INDEX year_end_closings_one_posted ON year_end_closings (tenant_id, fiscal_year_id) WHERE status = 'POSTED'");
        DB::statement("ALTER TABLE year_end_closings ADD CONSTRAINT year_end_closings_status_check CHECK (status IN ('POSTED','REVERSED'))");
        DB::statement("ALTER TABLE year_end_closings ADD CONSTRAINT year_end_closings_reversed_check CHECK ((status = 'REVERSED') = (reversal_journal_id IS NOT NULL AND reversed_at IS NOT NULL AND reverse_reason IS NOT NULL))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION year_end_closings_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'a year-end closing is never deleted' USING ERRCODE = '23514';
                END IF;
                IF NEW.tenant_id <> OLD.tenant_id OR NEW.fiscal_year_id <> OLD.fiscal_year_id OR NEW.run_no <> OLD.run_no OR NEW.journal_entry_id <> OLD.journal_entry_id
                   OR NEW.closing_date <> OLD.closing_date OR NEW.retained_earnings_account_id <> OLD.retained_earnings_account_id THEN
                    RAISE EXCEPTION 'a year-end closing run cannot change' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'REVERSED' THEN
                    RAISE EXCEPTION 'a reversed year-end closing is final' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER year_end_closings_guard BEFORE UPDATE OR DELETE ON year_end_closings FOR EACH ROW EXECUTE FUNCTION year_end_closings_guard()');

        // OA1 made CLOSED terminal. The controlled reopen needs one door: a period may leave CLOSED only when this very transaction wrote the REOPEN history row.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION accounting_periods_guard() RETURNS trigger AS $$
            DECLARE fy fiscal_years%ROWTYPE;
            BEGIN
                SELECT * INTO fy FROM fiscal_years WHERE tenant_id = NEW.tenant_id AND id = NEW.fiscal_year_id;
                IF NEW.start_date < fy.start_date OR NEW.end_date > fy.end_date THEN
                    RAISE EXCEPTION 'accounting period % lies outside its fiscal year', NEW.code USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.status = 'CLOSED' THEN
                    IF NEW.start_date <> OLD.start_date OR NEW.end_date <> OLD.end_date OR NEW.fiscal_year_id <> OLD.fiscal_year_id THEN
                        RAISE EXCEPTION 'a closed accounting period cannot change its dates' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.status <> 'CLOSED' AND (NEW.status NOT IN ('SOFT_CLOSED', 'OPEN') OR NOT EXISTS (
                        SELECT 1 FROM closing_events e
                        WHERE e.tenant_id = NEW.tenant_id AND e.accounting_period_id = NEW.id AND e.event_type = 'REOPEN'
                          AND e.from_status = 'CLOSED' AND e.to_status = NEW.status AND e.txid = txid_current())) THEN
                        RAISE EXCEPTION 'a closed accounting period cannot change: reopen it through the controlled reopen' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        // Catalog row for the year-end closing event (a fresh database gets the same row from AccountingCatalogSeeder; insert-if-missing).
        DB::table('accounting_event_types')->insertOrIgnore([
            'code' => 'YEAR_END_CLOSING', 'name' => 'Penutupan akhir tahun',
            'description' => 'Komponen: debit_close, credit_close, profit, loss. Diaktifkan oleh OA5; baris pada akun sementara disebut oleh dokumen (distribusi).',
            'components' => json_encode(['debit_close', 'credit_close', 'profit', 'loss']), 'status' => 'ACTIVE', 'sort_order' => 160,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('year_end_closings');
        Schema::dropIfExists('closing_events');
        Schema::dropIfExists('closing_checklist_items');
        Schema::dropIfExists('closing_checklist_templates');
        Schema::dropIfExists('closing_policy_rules');
        Schema::dropIfExists('closing_settings');
        DB::unprepared('DROP FUNCTION IF EXISTS year_end_closings_guard()');
        // Restore the OA1 guard: CLOSED is terminal again.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION accounting_periods_guard() RETURNS trigger AS $$
            DECLARE fy fiscal_years%ROWTYPE;
            BEGIN
                SELECT * INTO fy FROM fiscal_years WHERE tenant_id = NEW.tenant_id AND id = NEW.fiscal_year_id;
                IF NEW.start_date < fy.start_date OR NEW.end_date > fy.end_date THEN
                    RAISE EXCEPTION 'accounting period % lies outside its fiscal year', NEW.code USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' THEN
                    IF OLD.status = 'CLOSED' AND (NEW.status <> 'CLOSED' OR NEW.start_date <> OLD.start_date OR NEW.end_date <> OLD.end_date) THEN
                        RAISE EXCEPTION 'a closed accounting period cannot change' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }
};
