<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA4 batch C: asset categories, the fixed asset register and the depreciation schedule.
 *
 * The register is subledger metadata; the general ledger stays the financial truth and every journal comes from the Posting Engine.
 * The database repeats the rules the services enforce:
 *  - an asset leaves DRAFT only along the lifecycle graph, and once it has been capitalized its financial terms (cost, residual, life,
 *    method, start policy, category, accounts, dimensions) never change again, so posted history cannot be rewritten by an edit;
 *  - accumulated depreciation is never negative and never exceeds the depreciable basis (cost - residual);
 *  - the schedule has exactly one row per asset and month, and a row belongs to at most one depreciation run at a time, so the same
 *    asset-period can never be depreciated twice; a posted row changes only when its run is reversed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            // Optional account overrides; without one the account role mapping (FIXED_ASSET, ACCUMULATED_DEPRECIATION, ...) decides.
            $table->uuid('asset_account_id')->nullable();
            $table->uuid('accumulated_account_id')->nullable();
            $table->uuid('expense_account_id')->nullable();
            $table->uuid('gain_loss_account_id')->nullable();
            $table->string('default_method', 20)->default('STRAIGHT_LINE');
            $table->unsignedSmallInteger('default_useful_life_months')->nullable();
            $table->string('default_residual_type', 10)->default('NONE'); // NONE | AMOUNT | PERCENT of the cost
            $table->decimal('default_residual_value', 20, 4)->default(0);
            $table->string('default_start_policy', 20)->default('CAPITALIZATION_MONTH');
            $table->string('status', 10)->default('ACTIVE');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            foreach (['asset_account_id', 'accumulated_account_id', 'expense_account_id', 'gain_loss_account_id'] as $column) {
                $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            }
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
        });
        DB::statement("ALTER TABLE asset_categories ADD CONSTRAINT asset_categories_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");
        DB::statement("ALTER TABLE asset_categories ADD CONSTRAINT asset_categories_method_check CHECK (default_method IN ('STRAIGHT_LINE','DECLINING_BALANCE','NONE'))");
        DB::statement("ALTER TABLE asset_categories ADD CONSTRAINT asset_categories_policy_check CHECK (default_start_policy IN ('CAPITALIZATION_MONTH','NEXT_MONTH'))");
        DB::statement("ALTER TABLE asset_categories ADD CONSTRAINT asset_categories_residual_check CHECK (default_residual_type IN ('NONE','AMOUNT','PERCENT') AND default_residual_value >= 0
            AND (default_residual_type <> 'PERCENT' OR default_residual_value <= 100) AND (default_residual_type <> 'NONE' OR default_residual_value = 0))");
        DB::statement('ALTER TABLE asset_categories ADD CONSTRAINT asset_categories_life_check CHECK (default_useful_life_months IS NULL OR default_useful_life_months BETWEEN 1 AND 1200)');

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('asset_number', 40)->nullable(); // issued at capitalization
            $table->string('name', 200);
            $table->string('description', 500)->nullable();
            $table->uuid('asset_category_id');
            $table->string('status', 20)->default('DRAFT');
            $table->date('acquisition_date');
            $table->date('capitalization_date');
            $table->decimal('acquisition_cost', 20, 4);
            $table->decimal('residual_value', 20, 4)->default(0);
            $table->unsignedSmallInteger('useful_life_months')->nullable();
            $table->string('method', 20)->default('STRAIGHT_LINE');
            $table->jsonb('method_params')->nullable();
            $table->string('start_policy', 20)->default('CAPITALIZATION_MONTH');
            $table->char('currency', 3);
            $table->uuid('branch_id')->nullable();
            $table->uuid('business_unit_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->string('capitalization_mode', 15)->default('POST'); // POST: journal Dr asset Cr source | REGISTER_ONLY: cost already in the ledger through an AP invoice
            $table->uuid('source_account_id')->nullable(); // the account credited by a POST capitalization (payable, bank, clearing)
            $table->string('source_type', 30)->nullable(); // AP_INVOICE_LINE | EXTERNAL
            $table->string('source_id', 64)->nullable();
            $table->string('source_reference', 100)->nullable();
            // Accounts resolved at capitalization and frozen: later category or mapping changes never move an existing asset.
            $table->uuid('asset_account_id')->nullable();
            $table->uuid('accumulated_account_id')->nullable();
            $table->uuid('expense_account_id')->nullable();
            $table->uuid('gain_loss_account_id')->nullable();
            $table->decimal('depreciable_basis', 20, 4)->nullable();
            $table->decimal('accumulated_depreciation', 20, 4)->default(0);
            $table->jsonb('schedule_snapshot')->nullable(); // every input of the schedule as it was at capitalization (audit)
            $table->uuid('created_by')->nullable();
            $table->uuid('capitalized_by')->nullable();
            $table->timestampTz('capitalized_at')->nullable();
            $table->uuid('capitalization_journal_id')->nullable();
            $table->uuid('capitalization_event_id')->nullable();
            $table->uuid('capitalization_reversal_journal_id')->nullable();
            $table->uuid('capitalization_reversed_by')->nullable();
            $table->timestampTz('capitalization_reversed_at')->nullable();
            $table->string('capitalization_reversal_reason', 500)->nullable();
            $table->date('disposed_on')->nullable();
            $table->uuid('disposal_id')->nullable();
            $table->string('inactive_reason', 500)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'asset_category_id'])->references(['tenant_id', 'id'])->on('asset_categories')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'business_unit_id'])->references(['tenant_id', 'id'])->on('business_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cost_center_id'])->references(['tenant_id', 'id'])->on('cost_centers')->restrictOnDelete();
            foreach (['source_account_id', 'asset_account_id', 'accumulated_account_id', 'expense_account_id', 'gain_loss_account_id'] as $column) {
                $table->foreign(['tenant_id', $column])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            }
            $table->foreign(['tenant_id', 'capitalization_journal_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'capitalization_reversal_journal_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign('capitalization_event_id')->references('id')->on('accounting_events')->restrictOnDelete();
            foreach (['created_by', 'capitalized_by', 'capitalization_reversed_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->restrictOnDelete();
            }
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'asset_category_id', 'status']);
            $table->index(['tenant_id', 'created_by']);
        });
        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_status_check CHECK (status IN ('DRAFT','ACTIVE','FULLY_DEPRECIATED','DISPOSED','INACTIVE'))");
        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_method_check CHECK (method IN ('STRAIGHT_LINE','DECLINING_BALANCE','NONE'))");
        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_policy_check CHECK (start_policy IN ('CAPITALIZATION_MONTH','NEXT_MONTH'))");
        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_mode_check CHECK (capitalization_mode IN ('POST','REGISTER_ONLY'))");
        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement('ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_basis_check CHECK (acquisition_cost > 0 AND residual_value >= 0 AND residual_value <= acquisition_cost)');
        DB::statement('ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_life_check CHECK (useful_life_months IS NULL OR useful_life_months BETWEEN 1 AND 1200)');
        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_method_life_check CHECK ((method = 'NONE') = (useful_life_months IS NULL))");
        DB::statement('ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_dates_check CHECK (capitalization_date >= acquisition_date)');
        DB::statement('ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_accumulated_check CHECK (accumulated_depreciation >= 0
            AND ((depreciable_basis IS NULL AND accumulated_depreciation = 0) OR (depreciable_basis IS NOT NULL AND accumulated_depreciation <= depreciable_basis)))');
        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_capitalized_check CHECK (status IN ('DRAFT','INACTIVE') OR (
            asset_number IS NOT NULL AND capitalized_at IS NOT NULL AND depreciable_basis = acquisition_cost - residual_value AND schedule_snapshot IS NOT NULL
            AND asset_account_id IS NOT NULL AND (method = 'NONE' OR (accumulated_account_id IS NOT NULL AND expense_account_id IS NOT NULL))))");
        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_post_mode_check CHECK (status IN ('DRAFT','INACTIVE') OR capitalization_mode <> 'POST' OR capitalization_journal_id IS NOT NULL)");
        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_source_check CHECK (
            (source_type IS NULL OR source_type IN ('AP_INVOICE_LINE','EXTERNAL'))
            AND (capitalization_mode <> 'REGISTER_ONLY' OR (source_type = 'AP_INVOICE_LINE' AND source_id IS NOT NULL))
            AND (capitalization_mode <> 'POST' OR source_type IS DISTINCT FROM 'AP_INVOICE_LINE')
            AND (status IN ('DRAFT','INACTIVE') OR capitalization_mode <> 'POST' OR source_account_id IS NOT NULL))");
        DB::statement("ALTER TABLE fixed_assets ADD CONSTRAINT fixed_assets_disposed_check CHECK ((status = 'DISPOSED') = (disposed_on IS NOT NULL AND disposal_id IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX fixed_assets_number_unique ON fixed_assets (tenant_id, asset_number) WHERE asset_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX fixed_assets_journal_unique ON fixed_assets (tenant_id, capitalization_journal_id) WHERE capitalization_journal_id IS NOT NULL');
        DB::statement("CREATE INDEX fixed_assets_source ON fixed_assets (tenant_id, source_type, source_id) WHERE source_id IS NOT NULL AND status <> 'INACTIVE'");

        // Lifecycle: DRAFT > ACTIVE > FULLY_DEPRECIATED > DISPOSED; INACTIVE is the end of a discarded draft or a reversed capitalization.
        // After capitalization the financial terms are frozen. Only a draft is deleted.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION fixed_assets_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'only a draft fixed asset can be deleted' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status = 'INACTIVE' THEN
                    RAISE EXCEPTION 'an inactive fixed asset is final and cannot change' USING ERRCODE = '23514';
                END IF;
                IF NEW.status <> OLD.status AND NOT (
                    (OLD.status = 'DRAFT' AND NEW.status IN ('ACTIVE', 'INACTIVE'))
                    OR (OLD.status = 'ACTIVE' AND NEW.status IN ('FULLY_DEPRECIATED', 'DISPOSED', 'INACTIVE'))
                    OR (OLD.status = 'FULLY_DEPRECIATED' AND NEW.status IN ('ACTIVE', 'DISPOSED'))
                    OR (OLD.status = 'DISPOSED' AND NEW.status IN ('ACTIVE', 'FULLY_DEPRECIATED'))) THEN
                    RAISE EXCEPTION 'fixed asset status cannot move from % to %', OLD.status, NEW.status USING ERRCODE = '23514';
                END IF;
                IF OLD.status <> 'DRAFT' AND (to_jsonb(NEW) - 'status' - 'updated_at' - 'accumulated_depreciation' - 'disposed_on' - 'disposal_id' - 'name' - 'description'
                        - 'inactive_reason' - 'capitalization_reversal_journal_id' - 'capitalization_reversed_by' - 'capitalization_reversed_at' - 'capitalization_reversal_reason')
                   IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'updated_at' - 'accumulated_depreciation' - 'disposed_on' - 'disposal_id' - 'name' - 'description'
                        - 'inactive_reason' - 'capitalization_reversal_journal_id' - 'capitalization_reversed_by' - 'capitalization_reversed_at' - 'capitalization_reversal_reason') THEN
                    RAISE EXCEPTION 'the financial terms of a capitalized fixed asset cannot change' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'ACTIVE' AND NEW.status = 'INACTIVE' AND (NEW.capitalization_reversed_at IS NULL
                        OR (NEW.capitalization_mode = 'POST' AND NEW.capitalization_reversal_journal_id IS NULL)) THEN
                    RAISE EXCEPTION 'a capitalized fixed asset becomes inactive only through the reversal of its capitalization' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'DRAFT' AND NEW.status = 'ACTIVE' AND NEW.capitalization_mode = 'POST' AND NOT EXISTS (
                        SELECT 1 FROM journal_entries j WHERE j.tenant_id = NEW.tenant_id AND j.id = NEW.capitalization_journal_id AND j.status = 'POSTED'
                        AND j.total_debit = NEW.acquisition_cost AND j.posting_date = NEW.capitalization_date) THEN
                    RAISE EXCEPTION 'a capitalized fixed asset needs the posted journal of exactly its cost and capitalization date' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER fixed_assets_guard BEFORE UPDATE OR DELETE ON fixed_assets FOR EACH ROW EXECUTE FUNCTION fixed_assets_guard()');

        Schema::create('asset_depreciation_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('fixed_asset_id');
            $table->unsignedSmallInteger('sequence_no');
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('amount', 20, 4);
            $table->decimal('accumulated_after', 20, 4); // planned running total (calculation snapshot, not a balance)
            $table->decimal('book_value_after', 20, 4);
            $table->string('status', 10)->default('PLANNED');
            $table->uuid('run_line_id')->nullable(); // the depreciation run line that holds this row (IN_RUN) or posted it (POSTED)
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'fixed_asset_id'])->references(['tenant_id', 'id'])->on('fixed_assets')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['fixed_asset_id', 'sequence_no']);
            $table->unique(['fixed_asset_id', 'period_start']); // one row per asset and month: the same asset-period is never depreciated twice
            $table->index(['tenant_id', 'status', 'period_end']);
            $table->index(['run_line_id']);
        });
        DB::statement("ALTER TABLE asset_depreciation_schedules ADD CONSTRAINT asset_depreciation_schedules_status_check CHECK (status IN ('PLANNED','IN_RUN','POSTED','CANCELLED'))");
        DB::statement('ALTER TABLE asset_depreciation_schedules ADD CONSTRAINT asset_depreciation_schedules_amount_check CHECK (amount > 0 AND accumulated_after >= amount AND book_value_after >= 0 AND period_end >= period_start)');
        DB::statement("ALTER TABLE asset_depreciation_schedules ADD CONSTRAINT asset_depreciation_schedules_claim_check CHECK ((status IN ('IN_RUN','POSTED')) = (run_line_id IS NOT NULL))");

        // A new row may not take the asset past its depreciable basis; a row never changes except its claim columns; a claim is taken
        // from PLANNED only, a posted row is released only when its run was reversed, a cancelled row is final.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION asset_depreciation_schedules_guard() RETURNS trigger AS $$
            DECLARE basis numeric; planned numeric; run_status text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'a depreciation schedule row is never deleted' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'INSERT' THEN
                    SELECT depreciable_basis INTO basis FROM fixed_assets WHERE tenant_id = NEW.tenant_id AND id = NEW.fixed_asset_id;
                    SELECT coalesce(sum(amount), 0) INTO planned FROM asset_depreciation_schedules WHERE fixed_asset_id = NEW.fixed_asset_id AND status <> 'CANCELLED';
                    IF basis IS NULL OR planned + NEW.amount > basis THEN
                        RAISE EXCEPTION 'the depreciation schedule cannot exceed the depreciable basis of the asset' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.status <> 'PLANNED' THEN
                        RAISE EXCEPTION 'a schedule row starts as planned' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF (to_jsonb(NEW) - 'status' - 'run_line_id' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'status' - 'run_line_id' - 'updated_at') THEN
                    RAISE EXCEPTION 'the amount and period of a depreciation schedule row cannot change' USING ERRCODE = '23514';
                END IF;
                IF NEW.status = OLD.status AND NEW.run_line_id IS NOT DISTINCT FROM OLD.run_line_id THEN
                    RETURN NEW;
                END IF;
                IF OLD.status = 'CANCELLED' THEN
                    RAISE EXCEPTION 'a cancelled schedule row is final' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'PLANNED' AND NEW.status NOT IN ('IN_RUN', 'CANCELLED') THEN
                    RAISE EXCEPTION 'a planned schedule row is taken by a run or cancelled' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'IN_RUN' AND (NEW.status NOT IN ('POSTED', 'PLANNED') OR (NEW.status = 'POSTED' AND NEW.run_line_id IS DISTINCT FROM OLD.run_line_id)) THEN
                    RAISE EXCEPTION 'a schedule row held by a run is posted with it or released' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'POSTED' THEN
                    SELECT r.status INTO run_status FROM asset_depreciation_run_lines l JOIN asset_depreciation_runs r ON r.tenant_id = l.tenant_id AND r.id = l.run_id
                        WHERE l.tenant_id = OLD.tenant_id AND l.id = OLD.run_line_id;
                    IF NEW.status <> 'PLANNED' OR run_status IS DISTINCT FROM 'REVERSED' THEN
                        RAISE EXCEPTION 'posted depreciation is immutable; it is released only by reversing its run' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        // The function names asset_depreciation_runs, which the next migration creates; PL/pgSQL resolves tables at run time, so the order is safe.
        DB::unprepared('CREATE TRIGGER asset_depreciation_schedules_guard BEFORE INSERT OR UPDATE OR DELETE ON asset_depreciation_schedules FOR EACH ROW EXECUTE FUNCTION asset_depreciation_schedules_guard()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS asset_depreciation_schedules_guard ON asset_depreciation_schedules');
        DB::unprepared('DROP FUNCTION IF EXISTS asset_depreciation_schedules_guard()');
        Schema::dropIfExists('asset_depreciation_schedules');
        DB::unprepared('DROP TRIGGER IF EXISTS fixed_assets_guard ON fixed_assets');
        DB::unprepared('DROP FUNCTION IF EXISTS fixed_assets_guard()');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('asset_categories');
    }
};
