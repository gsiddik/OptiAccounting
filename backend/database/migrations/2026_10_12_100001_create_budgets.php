<?php

use App\Support\Database\DocumentGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA4 batch A: budgets, budget versions and budget lines. A budget is planning data: nothing here references a journal, and no
 * trigger or service creates one. The budget (the container for a fiscal year) and its versions have separate lifecycles:
 *   budget  DRAFT > ACTIVE > CLOSED, with CANCELLED (only while no version was ever approved);
 *   version DRAFT > SUBMITTED > APPROVED > ACTIVE > SUPERSEDED, with REJECTED (back to DRAFT) and CANCELLED.
 * An approved version is never rewritten: a revision is a new version. Activating a version closes the effective window of the
 * previous active one the day before, so exactly one version is effective on any date (exclusion constraint).
 */
return new class extends Migration
{
    private const ZERO = "'00000000-0000-0000-0000-000000000000'::uuid";

    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('budgets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->uuid('fiscal_year_id');
            $table->char('currency', 3); // the functional currency of the books; budgets are planned in it
            $table->uuid('responsible_user_id')->nullable();
            $table->string('status', 12)->default('DRAFT');
            $table->uuid('created_by')->nullable();
            $table->uuid('activated_by')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'fiscal_year_id'])->references(['tenant_id', 'id'])->on('fiscal_years')->restrictOnDelete();
            foreach (['responsible_user_id', 'created_by', 'activated_by', 'closed_by', 'cancelled_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->restrictOnDelete();
            }
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'fiscal_year_id', 'status']);
        });
        DB::statement("ALTER TABLE budgets ADD CONSTRAINT budgets_status_check CHECK (status IN ('DRAFT','ACTIVE','CLOSED','CANCELLED'))");
        DB::statement("ALTER TABLE budgets ADD CONSTRAINT budgets_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION budgets_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'a budget starts as a draft' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'only a draft budget can be deleted' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status IN ('CLOSED', 'CANCELLED') THEN
                    RAISE EXCEPTION 'a % budget is final and cannot change', lower(OLD.status) USING ERRCODE = '23514';
                END IF;
                IF NEW.tenant_id <> OLD.tenant_id OR NEW.code <> OLD.code OR NEW.fiscal_year_id <> OLD.fiscal_year_id OR NEW.currency <> OLD.currency THEN
                    RAISE EXCEPTION 'the code, fiscal year and currency of a budget cannot change' USING ERRCODE = '23514';
                END IF;
                IF NEW.status <> OLD.status THEN
                    IF NOT ((OLD.status = 'DRAFT' AND NEW.status IN ('ACTIVE', 'CANCELLED')) OR (OLD.status = 'ACTIVE' AND NEW.status IN ('CLOSED', 'CANCELLED'))) THEN
                        RAISE EXCEPTION 'a budget cannot move from % to %', OLD.status, NEW.status USING ERRCODE = '23514';
                    END IF;
                    IF NEW.status = 'CANCELLED' AND EXISTS (SELECT 1 FROM budget_versions WHERE tenant_id = NEW.tenant_id AND budget_id = NEW.id AND status IN ('APPROVED', 'ACTIVE', 'SUPERSEDED')) THEN
                        RAISE EXCEPTION 'a budget with an approved version cannot be cancelled; close it instead' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER budgets_guard BEFORE INSERT OR UPDATE OR DELETE ON budgets FOR EACH ROW EXECUTE FUNCTION budgets_guard()');

        Schema::create('budget_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('budget_id');
            $table->unsignedSmallInteger('version_number');
            $table->string('label', 100); // free text ("Original", "Revision 1", ...): never hardcoded
            $table->string('description', 500)->nullable();
            $table->uuid('base_version_id')->nullable(); // the version this one was copied from
            $table->string('status', 12)->default('DRAFT');
            $table->date('effective_from')->nullable(); // set on activation
            $table->date('effective_until')->nullable(); // set when a later version supersedes this one
            $table->uuid('created_by')->nullable();
            $table->uuid('submitted_by')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->uuid('activated_by')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->uuid('superseded_by_version_id')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'budget_id'])->references(['tenant_id', 'id'])->on('budgets')->restrictOnDelete();
            foreach (['created_by', 'submitted_by', 'approved_by', 'rejected_by', 'cancelled_by', 'activated_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->restrictOnDelete();
            }
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'budget_id', 'id']);
            $table->unique(['budget_id', 'version_number']);
            $table->index(['tenant_id', 'budget_id', 'status']);
        });
        Schema::table('budget_versions', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'base_version_id'])->references(['tenant_id', 'id'])->on('budget_versions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'superseded_by_version_id'])->references(['tenant_id', 'id'])->on('budget_versions')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE budget_versions ADD CONSTRAINT budget_versions_status_check CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','REJECTED','ACTIVE','SUPERSEDED','CANCELLED'))");
        DB::statement("ALTER TABLE budget_versions ADD CONSTRAINT budget_versions_window_check CHECK (
            ((status IN ('ACTIVE','SUPERSEDED')) = (effective_from IS NOT NULL))
            AND ((status = 'SUPERSEDED') = (effective_until IS NOT NULL))
            AND (effective_until IS NULL OR effective_until >= effective_from))");
        DB::statement('ALTER TABLE budget_versions ADD CONSTRAINT budget_versions_number_check CHECK (version_number >= 1)');
        // One active version per budget, and the effective windows of a budget's versions never overlap: exactly one version is effective on a date.
        DB::statement("CREATE UNIQUE INDEX budget_versions_one_active ON budget_versions (budget_id) WHERE status = 'ACTIVE'");
        DB::statement("ALTER TABLE budget_versions ADD CONSTRAINT budget_versions_effective_no_overlap EXCLUDE USING gist (
            budget_id WITH =, daterange(effective_from, effective_until, '[]') WITH &&) WHERE (status IN ('ACTIVE','SUPERSEDED'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION budget_versions_guard() RETURNS trigger AS $$
            DECLARE b budgets%ROWTYPE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'only a draft budget version can be deleted' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;

                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'a budget version starts as a draft' USING ERRCODE = '23514';
                    END IF;
                    SELECT * INTO b FROM budgets WHERE tenant_id = NEW.tenant_id AND id = NEW.budget_id;
                    IF b.status NOT IN ('DRAFT', 'ACTIVE') THEN
                        RAISE EXCEPTION 'versions can be added to a draft or active budget only' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.status IN ('SUPERSEDED', 'CANCELLED') THEN
                    RAISE EXCEPTION 'a % budget version is final and cannot change', lower(OLD.status) USING ERRCODE = '23514';
                END IF;
                IF NEW.tenant_id <> OLD.tenant_id OR NEW.budget_id <> OLD.budget_id OR NEW.version_number <> OLD.version_number THEN
                    RAISE EXCEPTION 'the budget and number of a version cannot change' USING ERRCODE = '23514';
                END IF;
                IF OLD.status <> 'DRAFT' AND (NEW.label IS DISTINCT FROM OLD.label OR NEW.description IS DISTINCT FROM OLD.description OR NEW.base_version_id IS DISTINCT FROM OLD.base_version_id) THEN
                    RAISE EXCEPTION 'a submitted, approved or active budget version cannot be edited; revise it as a new version' USING ERRCODE = '23514';
                END IF;
                IF NEW.status <> OLD.status AND NOT (
                    (OLD.status = 'DRAFT' AND NEW.status IN ('SUBMITTED', 'CANCELLED'))
                    OR (OLD.status = 'SUBMITTED' AND NEW.status IN ('APPROVED', 'REJECTED', 'CANCELLED'))
                    OR (OLD.status = 'REJECTED' AND NEW.status IN ('DRAFT', 'CANCELLED'))
                    OR (OLD.status = 'APPROVED' AND NEW.status IN ('ACTIVE', 'CANCELLED'))
                    OR (OLD.status = 'ACTIVE' AND NEW.status = 'SUPERSEDED')) THEN
                    RAISE EXCEPTION 'a budget version cannot move from % to %', OLD.status, NEW.status USING ERRCODE = '23514';
                END IF;
                IF NEW.status = 'SUBMITTED' AND OLD.status = 'DRAFT'
                   AND NOT EXISTS (SELECT 1 FROM budget_lines WHERE tenant_id = NEW.tenant_id AND budget_version_id = NEW.id) THEN
                    RAISE EXCEPTION 'a budget version without lines cannot be submitted' USING ERRCODE = '23514';
                END IF;
                IF NEW.status = 'ACTIVE' AND OLD.status = 'APPROVED' THEN
                    SELECT * INTO b FROM budgets WHERE tenant_id = NEW.tenant_id AND id = NEW.budget_id;
                    IF b.status <> 'ACTIVE' THEN
                        RAISE EXCEPTION 'only a version of an active budget can be activated' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF OLD.status = 'ACTIVE' AND NEW.effective_from IS DISTINCT FROM OLD.effective_from THEN
                    RAISE EXCEPTION 'the effective start of an active budget version cannot change' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'ACTIVE' AND NEW.status = 'ACTIVE' AND NEW.effective_until IS DISTINCT FROM OLD.effective_until THEN
                    RAISE EXCEPTION 'an active budget version ends only when it is superseded' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER budget_versions_guard BEFORE INSERT OR UPDATE OR DELETE ON budget_versions FOR EACH ROW EXECUTE FUNCTION budget_versions_guard()');

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('budget_id');
            $table->uuid('budget_version_id');
            $table->uuid('account_id'); // a posting account, or a header account that stands for all its descendants
            $table->uuid('accounting_period_id');
            $table->decimal('amount', 20, 4); // planned amount in the account's normal-balance direction
            $table->uuid('branch_id')->nullable(); // NULL = not split by this dimension
            $table->uuid('business_unit_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->string('description', 255)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'budget_id', 'budget_version_id'])->references(['tenant_id', 'budget_id', 'id'])->on('budget_versions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'accounting_period_id'])->references(['tenant_id', 'id'])->on('accounting_periods')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'business_unit_id'])->references(['tenant_id', 'id'])->on('business_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cost_center_id'])->references(['tenant_id', 'id'])->on('cost_centers')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'budget_version_id', 'accounting_period_id']);
            $table->index(['tenant_id', 'account_id']);
        });
        DB::statement('ALTER TABLE budget_lines ADD CONSTRAINT budget_lines_amount_check CHECK (amount >= 0)');
        $zero = self::ZERO;
        DB::statement("CREATE UNIQUE INDEX budget_lines_natural_unique ON budget_lines (budget_version_id, account_id, accounting_period_id,
            coalesce(branch_id, {$zero}), coalesce(business_unit_id, {$zero}), coalesce(cost_center_id, {$zero}))");

        // The period must belong to the fiscal year of the line's budget: a line cannot plan outside its year.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION budget_lines_refs() RETURNS trigger AS $$
            DECLARE budget_year uuid; period_year uuid;
            BEGIN
                SELECT fiscal_year_id INTO budget_year FROM budgets WHERE tenant_id = NEW.tenant_id AND id = NEW.budget_id;
                SELECT fiscal_year_id INTO period_year FROM accounting_periods WHERE tenant_id = NEW.tenant_id AND id = NEW.accounting_period_id;
                IF budget_year IS DISTINCT FROM period_year THEN
                    RAISE EXCEPTION 'a budget line period must belong to the fiscal year of its budget' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER budget_lines_refs BEFORE INSERT OR UPDATE ON budget_lines FOR EACH ROW EXECUTE FUNCTION budget_lines_refs()');
        DocumentGuards::guardLines('budget_lines', 'budget_versions', 'budget_version_id');
    }

    public function down(): void
    {
        DocumentGuards::drop('budget_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS budget_lines_refs ON budget_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS budget_lines_refs()');
        Schema::dropIfExists('budget_lines');
        DB::unprepared('DROP TRIGGER IF EXISTS budget_versions_guard ON budget_versions');
        DB::unprepared('DROP FUNCTION IF EXISTS budget_versions_guard()');
        Schema::dropIfExists('budget_versions');
        DB::unprepared('DROP TRIGGER IF EXISTS budgets_guard ON budgets');
        DB::unprepared('DROP FUNCTION IF EXISTS budgets_guard()');
        Schema::dropIfExists('budgets');
    }
};
