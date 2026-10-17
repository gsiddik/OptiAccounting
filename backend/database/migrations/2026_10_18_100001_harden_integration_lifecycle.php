<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ecosystem audit A17/A18/A20/A21/A27 (additive; nothing merged is rewritten):
 *
 *   integration_connections.lifecycle_version   bumped by every lifecycle move, so an in-flight pull can tell that a pause / disconnect /
 *                                               reconnect happened while its HTTP call was running (A20)
 *   integration_gaps                            one row per pause or disconnect of a connection: the source sequence (cursor) when it stopped, and, once
 *                                               the connection is switched on again, the source's head at that moment (`ceiling_sequence`).
 *                                               Events in (sequence_at_open, ceiling_sequence] arrived while nobody was listening and are
 *                                               handled by the gap policy instead of being posted automatically (A18)
 *   external_events.gap_id                      the gap an event belongs to
 *   external_events.accounting_outcome          what processing did to the books, apart from the processing status (A27)
 *   external_events.document_status             status of the accounting document at the time of processing (A27)
 *   external_event_conflicts                    evidence of every payload that arrived with a known event id but different content; an
 *                                               unposted event with an open conflict is held until a person decides (A21)
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE integration_connections ADD COLUMN lifecycle_version integer NOT NULL DEFAULT 1');

        Schema::create('integration_gaps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('connection_id');
            $table->string('open_reason', 14);
            $table->string('policy', 20);
            $table->string('status', 8)->default('OPEN');
            $table->unsignedBigInteger('sequence_at_open')->default(0);
            $table->unsignedBigInteger('ceiling_sequence')->nullable();
            $table->date('new_cutover_date')->nullable();
            $table->timestampTz('opened_at');
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'connection_id'])->references(['tenant_id', 'id'])->on('integration_connections')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['connection_id', 'status']);
        });
        DB::statement("ALTER TABLE integration_gaps ADD CONSTRAINT integration_gaps_status_check CHECK (status IN ('OPEN','CLOSED'))");
        DB::statement("ALTER TABLE integration_gaps ADD CONSTRAINT integration_gaps_reason_check CHECK (open_reason IN ('PAUSED','DISCONNECTED'))");
        DB::statement("ALTER TABLE integration_gaps ADD CONSTRAINT integration_gaps_policy_check CHECK (policy IN ('REQUIRES_REVIEW','IGNORE_GAP','BACKFILL_GAP','START_NEW_CUTOVER'))");
        DB::statement("ALTER TABLE integration_gaps ADD CONSTRAINT integration_gaps_closed_check CHECK ((status = 'CLOSED') = (closed_at IS NOT NULL AND ceiling_sequence IS NOT NULL))");
        // one open gap per connection: a second pause cannot start while the first is open
        DB::statement("CREATE UNIQUE INDEX integration_gaps_one_open ON integration_gaps (connection_id) WHERE status = 'OPEN'");

        DB::statement('ALTER TABLE external_events ADD COLUMN gap_id uuid NULL');
        DB::statement('ALTER TABLE external_events ADD COLUMN accounting_outcome varchar(24) NULL');
        DB::statement('ALTER TABLE external_events ADD COLUMN document_status varchar(24) NULL');
        DB::statement('ALTER TABLE external_events ADD CONSTRAINT external_events_gap_fk FOREIGN KEY (tenant_id, gap_id) REFERENCES integration_gaps (tenant_id, id) ON DELETE RESTRICT');
        DB::statement("ALTER TABLE external_events ADD CONSTRAINT external_events_outcome_check CHECK (accounting_outcome IS NULL OR accounting_outcome IN ('JOURNAL_POSTED','AWAITING_APPROVAL','DRAFT_DOCUMENT','INFORMATIONAL','NO_EFFECT'))");
        DB::statement('CREATE INDEX external_events_gap_idx ON external_events (gap_id) WHERE gap_id IS NOT NULL');
        // rows processed before this release keep NULL: the API derives the outcome for them (a journal means JOURNAL_POSTED); no existing row is rewritten

        Schema::create('external_event_conflicts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('external_event_id');
            $table->char('received_hash', 64);
            $table->jsonb('envelope');
            $table->timestampTz('received_at');
            $table->string('resolution', 14)->nullable();
            $table->string('resolution_reason', 500)->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('external_event_id')->references('id')->on('external_events')->restrictOnDelete();
            $table->unique(['external_event_id', 'received_hash']);
            $table->index(['tenant_id', 'resolved_at']);
        });
        DB::statement("ALTER TABLE external_event_conflicts ADD CONSTRAINT external_event_conflicts_resolution_check CHECK (resolution IS NULL OR resolution IN ('USE_ORIGINAL','REJECT'))");
        DB::statement('ALTER TABLE external_event_conflicts ADD CONSTRAINT external_event_conflicts_resolved_check CHECK ((resolution IS NULL) = (resolved_at IS NULL))');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION oa6_event_conflicts_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'external_event_conflicts rows are never removed' USING ERRCODE = '23514';
                END IF;
                IF NEW.tenant_id <> OLD.tenant_id OR NEW.external_event_id <> OLD.external_event_id OR NEW.received_hash <> OLD.received_hash
                   OR NEW.envelope <> OLD.envelope OR NEW.received_at <> OLD.received_at THEN
                    RAISE EXCEPTION 'external_event_conflicts: the conflicting payload is evidence and never changes' USING ERRCODE = '23514';
                END IF;
                IF OLD.resolved_at IS NOT NULL THEN
                    RAISE EXCEPTION 'external_event_conflicts: a resolved conflict is final' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
        DB::unprepared('CREATE TRIGGER external_event_conflicts_guard BEFORE UPDATE OR DELETE ON external_event_conflicts FOR EACH ROW EXECUTE FUNCTION oa6_event_conflicts_guard()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS external_event_conflicts_guard ON external_event_conflicts');
        Schema::dropIfExists('external_event_conflicts');
        DB::unprepared('DROP FUNCTION IF EXISTS oa6_event_conflicts_guard()');
        DB::statement('ALTER TABLE external_events DROP CONSTRAINT IF EXISTS external_events_gap_fk');
        DB::statement('ALTER TABLE external_events DROP CONSTRAINT IF EXISTS external_events_outcome_check');
        DB::statement('ALTER TABLE external_events DROP COLUMN IF EXISTS document_status');
        DB::statement('ALTER TABLE external_events DROP COLUMN IF EXISTS accounting_outcome');
        DB::statement('ALTER TABLE external_events DROP COLUMN IF EXISTS gap_id');
        Schema::dropIfExists('integration_gaps');
        DB::statement('ALTER TABLE integration_connections DROP COLUMN IF EXISTS lifecycle_version');
    }
};
