<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->boolean('commercially_available')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE modules ADD CONSTRAINT modules_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");
        DB::statement("ALTER TABLE modules ADD CONSTRAINT modules_code_check CHECK (code ~ '^[A-Z][A-Z0-9_]*$')");

        Schema::create('module_dependencies', function (Blueprint $table) {
            $table->uuid('module_id');
            $table->uuid('requires_module_id');
            $table->timestampsTz();

            $table->primary(['module_id', 'requires_module_id']);
            $table->foreign('module_id')->references('id')->on('modules')->cascadeOnDelete();
            $table->foreign('requires_module_id')->references('id')->on('modules')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE module_dependencies ADD CONSTRAINT module_dependencies_no_self_check CHECK (module_id <> requires_module_id)');

        Schema::create('features', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('module_id');
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestampsTz();

            $table->foreign('module_id')->references('id')->on('modules')->restrictOnDelete();
            $table->index('module_id');
        });
        DB::statement("ALTER TABLE features ADD CONSTRAINT features_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");
        DB::statement("ALTER TABLE features ADD CONSTRAINT features_code_check CHECK (code ~ '^[A-Z][A-Z0-9_]*$')");

        Schema::create('bundles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE bundles ADD CONSTRAINT bundles_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");

        Schema::create('bundle_modules', function (Blueprint $table) {
            $table->uuid('bundle_id');
            $table->uuid('module_id');
            $table->timestampsTz();

            $table->primary(['bundle_id', 'module_id']);
            $table->foreign('bundle_id')->references('id')->on('bundles')->cascadeOnDelete();
            $table->foreign('module_id')->references('id')->on('modules')->restrictOnDelete();
        });

        Schema::create('bundle_capacities', function (Blueprint $table) {
            $table->uuid('bundle_id');
            $table->string('limit_code', 60);
            $table->unsignedInteger('limit_value')->nullable(); // null = unlimited
            $table->timestampsTz();

            $table->primary(['bundle_id', 'limit_code']);
            $table->foreign('bundle_id')->references('id')->on('bundles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_capacities');
        Schema::dropIfExists('bundle_modules');
        Schema::dropIfExists('bundles');
        Schema::dropIfExists('features');
        Schema::dropIfExists('module_dependencies');
        Schema::dropIfExists('modules');
    }
};
