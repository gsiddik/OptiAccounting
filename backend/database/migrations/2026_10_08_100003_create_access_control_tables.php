<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique(); // resource.action, e.g. access.role.manage
            $table->string('scope', 10);
            $table->string('group', 100);
            $table->string('description')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'scope']);
        });
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_scope_check CHECK (scope IN ('platform','tenant'))");
        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_code_check CHECK (code ~ '^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)+$')");

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('scope', 10);
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['id', 'scope']);
        });
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_scope_check CHECK (scope IN ('platform','tenant'))");
        // Platform roles have no tenant; tenant roles always belong to exactly one tenant.
        DB::statement("ALTER TABLE roles ADD CONSTRAINT roles_scope_tenant_check CHECK ((scope = 'platform') = (tenant_id IS NULL))");
        DB::statement('CREATE UNIQUE INDEX roles_tenant_name_unique ON roles (tenant_id, lower(name)) WHERE tenant_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX roles_platform_name_unique ON roles (lower(name)) WHERE tenant_id IS NULL');

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->string('scope', 10);
            $table->timestampsTz();

            $table->primary(['role_id', 'permission_id']);
            // A role can only hold permissions of its own scope (tenant roles never get platform permissions).
            $table->foreign(['role_id', 'scope'])->references(['id', 'scope'])->on('roles')->cascadeOnDelete();
            $table->foreign(['permission_id', 'scope'])->references(['id', 'scope'])->on('permissions')->cascadeOnDelete();
        });

        Schema::create('platform_role_assignments', function (Blueprint $table) {
            $table->uuid('user_id');
            $table->uuid('role_id');
            $table->string('scope', 10)->default('platform');
            $table->timestampsTz();

            $table->primary(['user_id', 'role_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign(['role_id', 'scope'])->references(['id', 'scope'])->on('roles')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE platform_role_assignments ADD CONSTRAINT platform_role_assignments_scope_check CHECK (scope = 'platform')");

        Schema::create('tenant_user_roles', function (Blueprint $table) {
            $table->uuid('tenant_id');
            $table->uuid('tenant_user_id');
            $table->uuid('role_id');
            $table->timestampsTz();

            $table->primary(['tenant_user_id', 'role_id']);
            // Same-tenant guarantees at the database level.
            $table->foreign(['tenant_id', 'tenant_user_id'])->references(['tenant_id', 'id'])->on('tenant_users')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'role_id'])->references(['tenant_id', 'id'])->on('roles')->cascadeOnDelete();
            $table->index(['tenant_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_user_roles');
        Schema::dropIfExists('platform_role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
