<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email');
            $table->string('password')->nullable(); // null for identities managed by OptiNexus
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampTz('last_login_at')->nullable();
            $table->string('optinexus_subject')->nullable()->unique();
            $table->timestampsTz();
        });
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('ACTIVE','INACTIVE','SUSPENDED'))");

        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->string('timezone', 64)->default('Asia/Jakarta');
            $table->string('default_locale', 10)->default('id');
            $table->char('default_currency', 3)->default('IDR');
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->string('optinexus_tenant_id')->nullable()->unique();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_status_check CHECK (status IN ('DRAFT','ACTIVE','SUSPENDED','INACTIVE','TERMINATED'))");
        DB::statement("ALTER TABLE tenants ADD CONSTRAINT tenants_code_check CHECK (code ~ '^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$')");

        Schema::create('tenant_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->string('status', 20)->default('INVITED');
            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'id']); // target of composite FKs from tenant-owned tables
            $table->index(['user_id', 'status']);
        });
        DB::statement("ALTER TABLE tenant_users ADD CONSTRAINT tenant_users_status_check CHECK (status IN ('INVITED','ACTIVE','SUSPENDED','INACTIVE'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
        Schema::dropIfExists('users');
    }
};
