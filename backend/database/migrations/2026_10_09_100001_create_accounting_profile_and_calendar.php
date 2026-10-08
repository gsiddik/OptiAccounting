<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** OA1 batches A-B: accounting profile, fiscal years and accounting periods. */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('accounting_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('framework', 20);
            $table->char('functional_currency', 3);
            $table->unsignedSmallInteger('currency_scale')->default(2); // minor units accepted on a journal line (IDR 2, JPY 0)
            $table->string('status', 20)->default('CONFIGURING');
            // Policy metadata (docs/architecture/ACCOUNTING_CORE.md): workflow and segregation of duties.
            $table->boolean('approval_required')->default(true);
            $table->boolean('sod_creator_not_approver')->default(true);
            $table->boolean('sod_creator_not_poster')->default(false);
            $table->boolean('sod_approver_not_poster')->default(false);
            $table->date('cutover_date')->nullable(); // set when the opening balance is posted
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('locked_at')->nullable(); // first posted journal
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique('tenant_id');
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE accounting_profiles ADD CONSTRAINT accounting_profiles_framework_check CHECK (framework IN ('SAK_GENERAL','SAK_EP','SAK_EMKM','CUSTOM'))");
        DB::statement("ALTER TABLE accounting_profiles ADD CONSTRAINT accounting_profiles_status_check CHECK (status IN ('CONFIGURING','READY','LOCKED'))");
        DB::statement("ALTER TABLE accounting_profiles ADD CONSTRAINT accounting_profiles_currency_check CHECK (functional_currency ~ '^[A-Z]{3}\$' AND currency_scale BETWEEN 0 AND 4)");

        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 30);
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('DRAFT');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_status_check CHECK (status IN ('DRAFT','OPEN','CLOSED'))");
        DB::statement('ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_range_check CHECK (end_date > start_date)');
        // A tenant's fiscal years never overlap (non-calendar years are fine; gaps are allowed).
        DB::statement("ALTER TABLE fiscal_years ADD CONSTRAINT fiscal_years_no_overlap EXCLUDE USING gist (
            tenant_id WITH =, daterange(start_date, end_date, '[]') WITH &&)");

        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('fiscal_year_id');
            $table->unsignedSmallInteger('number');
            $table->string('code', 30);
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status', 20)->default('FUTURE');
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'fiscal_year_id'])->references(['tenant_id', 'id'])->on('fiscal_years')->cascadeOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'fiscal_year_id', 'number']);
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'start_date', 'end_date']);
        });
        DB::statement("ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_status_check CHECK (status IN ('FUTURE','OPEN','SOFT_CLOSED','CLOSED'))");
        DB::statement('ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_range_check CHECK (end_date >= start_date)');
        DB::statement("ALTER TABLE accounting_periods ADD CONSTRAINT accounting_periods_no_overlap EXCLUDE USING gist (
            tenant_id WITH =, daterange(start_date, end_date, '[]') WITH &&)");

        // A period lies inside its fiscal year; a closed period never changes its dates or goes back to open.
        DB::unprepared("CREATE OR REPLACE FUNCTION accounting_periods_guard() RETURNS trigger AS \$\$
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
            END; \$\$ LANGUAGE plpgsql");
        DB::unprepared('CREATE TRIGGER accounting_periods_guard BEFORE INSERT OR UPDATE ON accounting_periods
            FOR EACH ROW EXECUTE FUNCTION accounting_periods_guard()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS accounting_periods_guard ON accounting_periods');
        DB::unprepared('DROP FUNCTION IF EXISTS accounting_periods_guard()');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('fiscal_years');
        Schema::dropIfExists('accounting_profiles');
    }
};
