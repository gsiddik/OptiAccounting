<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA5 batch B: report definitions, report lines and COA report mappings. Configuration only: no table here holds an amount, so
 * financial statements are always derived from POSTED journal lines and never from a copy.
 *
 *   report definition (versioned, per statement type)  ->  report lines (tree)  ->  report line mappings (account / account group)
 *
 * A definition moves DRAFT > ACTIVE > ARCHIVED. Only a DRAFT definition can change (lines and mappings included, trigger-enforced); a
 * revision is a new version. Publishing sets the effective window and archives the previous version the day before, so exactly one
 * version applies on any date (exclusion constraint) and a historical report can be reproduced with the version that applied then.
 * Line calculations are declarative (operand lists over other line codes); there is no formula language and nothing is evaluated.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('report_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('statement_type', 16);
            $table->string('code', 40);
            $table->string('name', 150);
            $table->unsignedInteger('version');
            $table->string('status', 10)->default('DRAFT');
            $table->uuid('based_on_id')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('description', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('published_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'statement_type', 'version']);
            $table->unique(['tenant_id', 'code', 'version']);
            $table->index(['tenant_id', 'statement_type', 'status']);
        });
        DB::statement('ALTER TABLE report_definitions ADD CONSTRAINT report_definitions_based_on_fk FOREIGN KEY (tenant_id, based_on_id) REFERENCES report_definitions (tenant_id, id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE report_definitions ADD CONSTRAINT report_definitions_type_check CHECK (statement_type IN ('BALANCE_SHEET','PROFIT_LOSS','CASH_FLOW'))");
        DB::statement("ALTER TABLE report_definitions ADD CONSTRAINT report_definitions_status_check CHECK (status IN ('DRAFT','ACTIVE','ARCHIVED'))");
        DB::statement('ALTER TABLE report_definitions ADD CONSTRAINT report_definitions_window_check CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
        // One version applies on any date: published versions of a statement type never overlap in time (NULL bound = open-ended).
        DB::statement("ALTER TABLE report_definitions ADD CONSTRAINT report_definitions_no_overlap EXCLUDE USING gist (
            tenant_id WITH =, statement_type WITH =, daterange(effective_from, effective_to, '[]') WITH &&) WHERE (status IN ('ACTIVE','ARCHIVED'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_definitions_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'a report definition starts as a draft' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'only a draft report definition can be deleted' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;

                IF NEW.tenant_id <> OLD.tenant_id OR NEW.statement_type <> OLD.statement_type OR NEW.version <> OLD.version OR NEW.code <> OLD.code THEN
                    RAISE EXCEPTION 'the type, code and version of a report definition cannot change' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'ARCHIVED' THEN
                    RAISE EXCEPTION 'an archived report definition is final' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'ACTIVE' THEN
                    IF NEW.status = 'ARCHIVED' THEN
                        IF NEW.effective_from IS DISTINCT FROM OLD.effective_from THEN
                            RAISE EXCEPTION 'archiving cannot move the start of the effective window' USING ERRCODE = '23514';
                        END IF;
                        RETURN NEW;
                    END IF;
                    IF NEW.status <> 'ACTIVE' OR NEW.name IS DISTINCT FROM OLD.name OR NEW.effective_from IS DISTINCT FROM OLD.effective_from
                       OR NEW.effective_to IS DISTINCT FROM OLD.effective_to OR NEW.description IS DISTINCT FROM OLD.description THEN
                        RAISE EXCEPTION 'a published report definition cannot change; create a new version' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF NEW.status = 'ARCHIVED' THEN
                    RAISE EXCEPTION 'a draft report definition is published before it can be archived' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER report_definitions_guard BEFORE INSERT OR UPDATE OR DELETE ON report_definitions FOR EACH ROW EXECUTE FUNCTION report_definitions_guard()');

        Schema::create('report_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('report_definition_id');
            $table->uuid('parent_id')->nullable();
            $table->string('code', 40);
            $table->string('label', 200);
            $table->string('line_type', 16);
            $table->unsignedInteger('display_order')->default(0);
            $table->string('balance_side', 6)->nullable();          // ACCOUNT / ACCOUNT_GROUP: DEBIT = debit minus credit, CREDIT = credit minus debit
            $table->string('cash_flow_category', 14)->nullable();   // cash flow definitions: how the movement of the mapped accounts is classified
            $table->string('system_key', 24)->nullable();           // SYSTEM lines: one of a closed list of built-in figures
            $table->jsonb('operands')->nullable();                  // CALCULATED_TOTAL: [{"line":"CODE","sign":"+"|"-"}]
            $table->boolean('show_zero')->default(false);
            $table->boolean('emphasis')->default(false);
            $table->string('notes', 500)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'report_definition_id'])->references(['tenant_id', 'id'])->on('report_definitions')->cascadeOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'report_definition_id', 'id']);
            $table->unique(['tenant_id', 'report_definition_id', 'code']);
            $table->index(['tenant_id', 'report_definition_id', 'parent_id', 'display_order']);
        });
        DB::statement('ALTER TABLE report_lines ADD CONSTRAINT report_lines_parent_fk FOREIGN KEY (tenant_id, report_definition_id, parent_id) REFERENCES report_lines (tenant_id, report_definition_id, id)'); // NO ACTION: a whole draft tree can be removed in one statement
        DB::statement("ALTER TABLE report_lines ADD CONSTRAINT report_lines_type_check CHECK (line_type IN ('HEADER','ACCOUNT','ACCOUNT_GROUP','SUBTOTAL','CALCULATED_TOTAL','SYSTEM'))");
        DB::statement("ALTER TABLE report_lines ADD CONSTRAINT report_lines_side_check CHECK (balance_side IS NULL OR balance_side IN ('DEBIT','CREDIT'))");
        DB::statement("ALTER TABLE report_lines ADD CONSTRAINT report_lines_side_required CHECK (line_type NOT IN ('ACCOUNT','ACCOUNT_GROUP') OR balance_side IS NOT NULL)");
        DB::statement("ALTER TABLE report_lines ADD CONSTRAINT report_lines_cash_flow_check CHECK (cash_flow_category IS NULL OR cash_flow_category IN ('OPERATING','INVESTING','FINANCING','NON_CASH','UNCLASSIFIED'))");
        DB::statement("ALTER TABLE report_lines ADD CONSTRAINT report_lines_system_check CHECK ((line_type = 'SYSTEM') = (system_key IS NOT NULL)
            AND (system_key IS NULL OR system_key IN ('CURRENT_RESULT','NET_RESULT','CASH_OPENING','CASH_CLOSING','NET_CASH_CHANGE','UNCLASSIFIED_CASH_FLOW')))");
        DB::statement("ALTER TABLE report_lines ADD CONSTRAINT report_lines_operands_check CHECK ((line_type = 'CALCULATED_TOTAL') = (operands IS NOT NULL) AND (operands IS NULL OR jsonb_typeof(operands) = 'array'))");

        Schema::create('report_line_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('report_definition_id');
            $table->uuid('report_line_id');
            $table->uuid('account_id');
            $table->boolean('include_descendants')->default(false); // a mapped header account stands for every posting account below it
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'report_definition_id', 'report_line_id'])->references(['tenant_id', 'report_definition_id', 'id'])->on('report_lines')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['report_definition_id', 'account_id']); // an account sits on one line of a definition
            $table->index(['tenant_id', 'report_line_id']);
        });

        // Lines and mappings follow their definition: DRAFT only, and never moved to another definition.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION report_config_guard() RETURNS trigger AS $$
            DECLARE def_status text; def_id uuid;
            BEGIN
                def_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.report_definition_id ELSE NEW.report_definition_id END;
                IF TG_OP = 'UPDATE' AND NEW.report_definition_id <> OLD.report_definition_id THEN
                    RAISE EXCEPTION 'a report line or mapping cannot move to another definition' USING ERRCODE = '23514';
                END IF;
                SELECT status INTO def_status FROM report_definitions WHERE id = def_id;
                IF FOUND AND def_status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'a published report definition cannot change; create a new version' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER report_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON report_lines FOR EACH ROW EXECUTE FUNCTION report_config_guard()');
        DB::unprepared('CREATE TRIGGER report_line_mappings_guard BEFORE INSERT OR UPDATE OR DELETE ON report_line_mappings FOR EACH ROW EXECUTE FUNCTION report_config_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('report_line_mappings');
        Schema::dropIfExists('report_lines');
        Schema::dropIfExists('report_definitions');
        DB::unprepared('DROP FUNCTION IF EXISTS report_config_guard()');
        DB::unprepared('DROP FUNCTION IF EXISTS report_definitions_guard()');
    }
};
