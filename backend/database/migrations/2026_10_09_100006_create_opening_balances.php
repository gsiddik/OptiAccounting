<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** OA1 batch I: the opening balance is a journal-backed document (type OPENING), not a table of balances. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->date('cutover_date');
            $table->string('status', 10)->default('DRAFT');
            $table->uuid('journal_entry_id');
            $table->string('reference', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'journal_entry_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('posted_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique('journal_entry_id');
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE opening_balances ADD CONSTRAINT opening_balances_status_check CHECK (status IN ('DRAFT','POSTED','REVERSED','CANCELLED'))");
        // One live opening balance per tenant: no accidental second initialization.
        DB::statement("CREATE UNIQUE INDEX opening_balances_one_live ON opening_balances (tenant_id) WHERE status IN ('DRAFT','POSTED')");
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
