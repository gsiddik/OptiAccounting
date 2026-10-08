<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA2 batch G: cash and bank accounts. An operational account mapped to exactly one GL account; its book balance is always read from
 * posted journal lines, never stored. Only the masked bank account number is kept (the full number never reaches the database).
 * The GL mapping of an account that any document has used cannot change: history stays readable and reconciliations stay unambiguous.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('kind', 10); // CASH | BANK
            $table->string('status', 10)->default('ACTIVE');
            $table->char('currency', 3);
            $table->uuid('account_id'); // the mapped GL account
            $table->string('bank_name', 100)->nullable();
            $table->string('account_holder', 150)->nullable();
            $table->string('account_number_masked', 30)->nullable(); // e.g. ******1234; never the full number
            $table->uuid('branch_id')->nullable();
            $table->uuid('business_unit_id')->nullable();
            $table->string('notes', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'business_unit_id'])->references(['tenant_id', 'id'])->on('business_units')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement("ALTER TABLE cash_bank_accounts ADD CONSTRAINT cash_bank_accounts_kind_check CHECK (kind IN ('CASH','BANK'))");
        DB::statement("ALTER TABLE cash_bank_accounts ADD CONSTRAINT cash_bank_accounts_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");
        DB::statement("ALTER TABLE cash_bank_accounts ADD CONSTRAINT cash_bank_accounts_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        // A cash account carries no bank data; a bank account names its bank.
        DB::statement("ALTER TABLE cash_bank_accounts ADD CONSTRAINT cash_bank_accounts_bank_data_check CHECK (
            (kind = 'BANK' AND bank_name IS NOT NULL) OR (kind = 'CASH' AND bank_name IS NULL AND account_holder IS NULL AND account_number_masked IS NULL))");
        // Sensitive data: no run of five or more digits can be stored, whatever wrote the row.
        DB::statement("ALTER TABLE cash_bank_accounts ADD CONSTRAINT cash_bank_accounts_masked_check CHECK (account_number_masked IS NULL OR account_number_masked !~ '[0-9]{5,}')");
        // One active cash/bank account per GL account, so the book balance of a GL account belongs to one operational account.
        DB::statement("CREATE UNIQUE INDEX cash_bank_accounts_active_gl_unique ON cash_bank_accounts (tenant_id, account_id) WHERE status = 'ACTIVE'");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_bank_account_in_use(p_tenant uuid, p_id uuid) RETURNS boolean AS $$
            DECLARE t text; used boolean;
            BEGIN
                FOREACH t IN ARRAY ARRAY['vendor_payments', 'expenses', 'cash_transactions', 'bank_statements'] LOOP
                    IF to_regclass(t) IS NOT NULL THEN
                        EXECUTE format('SELECT EXISTS (SELECT 1 FROM %I WHERE tenant_id = $1 AND cash_bank_account_id = $2)', t) INTO used USING p_tenant, p_id;
                        IF used THEN RETURN true; END IF;
                    END IF;
                END LOOP;
                RETURN false;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_bank_accounts_guard() RETURNS trigger AS $$
            BEGIN
                IF (NEW.account_id <> OLD.account_id OR NEW.currency <> OLD.currency OR NEW.kind <> OLD.kind) AND cash_bank_account_in_use(OLD.tenant_id, OLD.id) THEN
                    RAISE EXCEPTION 'the GL account, currency and kind of a cash or bank account that documents use cannot change' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER cash_bank_accounts_guard BEFORE UPDATE ON cash_bank_accounts FOR EACH ROW EXECUTE FUNCTION cash_bank_accounts_guard()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS cash_bank_accounts_guard ON cash_bank_accounts');
        DB::unprepared('DROP FUNCTION IF EXISTS cash_bank_accounts_guard()');
        DB::unprepared('DROP FUNCTION IF EXISTS cash_bank_account_in_use(uuid, uuid)');
        Schema::dropIfExists('cash_bank_accounts');
    }
};
