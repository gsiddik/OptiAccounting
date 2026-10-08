<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA0-N (additive): markers that let an OptiNexus sign-in undo exactly what an OptiNexus event switched
 * off, the projection timestamp, and the transactional outbox (docs/architecture/OPTINEXUS_ADAPTER.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('optinexus_deactivated_at')->nullable();
        });
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->timestampTz('optinexus_deactivated_at')->nullable();
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->timestampTz('optinexus_deactivated_at')->nullable();
            $table->timestampTz('optinexus_synced_at')->nullable();
        });

        // Business and security events written in the same transaction as the change they describe.
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary(); // also the idempotency key (event_id) towards the receiver
            $table->uuid('tenant_id')->nullable();
            $table->string('channel', 20)->default('OPTINEXUS');
            $table->string('kind', 10);
            $table->string('event_type', 120);
            $table->string('schema_version', 20)->default('1');
            $table->jsonb('payload');
            $table->timestampTz('occurred_at');
            $table->string('status', 12)->default('PENDING');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('last_attempted_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->index(['channel', 'status', 'last_attempted_at']);
            $table->index('tenant_id');
        });
        DB::statement("ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_kind_check CHECK (kind IN ('EVENT','AUDIT'))");
        DB::statement("ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_status_check CHECK (status IN ('PENDING','DELIVERED','FAILED'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['optinexus_deactivated_at', 'optinexus_synced_at']);
        });
        Schema::table('tenant_users', function (Blueprint $table) {
            $table->dropColumn('optinexus_deactivated_at');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('optinexus_deactivated_at');
        });
    }
};
