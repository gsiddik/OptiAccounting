<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable(); // null = platform-level event
            $table->uuid('actor_user_id')->nullable();
            $table->string('actor_scope', 10)->nullable(); // platform | tenant | system
            $table->string('action', 100);
            $table->string('resource_type', 100);
            $table->string('resource_id', 64)->nullable();
            $table->jsonb('changes')->nullable(); // {before:{}, after:{}} with secrets removed
            $table->jsonb('context')->nullable(); // request id, ip, user agent
            $table->timestampTz('occurred_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['resource_type', 'resource_id']);
            $table->index(['actor_user_id', 'occurred_at']);
        });

        // Append-only: rows can never be changed or removed through SQL.
        DB::unprepared("CREATE OR REPLACE FUNCTION audit_logs_immutable() RETURNS trigger AS \$\$
            BEGIN RAISE EXCEPTION 'audit_logs is append-only'; END; \$\$ LANGUAGE plpgsql");
        DB::unprepared('CREATE TRIGGER audit_logs_no_update_delete BEFORE UPDATE OR DELETE ON audit_logs
            FOR EACH ROW EXECUTE FUNCTION audit_logs_immutable()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS audit_logs_no_update_delete ON audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS audit_logs_immutable()');
        Schema::dropIfExists('audit_logs');
    }
};
