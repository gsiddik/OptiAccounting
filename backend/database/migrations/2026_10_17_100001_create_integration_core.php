<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA6 batch A: the generic integration core (docs/architecture/INTEGRATION_ARCHITECTURE.md). Four tenant-owned tables, no product names and
 * no foreign key into any external system (external ids are plain strings):
 *
 *   integration_connections   one link to one source tenant of one source system: lifecycle, cutover and gap policy, pull cursor, credential
 *   external_events           the inbox: every envelope exactly once per (connection, source_system, event_id); the envelope is evidence and
 *                             never changes (trigger); only the processing state moves
 *   external_dimensions       references to the source's vehicles, work orders, vendors... (not copies)
 *   external_source_links     traceability between a journal / document of ours and the source document it came from
 *
 * The credential is written through an encrypted cast and is never serialized; the database only sees ciphertext.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('name', 120);
            $table->string('source_system', 40);
            $table->string('source_tenant_reference', 120)->nullable();
            $table->string('status', 14)->default('DRAFT');
            $table->string('base_url', 255)->nullable();
            $table->text('credential')->nullable();
            $table->string('cutover_policy', 24)->default('OPENING_BALANCE_ONLY');
            $table->date('cutover_date')->nullable();
            $table->string('gap_policy', 20)->default('REQUIRES_REVIEW');
            $table->unsignedSmallInteger('pull_interval_minutes')->default(5);
            $table->string('last_cursor', 255)->nullable();
            $table->timestampTz('last_pulled_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedSmallInteger('consecutive_failures')->default(0);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'source_system', 'source_tenant_reference']);
            $table->index(['status', 'tenant_id']);
        });
        DB::statement("ALTER TABLE integration_connections ADD CONSTRAINT integration_connections_status_check CHECK (status IN ('DRAFT','CONFIGURING','READY','ACTIVE','PAUSED','ERROR','DISCONNECTED'))");
        DB::statement("ALTER TABLE integration_connections ADD CONSTRAINT integration_connections_cutover_policy_check CHECK (cutover_policy IN ('OPENING_BALANCE_ONLY','START_FROM_CUTOVER_DATE','HISTORICAL_BACKFILL'))");
        DB::statement("ALTER TABLE integration_connections ADD CONSTRAINT integration_connections_gap_policy_check CHECK (gap_policy IN ('REQUIRES_REVIEW','IGNORE_GAP','BACKFILL_GAP','START_NEW_CUTOVER'))");
        DB::statement("ALTER TABLE integration_connections ADD CONSTRAINT integration_connections_source_system_check CHECK (source_system ~ '^[a-z][a-z0-9_]{1,39}\$')");
        DB::statement('ALTER TABLE integration_connections ADD CONSTRAINT integration_connections_interval_check CHECK (pull_interval_minutes BETWEEN 1 AND 1440)');

        Schema::create('external_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('connection_id');
            $table->string('source_system', 40);
            $table->string('event_id', 100);
            $table->string('event_type', 120);
            $table->string('schema_version', 10);
            $table->timestampTz('occurred_at');
            $table->unsignedBigInteger('sequence')->nullable();
            $table->jsonb('envelope');
            $table->char('content_hash', 64);
            $table->string('status', 12)->default('RECEIVED');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('next_attempt_at')->nullable();
            $table->string('last_error_code', 60)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->boolean('conflict')->default(false);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->uuid('resulting_journal_id')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'connection_id'])->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'resulting_journal_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->unique(['connection_id', 'source_system', 'event_id']);
            $table->index(['tenant_id', 'status', 'next_attempt_at']);
            $table->index(['tenant_id', 'connection_id', 'received_at']);
            $table->index(['tenant_id', 'event_type']);
        });
        DB::statement("ALTER TABLE external_events ADD CONSTRAINT external_events_status_check CHECK (status IN ('RECEIVED','VALIDATED','POSTED','NEEDS_REVIEW','REJECTED','FAILED','DEAD_LETTER'))");
        DB::statement("ALTER TABLE external_events ADD CONSTRAINT external_events_schema_version_check CHECK (schema_version ~ '^[0-9]+\\.[0-9]+\$')");
        DB::statement("ALTER TABLE external_events ADD CONSTRAINT external_events_posted_check CHECK (status <> 'POSTED' OR processed_at IS NOT NULL)");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION oa6_external_events_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'external_events rows are never removed' USING ERRCODE = '23514';
                END IF;
                IF NEW.tenant_id <> OLD.tenant_id OR NEW.connection_id <> OLD.connection_id OR NEW.source_system <> OLD.source_system
                   OR NEW.event_id <> OLD.event_id OR NEW.event_type <> OLD.event_type OR NEW.schema_version <> OLD.schema_version
                   OR NEW.occurred_at <> OLD.occurred_at OR NEW.envelope <> OLD.envelope OR NEW.content_hash <> OLD.content_hash THEN
                    RAISE EXCEPTION 'external_events: the received envelope is evidence and never changes' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER external_events_guard BEFORE UPDATE OR DELETE ON external_events FOR EACH ROW EXECUTE FUNCTION oa6_external_events_guard()');

        Schema::create('external_dimensions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('source_system', 40);
            $table->string('source_tenant_reference', 120);
            $table->string('entity_type', 40);
            $table->string('external_id', 120);
            $table->string('code', 80)->nullable();
            $table->string('name', 200)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'source_system', 'source_tenant_reference', 'entity_type', 'external_id'], 'external_dimensions_reference_unique');
            $table->index(['tenant_id', 'entity_type', 'code']);
        });

        Schema::create('external_source_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('journal_id')->nullable();
            $table->string('document_type', 40)->nullable();
            $table->uuid('document_id')->nullable();
            $table->string('source_system', 40);
            $table->string('source_type', 60);
            $table->string('source_id', 120);
            $table->string('source_document_number', 120)->nullable();
            $table->string('source_event_id', 100);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'journal_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->unique(['tenant_id', 'source_system', 'source_type', 'source_id', 'source_event_id'], 'external_source_links_source_unique');
            $table->index(['tenant_id', 'journal_id']);
            $table->index(['tenant_id', 'document_type', 'document_id']);
        });
        DB::statement('ALTER TABLE external_source_links ADD CONSTRAINT external_source_links_target_check CHECK (
            (journal_id IS NOT NULL OR document_id IS NOT NULL) AND ((document_type IS NULL) = (document_id IS NULL)))');
    }

    public function down(): void
    {
        Schema::dropIfExists('external_source_links');
        Schema::dropIfExists('external_dimensions');
        DB::unprepared('DROP TRIGGER IF EXISTS external_events_guard ON external_events');
        Schema::dropIfExists('external_events');
        DB::unprepared('DROP FUNCTION IF EXISTS oa6_external_events_guard()');
        Schema::dropIfExists('integration_connections');
    }
};
