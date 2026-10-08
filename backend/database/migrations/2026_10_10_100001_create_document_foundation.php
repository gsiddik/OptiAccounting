<?php

use App\Support\Database\DocumentGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** OA2 foundation: the shared document history table and the database guards every OA2 source document attaches. */
return new class extends Migration
{
    public function up(): void
    {
        DocumentGuards::installFunctions();

        // One append-only history for every OA2 document (vendor invoice, payment, expense, cash transaction).
        Schema::create('document_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('document_type', 30);
            $table->uuid('document_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->uuid('actor_user_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestampTz('occurred_at');

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'document_type', 'document_id', 'occurred_at'], 'document_transitions_lookup');
        });
        DocumentGuards::appendOnly('document_transitions');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS document_transitions_append_only ON document_transitions');
        Schema::dropIfExists('document_transitions');
        DB::unprepared('DROP FUNCTION IF EXISTS oa2_append_only()');
        DB::unprepared('DROP FUNCTION IF EXISTS oa2_lines_guard()');
        DB::unprepared('DROP FUNCTION IF EXISTS oa2_document_guard()');
    }
};
