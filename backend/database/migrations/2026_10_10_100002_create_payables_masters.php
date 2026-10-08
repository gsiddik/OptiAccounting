<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** OA2 batch A: payment terms, expense categories (configuration masters) and vendors. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_terms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 30);
            $table->string('name', 100);
            $table->string('term_type', 20); // NET_DAYS | END_OF_MONTH | CUSTOM (due date entered by hand)
            $table->unsignedSmallInteger('due_days')->nullable();
            $table->boolean('allows_due_date_override')->default(false);
            $table->string('description', 255)->nullable();
            $table->string('status', 10)->default('ACTIVE');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE payment_terms ADD CONSTRAINT payment_terms_type_check CHECK (term_type IN ('NET_DAYS','END_OF_MONTH','CUSTOM'))");
        DB::statement("ALTER TABLE payment_terms ADD CONSTRAINT payment_terms_days_check CHECK (
            (term_type = 'CUSTOM' AND due_days IS NULL) OR (term_type <> 'CUSTOM' AND due_days IS NOT NULL AND due_days <= 3650))");
        DB::statement("ALTER TABLE payment_terms ADD CONSTRAINT payment_terms_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");

        // Expense categories carry classification only; the account comes from a semantic role (tenant mapping) or a default account.
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('description', 255)->nullable();
            $table->string('account_role', 40)->nullable();
            $table->uuid('account_id')->nullable();
            $table->string('status', 10)->default('ACTIVE');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('account_role')->references('code')->on('account_roles')->restrictOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE expense_categories ADD CONSTRAINT expense_categories_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");

        Schema::create('vendors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 40);
            $table->string('name', 255);
            $table->string('legal_name', 255)->nullable();
            $table->string('status', 10)->default('ACTIVE');
            $table->string('contact_name', 150)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('address', 500)->nullable();
            $table->string('tax_id', 50)->nullable(); // identifier metadata only (NPWP); no tax logic in OA2
            $table->boolean('tax_registered')->default(false);
            // Financial profile: separate from identity, explicit and audited. Rules and mappings stay the accounting authority.
            $table->uuid('payment_term_id')->nullable();
            $table->char('default_currency', 3)->nullable();
            $table->uuid('payable_account_id')->nullable();
            $table->uuid('default_expense_account_id')->nullable();
            $table->string('external_source', 40)->nullable();
            $table->string('external_id', 100)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_term_id'])->references(['tenant_id', 'id'])->on('payment_terms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payable_account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'default_expense_account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE vendors ADD CONSTRAINT vendors_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");
        DB::statement("ALTER TABLE vendors ADD CONSTRAINT vendors_currency_check CHECK (default_currency IS NULL OR default_currency ~ '^[A-Z]{3}\$')");
        DB::statement('ALTER TABLE vendors ADD CONSTRAINT vendors_external_check CHECK ((external_source IS NULL) = (external_id IS NULL))');
        DB::statement('CREATE UNIQUE INDEX vendors_external_unique ON vendors (tenant_id, external_source, external_id) WHERE external_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('payment_terms');
    }
};
