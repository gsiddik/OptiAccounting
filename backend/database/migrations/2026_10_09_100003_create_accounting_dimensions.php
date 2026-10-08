<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** OA1 batch D: dimension type catalog and tenant-owned cost centers (branch / business unit are OA0 tables). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dimension_types', function (Blueprint $table) {
            $table->string('code', 40)->primary();
            $table->string('name');
            $table->string('kind', 10); // INTERNAL (owned here) | EXTERNAL (referenced by key, registered by an integration phase)
            $table->string('status', 10)->default('ACTIVE');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE dimension_types ADD CONSTRAINT dimension_types_kind_check CHECK (kind IN ('INTERNAL','EXTERNAL'))");

        Schema::create('cost_centers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 30);
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('business_unit_id')->nullable();
            $table->string('status', 10)->default('ACTIVE');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'business_unit_id'])->references(['tenant_id', 'id'])->on('business_units')->restrictOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
        });
        DB::statement("ALTER TABLE cost_centers ADD CONSTRAINT cost_centers_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_centers');
        Schema::dropIfExists('dimension_types');
    }
};
