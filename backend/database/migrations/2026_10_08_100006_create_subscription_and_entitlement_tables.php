<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('bundle_id')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->string('source', 20)->default('LOCAL');
            $table->string('external_reference')->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('bundle_id')->references('id')->on('bundles')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_status_check CHECK (status IN ('PENDING','ACTIVE','PAST_DUE','SUSPENDED','EXPIRED','CANCELLED'))");
        DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_source_check CHECK (source IN ('LOCAL','OPTINEXUS'))");
        DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_dates_check CHECK (ends_on IS NULL OR ends_on >= starts_on)');
        // At most one live subscription per tenant; ended ones stay as history.
        DB::statement("CREATE UNIQUE INDEX subscriptions_one_live_per_tenant ON subscriptions (tenant_id) WHERE status IN ('PENDING','ACTIVE','PAST_DUE','SUSPENDED')");

        Schema::create('tenant_module_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('module_id');
            $table->string('state', 20)->default('ACTIVE');
            $table->string('source', 20);
            $table->uuid('subscription_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable(); // inclusive last usable day; null = open-ended
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('module_id')->references('id')->on('modules')->restrictOnDelete();
            $table->foreign(['tenant_id', 'subscription_id'])->references(['tenant_id', 'id'])->on('subscriptions')->restrictOnDelete();
            $table->index(['tenant_id', 'module_id']);
        });
        DB::statement("ALTER TABLE tenant_module_entitlements ADD CONSTRAINT tme_state_check CHECK (state IN ('ACTIVE','READ_ONLY','SUSPENDED','DISABLED'))");
        DB::statement("ALTER TABLE tenant_module_entitlements ADD CONSTRAINT tme_source_check CHECK (source IN ('BUNDLE','ADD_ON','CUSTOM_CONTRACT','MANUAL_OVERRIDE','OPTINEXUS'))");
        DB::statement('ALTER TABLE tenant_module_entitlements ADD CONSTRAINT tme_dates_check CHECK (effective_until IS NULL OR effective_until >= effective_from)');
        // One entitlement identity per tenant+module at any date: windows must not overlap.
        DB::statement("ALTER TABLE tenant_module_entitlements ADD CONSTRAINT tme_no_overlap EXCLUDE USING gist (
            tenant_id WITH =, module_id WITH =, daterange(effective_from, effective_until, '[]') WITH &&)");

        Schema::create('tenant_feature_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('feature_id');
            $table->string('state', 20)->default('ACTIVE');
            $table->string('source', 20);
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('feature_id')->references('id')->on('features')->restrictOnDelete();
            $table->index(['tenant_id', 'feature_id']);
        });
        DB::statement("ALTER TABLE tenant_feature_entitlements ADD CONSTRAINT tfe_state_check CHECK (state IN ('ACTIVE','DISABLED'))");
        DB::statement("ALTER TABLE tenant_feature_entitlements ADD CONSTRAINT tfe_source_check CHECK (source IN ('BUNDLE','ADD_ON','CUSTOM_CONTRACT','MANUAL_OVERRIDE','OPTINEXUS'))");
        DB::statement('ALTER TABLE tenant_feature_entitlements ADD CONSTRAINT tfe_dates_check CHECK (effective_until IS NULL OR effective_until >= effective_from)');
        DB::statement("ALTER TABLE tenant_feature_entitlements ADD CONSTRAINT tfe_no_overlap EXCLUDE USING gist (
            tenant_id WITH =, feature_id WITH =, daterange(effective_from, effective_until, '[]') WITH &&)");

        Schema::create('tenant_capacity_limits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('limit_code', 60);
            $table->unsignedInteger('limit_value')->nullable(); // null = unlimited
            $table->string('source', 20);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'limit_code']);
        });
        DB::statement("ALTER TABLE tenant_capacity_limits ADD CONSTRAINT tcl_source_check CHECK (source IN ('BUNDLE','ADD_ON','CUSTOM_CONTRACT','MANUAL_OVERRIDE','OPTINEXUS'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_capacity_limits');
        Schema::dropIfExists('tenant_feature_entitlements');
        Schema::dropIfExists('tenant_module_entitlements');
        Schema::dropIfExists('subscriptions');
    }
};
