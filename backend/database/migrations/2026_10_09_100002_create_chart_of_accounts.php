<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** OA1 batch C: tenant-owned hierarchical chart of accounts and platform COA templates (copied, never referenced). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 30);
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->uuid('parent_id')->nullable();
            $table->string('account_type', 20);
            $table->string('normal_balance', 6); // explicit: contra accounts break any type-based default
            $table->boolean('is_postable')->default(true); // false = header / group account
            $table->boolean('is_control')->default(false); // control accounts refuse manual journals (subledger postings only)
            $table->char('currency', 3)->nullable(); // currency restriction foundation (OA4 relaxes it)
            $table->string('status', 10)->default('ACTIVE');
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'parent_id']);
        });
        // Self-reference after the (tenant_id, id) unique key exists.
        Schema::table('accounts', fn (Blueprint $table) => $table->foreign(['tenant_id', 'parent_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete());
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_type_check CHECK (account_type IN ('ASSET','LIABILITY','EQUITY','REVENUE','EXPENSE'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_normal_balance_check CHECK (normal_balance IN ('DEBIT','CREDIT'))");
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");
        DB::statement('ALTER TABLE accounts ADD CONSTRAINT accounts_not_own_parent CHECK (parent_id IS NULL OR parent_id <> id)');
        DB::statement('ALTER TABLE accounts ADD CONSTRAINT accounts_control_postable CHECK (NOT is_control OR is_postable)');
        DB::statement("ALTER TABLE accounts ADD CONSTRAINT accounts_currency_check CHECK (currency IS NULL OR currency ~ '^[A-Z]{3}\$')");

        // Hierarchy rules the application also validates: a parent is a header of the same type, and no cycles.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION accounts_guard() RETURNS trigger AS $$
            DECLARE parent accounts%ROWTYPE;
            BEGIN
                IF NEW.parent_id IS NOT NULL THEN
                    SELECT * INTO parent FROM accounts WHERE tenant_id = NEW.tenant_id AND id = NEW.parent_id;
                    IF parent.is_postable THEN
                        RAISE EXCEPTION 'account % cannot be a parent: it is a posting account', parent.code USING ERRCODE = '23514';
                    END IF;
                    IF parent.account_type <> NEW.account_type THEN
                        RAISE EXCEPTION 'account % must have the account type of its parent', NEW.code USING ERRCODE = '23514';
                    END IF;
                    IF TG_OP = 'UPDATE' AND NEW.parent_id IS DISTINCT FROM OLD.parent_id THEN
                        IF EXISTS (
                            WITH RECURSIVE up(id, parent_id) AS (
                                SELECT id, parent_id FROM accounts WHERE tenant_id = NEW.tenant_id AND id = NEW.parent_id
                                UNION ALL
                                SELECT a.id, a.parent_id FROM accounts a JOIN up ON a.id = up.parent_id AND a.tenant_id = NEW.tenant_id)
                            SELECT 1 FROM up WHERE id = NEW.id) THEN
                            RAISE EXCEPTION 'account hierarchy cannot contain a cycle' USING ERRCODE = '23514';
                        END IF;
                    END IF;
                END IF;
                IF TG_OP = 'UPDATE' AND NEW.is_postable AND EXISTS (SELECT 1 FROM accounts WHERE tenant_id = NEW.tenant_id AND parent_id = NEW.id) THEN
                    RAISE EXCEPTION 'account % has children and must stay a header', NEW.code USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER accounts_guard BEFORE INSERT OR UPDATE ON accounts FOR EACH ROW EXECUTE FUNCTION accounts_guard()');

        // Platform-owned templates (no tenant_id); applying one copies rows into `accounts`.
        Schema::create('coa_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->string('status', 10)->default('ACTIVE');
            $table->timestampsTz();
        });

        Schema::create('coa_template_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('coa_template_id');
            $table->string('code', 30);
            $table->string('name');
            $table->string('parent_code', 30)->nullable();
            $table->string('account_type', 20);
            $table->string('normal_balance', 6);
            $table->boolean('is_postable')->default(true);
            $table->boolean('is_control')->default(false);
            $table->string('account_role', 40)->nullable(); // suggested mapping applied together with the accounts
            $table->unsignedInteger('sort_order')->default(0);

            $table->foreign('coa_template_id')->references('id')->on('coa_templates')->cascadeOnDelete();
            $table->unique(['coa_template_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coa_template_accounts');
        Schema::dropIfExists('coa_templates');
        DB::unprepared('DROP TRIGGER IF EXISTS accounts_guard ON accounts');
        DB::unprepared('DROP FUNCTION IF EXISTS accounts_guard()');
        Schema::dropIfExists('accounts');
    }
};
