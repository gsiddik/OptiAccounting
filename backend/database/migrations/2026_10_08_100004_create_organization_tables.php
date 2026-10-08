<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 30);
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE branches ADD CONSTRAINT branches_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");

        Schema::create('business_units', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('branch_id')->nullable(); // a business unit may exist without a branch
            $table->string('code', 30);
            $table->string('name');
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE business_units ADD CONSTRAINT business_units_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");

        Schema::create('data_scopes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tenant_user_id');
            $table->string('scope_type', 20);
            $table->uuid('branch_id')->nullable();
            $table->uuid('business_unit_id')->nullable();
            $table->timestampsTz();

            $table->foreign(['tenant_id', 'tenant_user_id'])->references(['tenant_id', 'id'])->on('tenant_users')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'business_unit_id'])->references(['tenant_id', 'id'])->on('business_units')->cascadeOnDelete();
            $table->index(['tenant_id', 'tenant_user_id']);
        });
        // New dimensions (COST_CENTER, WORKSHOP, ...) are added with an additive column + constraint update.
        DB::statement("ALTER TABLE data_scopes ADD CONSTRAINT data_scopes_shape_check CHECK (
            (scope_type IN ('TENANT','OWN') AND branch_id IS NULL AND business_unit_id IS NULL)
            OR (scope_type = 'BRANCH' AND branch_id IS NOT NULL AND business_unit_id IS NULL)
            OR (scope_type = 'BUSINESS_UNIT' AND business_unit_id IS NOT NULL AND branch_id IS NULL))");
        DB::statement("CREATE UNIQUE INDEX data_scopes_unique ON data_scopes (tenant_user_id, scope_type,
            COALESCE(branch_id, '00000000-0000-0000-0000-000000000000'::uuid),
            COALESCE(business_unit_id, '00000000-0000-0000-0000-000000000000'::uuid))");
    }

    public function down(): void
    {
        Schema::dropIfExists('data_scopes');
        Schema::dropIfExists('business_units');
        Schema::dropIfExists('branches');
    }
};
