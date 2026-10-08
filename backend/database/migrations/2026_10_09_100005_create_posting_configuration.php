<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA1 batches G-H: account roles, accounting event types, tenant account mappings, versioned posting rules
 * and the idempotent accounting event log used by the central posting engine.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_roles', function (Blueprint $table) {
            $table->string('code', 40)->primary();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->string('status', 10)->default('ACTIVE');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('accounting_event_types', function (Blueprint $table) {
            $table->string('code', 40)->primary();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->jsonb('components')->default('[]'); // the amount keys a payload of this event type carries (a rule line may name only these)
            $table->string('status', 10)->default('ACTIVE');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });

        Schema::create('account_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('account_role', 40);
            $table->uuid('account_id');
            $table->uuid('branch_id')->nullable(); // optional specificity: business unit > branch > tenant default
            $table->uuid('business_unit_id')->nullable();
            $table->string('status', 10)->default('ACTIVE');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('account_role')->references('code')->on('account_roles')->restrictOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'business_unit_id'])->references(['tenant_id', 'id'])->on('business_units')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE account_mappings ADD CONSTRAINT account_mappings_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");
        DB::statement("CREATE UNIQUE INDEX account_mappings_active_unique ON account_mappings (tenant_id, account_role,
            COALESCE(branch_id, '00000000-0000-0000-0000-000000000000'::uuid),
            COALESCE(business_unit_id, '00000000-0000-0000-0000-000000000000'::uuid)) WHERE status = 'ACTIVE'");

        Schema::create('posting_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 60);
            $table->unsignedInteger('version')->default(1);
            $table->string('event_type', 40);
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->string('status', 10)->default('DRAFT');
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->uuid('published_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('event_type')->references('code')->on('accounting_event_types')->restrictOnDelete();
            $table->foreign('published_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'code', 'version']);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE posting_rules ADD CONSTRAINT posting_rules_status_check CHECK (status IN ('DRAFT','PUBLISHED','ARCHIVED'))");
        DB::statement("ALTER TABLE posting_rules ADD CONSTRAINT posting_rules_published_check CHECK (status = 'DRAFT' OR effective_from IS NOT NULL)");
        DB::statement('ALTER TABLE posting_rules ADD CONSTRAINT posting_rules_window_check CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
        // The rule that applies to a posting date is unambiguous: published / archived windows of one event type never overlap.
        DB::statement("ALTER TABLE posting_rules ADD CONSTRAINT posting_rules_no_overlap EXCLUDE USING gist (
            tenant_id WITH =, event_type WITH =, daterange(effective_from, effective_to, '[]') WITH &&)
            WHERE (status IN ('PUBLISHED','ARCHIVED'))");

        Schema::create('posting_rule_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('posting_rule_id');
            $table->unsignedSmallInteger('line_number');
            $table->string('side', 6);
            $table->string('account_role', 40);
            $table->string('amount_key', 40); // names a component of the event payload; never an expression
            $table->boolean('skip_if_zero')->default(true);
            $table->string('description', 255)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'posting_rule_id'])->references(['tenant_id', 'id'])->on('posting_rules')->cascadeOnDelete();
            $table->foreign('account_role')->references('code')->on('account_roles')->restrictOnDelete();
            $table->unique(['posting_rule_id', 'line_number']);
        });
        DB::statement("ALTER TABLE posting_rule_lines ADD CONSTRAINT posting_rule_lines_side_check CHECK (side IN ('DEBIT','CREDIT'))");
        DB::statement("ALTER TABLE posting_rule_lines ADD CONSTRAINT posting_rule_lines_key_check CHECK (amount_key ~ '^[a-z][a-z0-9_]*\$')");

        // A published or archived rule is a historical fact: its lines never change.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION posting_rule_lines_guard() RETURNS trigger AS $$
            DECLARE st text;
            BEGIN
                SELECT status INTO st FROM posting_rules WHERE tenant_id = COALESCE(NEW.tenant_id, OLD.tenant_id) AND id = COALESCE(NEW.posting_rule_id, OLD.posting_rule_id);
                IF st IS NOT NULL AND st <> 'DRAFT' THEN
                    RAISE EXCEPTION 'the lines of a published posting rule cannot change; create a new version' USING ERRCODE = '23514';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER posting_rule_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON posting_rule_lines FOR EACH ROW EXECUTE FUNCTION posting_rule_lines_guard()');

        Schema::create('accounting_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('event_type', 40);
            $table->string('source_type', 40);
            $table->string('source_id', 64);
            $table->string('posting_purpose', 30)->default('POST');
            $table->string('status', 10)->default('PENDING');
            $table->date('posting_date');
            $table->jsonb('payload');
            $table->char('payload_hash', 64); // same source fact + different content is a conflict, not a silent replay
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('posting_rule_id')->nullable();
            $table->string('failure_code', 60)->nullable();
            $table->string('failure_message', 500)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('event_type')->references('code')->on('accounting_event_types')->restrictOnDelete();
            $table->foreign(['tenant_id', 'journal_entry_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'posting_rule_id'])->references(['tenant_id', 'id'])->on('posting_rules')->restrictOnDelete();
            $table->unique(['tenant_id', 'source_type', 'source_id', 'posting_purpose']); // a business fact posts once
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE accounting_events ADD CONSTRAINT accounting_events_status_check CHECK (status IN ('PENDING','POSTED','FAILED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_events');
        DB::unprepared('DROP TRIGGER IF EXISTS posting_rule_lines_guard ON posting_rule_lines');
        Schema::dropIfExists('posting_rule_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS posting_rule_lines_guard()');
        Schema::dropIfExists('posting_rules');
        Schema::dropIfExists('account_mappings');
        Schema::dropIfExists('accounting_event_types');
        Schema::dropIfExists('account_roles');
    }
};
