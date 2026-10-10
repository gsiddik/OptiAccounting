<?php

use App\Support\Database\DocumentGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA4 batch D: the depreciation run. One run is one source document for one accounting period: it holds the depreciation of every
 * eligible asset up to that period (a line per asset, the schedule rows it covers claimed by the line), is reviewed as a draft and posts
 * one journal through the Posting Engine. Posting is atomic with the schedule state. The database repeats the invariants: a posted run
 * has the posted journal of exactly its total and date, a run posts only the lines it holds, and the posted run and its lines are
 * immutable (reversal is the only correction).
 */
return new class extends Migration
{
    private const REVERSAL = ['reversal_journal_id', 'reversed_by', 'reversed_at', 'reversal_reason', 'reversal_posting_date'];

    public function up(): void
    {
        Schema::create('asset_depreciation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('document_number', 40)->nullable(); // issued at posting
            $table->uuid('accounting_period_id');
            $table->date('posting_date');
            $table->string('description', 500)->nullable();
            $table->string('reference', 100)->nullable();
            $table->decimal('total_amount', 20, 4)->default(0);
            $table->unsignedInteger('asset_count')->default(0);
            $table->string('status', 20)->default('DRAFT');
            $table->uuid('created_by')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('accounting_event_id')->nullable();
            $table->uuid('reversal_journal_id')->nullable();
            $table->uuid('reversed_by')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->date('reversal_posting_date')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'accounting_period_id'])->references(['tenant_id', 'id'])->on('accounting_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'journal_entry_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reversal_journal_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign('accounting_event_id')->references('id')->on('accounting_events')->restrictOnDelete();
            foreach (['created_by', 'cancelled_by', 'posted_by', 'reversed_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->restrictOnDelete();
            }
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'posting_date']);
            $table->index(['tenant_id', 'accounting_period_id']);
        });
        DB::statement("ALTER TABLE asset_depreciation_runs ADD CONSTRAINT asset_depreciation_runs_status_check CHECK (status IN ('DRAFT','POSTED','CANCELLED','REVERSED'))");
        DB::statement('ALTER TABLE asset_depreciation_runs ADD CONSTRAINT asset_depreciation_runs_total_check CHECK (total_amount >= 0 AND (asset_count > 0) = (total_amount > 0))');
        DB::statement("ALTER TABLE asset_depreciation_runs ADD CONSTRAINT asset_depreciation_runs_posted_check CHECK (status NOT IN ('POSTED','REVERSED') OR (
            document_number IS NOT NULL AND posted_at IS NOT NULL AND journal_entry_id IS NOT NULL AND total_amount > 0))");
        DB::statement("ALTER TABLE asset_depreciation_runs ADD CONSTRAINT asset_depreciation_runs_reversed_check CHECK (
            (status = 'REVERSED') = (reversal_journal_id IS NOT NULL AND reversed_at IS NOT NULL AND reversal_posting_date IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX asset_depreciation_runs_number_unique ON asset_depreciation_runs (tenant_id, document_number) WHERE document_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX asset_depreciation_runs_journal_unique ON asset_depreciation_runs (tenant_id, journal_entry_id) WHERE journal_entry_id IS NOT NULL');

        // The posting date lies in the run's period; a run is born a draft.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION asset_depreciation_runs_link_guard() RETURNS trigger AS $$
            DECLARE p accounting_periods%ROWTYPE;
            BEGIN
                IF TG_OP = 'INSERT' AND NEW.status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'a depreciation run starts as a draft' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND NEW.accounting_period_id <> OLD.accounting_period_id AND OLD.status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'the period of a depreciation run cannot change after the draft' USING ERRCODE = '23514';
                END IF;
                SELECT * INTO p FROM accounting_periods WHERE tenant_id = NEW.tenant_id AND id = NEW.accounting_period_id;
                IF NOT FOUND OR NEW.posting_date < p.start_date OR NEW.posting_date > p.end_date THEN
                    RAISE EXCEPTION 'the posting date of a depreciation run lies in its accounting period' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER asset_depreciation_runs_link_guard BEFORE INSERT OR UPDATE ON asset_depreciation_runs FOR EACH ROW EXECUTE FUNCTION asset_depreciation_runs_link_guard()');

        // Posting: the posted journal of exactly the run's total and date, and exactly the lines the run holds.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION asset_depreciation_runs_post_guard() RETURNS trigger AS $$
            DECLARE j journal_entries%ROWTYPE; line_total numeric; line_count integer;
            BEGIN
                IF NEW.status = 'POSTED' AND OLD.status IS DISTINCT FROM 'POSTED' THEN
                    SELECT * INTO j FROM journal_entries WHERE tenant_id = NEW.tenant_id AND id = NEW.journal_entry_id;
                    IF NOT FOUND OR j.status <> 'POSTED' OR j.total_credit <> NEW.total_amount OR j.total_debit <> NEW.total_amount OR j.posting_date <> NEW.posting_date THEN
                        RAISE EXCEPTION 'a posted depreciation run needs the posted journal of exactly its total and posting date' USING ERRCODE = '23514';
                    END IF;
                    SELECT coalesce(sum(amount), 0), count(*) INTO line_total, line_count FROM asset_depreciation_run_lines WHERE tenant_id = NEW.tenant_id AND run_id = NEW.id;
                    IF line_total <> NEW.total_amount OR line_count <> NEW.asset_count THEN
                        RAISE EXCEPTION 'the lines of a depreciation run must add up to its total' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF NEW.status = 'REVERSED' AND OLD.status = 'POSTED' THEN
                    IF NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.tenant_id = NEW.tenant_id AND r.id = NEW.reversal_journal_id
                                   AND r.status = 'POSTED' AND r.reverses_journal_id = NEW.journal_entry_id) THEN
                        RAISE EXCEPTION 'a depreciation run is reversed only by the posted reversal of its own journal' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER asset_depreciation_runs_post_guard BEFORE UPDATE ON asset_depreciation_runs FOR EACH ROW EXECUTE FUNCTION asset_depreciation_runs_post_guard()');
        DocumentGuards::guardDocument('asset_depreciation_runs', self::REVERSAL);

        Schema::create('asset_depreciation_run_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('run_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('fixed_asset_id');
            $table->unsignedSmallInteger('schedule_rows'); // how many monthly rows this line covers (more than one for catch-up)
            $table->decimal('amount', 20, 4);
            $table->decimal('accumulated_before', 20, 4);
            $table->decimal('accumulated_after', 20, 4);
            $table->decimal('book_value_after', 20, 4);
            $table->uuid('expense_account_id');
            $table->uuid('accumulated_account_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('business_unit_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'run_id'])->references(['tenant_id', 'id'])->on('asset_depreciation_runs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'fixed_asset_id'])->references(['tenant_id', 'id'])->on('fixed_assets')->restrictOnDelete();
            $table->foreign(['tenant_id', 'expense_account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'accumulated_account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'business_unit_id'])->references(['tenant_id', 'id'])->on('business_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cost_center_id'])->references(['tenant_id', 'id'])->on('cost_centers')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['run_id', 'line_number']);
            $table->unique(['run_id', 'fixed_asset_id']);
            $table->index(['tenant_id', 'fixed_asset_id']);
        });
        DB::statement('ALTER TABLE asset_depreciation_run_lines ADD CONSTRAINT asset_depreciation_run_lines_amount_check CHECK (
            amount > 0 AND schedule_rows > 0 AND accumulated_before >= 0 AND accumulated_after = accumulated_before + amount AND book_value_after >= 0)');
        DocumentGuards::guardLines('asset_depreciation_run_lines', 'asset_depreciation_runs', 'run_id');

        Schema::table('asset_depreciation_schedules', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'run_line_id'])->references(['tenant_id', 'id'])->on('asset_depreciation_run_lines')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asset_depreciation_schedules', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'run_line_id']);
        });
        DocumentGuards::drop('asset_depreciation_run_lines');
        Schema::dropIfExists('asset_depreciation_run_lines');
        DocumentGuards::drop('asset_depreciation_runs');
        DB::unprepared('DROP TRIGGER IF EXISTS asset_depreciation_runs_post_guard ON asset_depreciation_runs');
        DB::unprepared('DROP FUNCTION IF EXISTS asset_depreciation_runs_post_guard()');
        DB::unprepared('DROP TRIGGER IF EXISTS asset_depreciation_runs_link_guard ON asset_depreciation_runs');
        DB::unprepared('DROP FUNCTION IF EXISTS asset_depreciation_runs_link_guard()');
        Schema::dropIfExists('asset_depreciation_runs');
    }
};
