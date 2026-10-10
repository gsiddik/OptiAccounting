<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA3 batch A: the customer master. Payment terms are the OA2 table (generic: net days, end of month, custom), shared with vendors.
 * Identity and financial profile are separate columns groups; the profile only carries hints and overrides, the posting rules and
 * account mappings stay the accounting authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 40);
            $table->string('name', 255);
            $table->string('legal_name', 255)->nullable();
            $table->string('status', 10)->default('ACTIVE');
            $table->string('contact_name', 150)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address', 500)->nullable(); // billing address
            $table->string('tax_id', 50)->nullable(); // identifier metadata only (NPWP); no tax logic in OA3
            $table->boolean('tax_registered')->default(false);
            // Financial profile: explicit, validated and audited. Rules and mappings stay the accounting authority.
            $table->uuid('payment_term_id')->nullable();
            $table->char('default_currency', 3)->nullable();
            $table->uuid('receivable_account_id')->nullable();
            $table->uuid('default_revenue_account_id')->nullable();
            $table->decimal('credit_limit', 20, 4)->nullable(); // metadata foundation: shown, never enforced in OA3
            $table->string('external_source', 40)->nullable();
            $table->string('external_id', 100)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_term_id'])->references(['tenant_id', 'id'])->on('payment_terms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'receivable_account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'default_revenue_account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_currency_check CHECK (default_currency IS NULL OR default_currency ~ '^[A-Z]{3}\$')");
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_credit_limit_check CHECK (credit_limit IS NULL OR credit_limit >= 0)');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_external_check CHECK ((external_source IS NULL) = (external_id IS NULL))');
        DB::statement('CREATE UNIQUE INDEX customers_external_unique ON customers (tenant_id, external_source, external_id) WHERE external_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
