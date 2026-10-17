<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ecosystem audit A19 / A17, consumer side (additive; nothing existing is rewritten). What the last contact with the source's feed said,
 * kept apart from the connection's lifecycle status so that three different situations are never shown as one "error":
 *
 *   feed_access  OK           the last pull was accepted
 *                REFUSED      the source (or OptiEntry's own pairing check) refused to serve this organization: credential revoked or unknown,
 *                             scope missing, tenant not paired / pairing changed, a subscription or connector entitlement gone. The connection
 *                             stays ACTIVE (the history is kept and the pull resumes by itself when the cause is lifted) but pulls back off
 *                             and the failure is not counted towards ERROR
 *                UNAVAILABLE  the source or its governance could not answer (network, 5xx, 429, 503 FEED_GOVERNANCE_UNAVAILABLE)
 *                INVALID      the source answered, but not with the agreed feed (malformed page, cursor ahead of the source)
 *                UNKNOWN      nothing pulled yet
 *   feed_access_code / feed_access_since / feed_checked_at / feed_next_attempt_at   which refusal, since when, last check, back-off
 *   paired_nexus_tenant_id   the OptiNexus tenant the last accepted page was verified for (information; the authority is OptiNexus)
 *   source_high_water(_at)   the newest committed sequence the source stated and when, so the lag and a source that fell behind the cursor show
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE integration_connections ADD COLUMN feed_access varchar(12) NOT NULL DEFAULT 'UNKNOWN'");
        DB::statement('ALTER TABLE integration_connections ADD COLUMN feed_access_code varchar(60) NULL');
        DB::statement('ALTER TABLE integration_connections ADD COLUMN feed_access_since timestamptz NULL');
        DB::statement('ALTER TABLE integration_connections ADD COLUMN feed_checked_at timestamptz NULL');
        DB::statement('ALTER TABLE integration_connections ADD COLUMN feed_next_attempt_at timestamptz NULL');
        DB::statement('ALTER TABLE integration_connections ADD COLUMN paired_nexus_tenant_id varchar(64) NULL');
        DB::statement('ALTER TABLE integration_connections ADD COLUMN source_high_water bigint NULL');
        DB::statement('ALTER TABLE integration_connections ADD COLUMN source_high_water_at timestamptz NULL');
        DB::statement("ALTER TABLE integration_connections ADD CONSTRAINT integration_connections_feed_access_check CHECK (feed_access IN ('UNKNOWN','OK','REFUSED','UNAVAILABLE','INVALID'))");
        DB::statement('ALTER TABLE integration_connections ADD CONSTRAINT integration_connections_high_water_check CHECK (source_high_water IS NULL OR source_high_water >= 0)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE integration_connections DROP CONSTRAINT IF EXISTS integration_connections_high_water_check');
        DB::statement('ALTER TABLE integration_connections DROP CONSTRAINT IF EXISTS integration_connections_feed_access_check');
        foreach (['source_high_water_at', 'source_high_water', 'paired_nexus_tenant_id', 'feed_next_attempt_at', 'feed_checked_at', 'feed_access_since', 'feed_access_code', 'feed_access'] as $column) {
            DB::statement("ALTER TABLE integration_connections DROP COLUMN IF EXISTS {$column}");
        }
    }
};
