<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA4 batch G: the tax foundation. Tax codes (tenant-owned, generic types: input, output, withholding, other) with effective-dated rates
 * that never overlap, and the tax transaction: the immutable snapshot of what was calculated for one document line (code, rate, method,
 * base, tax, accounts, dates, counterparty), written as a draft with the document and frozen when the document posts. Changing a tax
 * rate therefore never alters a posted transaction, and the tax report reads these rows, not today's configuration.
 *
 * The documents themselves are extended minimally: a line (an expense, as a whole) may name a tax code, and keeps the amount as it was
 * entered (the gross for an inclusive code), so a draft can be re-saved without taxing a net amount twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code', 30);
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->string('tax_type', 20); // INPUT_TAX | OUTPUT_TAX | WITHHOLDING | OTHER
            $table->string('calculation_method', 10)->default('EXCLUSIVE'); // EXCLUSIVE | INCLUSIVE
            $table->string('treatment', 12)->default('STANDARD'); // STANDARD | ZERO_RATED | EXEMPT
            $table->boolean('is_recoverable')->default(true); // input tax only: false = the tax is a cost of the line
            $table->string('account_role', 40)->nullable(); // the role the tax posts to (resolved through the tenant's mapping)
            $table->uuid('account_id')->nullable(); // optional explicit account, wins over the role
            $table->jsonb('metadata')->nullable(); // free labels for local requirements; never read by the calculation
            $table->string('status', 10)->default('ACTIVE');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign('account_role')->references('code')->on('account_roles')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status', 'tax_type']);
        });
        DB::statement("ALTER TABLE tax_codes ADD CONSTRAINT tax_codes_type_check CHECK (tax_type IN ('INPUT_TAX','OUTPUT_TAX','WITHHOLDING','OTHER'))");
        DB::statement("ALTER TABLE tax_codes ADD CONSTRAINT tax_codes_method_check CHECK (calculation_method IN ('EXCLUSIVE','INCLUSIVE'))");
        DB::statement("ALTER TABLE tax_codes ADD CONSTRAINT tax_codes_treatment_check CHECK (treatment IN ('STANDARD','ZERO_RATED','EXEMPT'))");
        DB::statement("ALTER TABLE tax_codes ADD CONSTRAINT tax_codes_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");
        // Only the input side can be a cost (non-recoverable); an output tax is always owed.
        DB::statement("ALTER TABLE tax_codes ADD CONSTRAINT tax_codes_recoverable_check CHECK (is_recoverable OR tax_type IN ('INPUT_TAX','OTHER'))");
        // A tax that is posted to an account names where; a non-recoverable tax is a cost and needs none.
        DB::statement('ALTER TABLE tax_codes ADD CONSTRAINT tax_codes_account_check CHECK (NOT is_recoverable OR account_role IS NOT NULL OR account_id IS NOT NULL)');

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tax_code_id');
            $table->decimal('rate', 9, 6); // percent, 0..100
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tax_code_id'])->references(['tenant_id', 'id'])->on('tax_codes')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'tax_code_id', 'effective_from']);
        });
        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_rate_check CHECK (rate >= 0 AND rate <= 100)');
        DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_window_check CHECK (effective_until IS NULL OR effective_until >= effective_from)');
        // The rate of a tax code on a date is unique: windows of one code never overlap.
        DB::statement("ALTER TABLE tax_rates ADD CONSTRAINT tax_rates_no_overlap EXCLUDE USING gist (tax_code_id WITH =, daterange(effective_from, effective_until, '[]') WITH &&)");

        Schema::create('tax_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tax_code_id');
            $table->uuid('tax_rate_id');
            // The snapshot of the configuration used (later changes to the code or the rate never touch these).
            $table->string('tax_code', 30);
            $table->string('tax_name', 150);
            $table->string('tax_type', 20);
            $table->string('treatment', 12);
            $table->string('calculation_method', 10);
            $table->boolean('is_recoverable');
            $table->decimal('rate', 9, 6);
            $table->string('account_role', 40)->nullable();
            $table->uuid('account_id')->nullable();
            $table->string('direction', 6); // INPUT (purchases, expenses) | OUTPUT (sales)
            // The document fact.
            $table->string('source_type', 30);
            $table->uuid('source_id');
            $table->unsignedSmallInteger('line_number')->default(0); // 0 = the document as a whole (an expense)
            $table->decimal('entered_amount', 20, 4); // as entered: the gross when the method is inclusive
            $table->decimal('base_amount', 20, 4);
            $table->decimal('tax_amount', 20, 4);
            $table->date('tax_date'); // the date the rate was resolved on (the document's transaction date)
            $table->date('posting_date')->nullable();
            $table->string('document_number', 40)->nullable();
            $table->string('counterparty_type', 20)->nullable(); // vendor | customer
            $table->uuid('counterparty_id')->nullable();
            $table->string('counterparty_name', 255)->nullable();
            $table->string('counterparty_tax_id', 50)->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('business_unit_id')->nullable();
            $table->string('status', 10)->default('DRAFT');
            $table->uuid('created_by')->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('reversal_journal_id')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tax_code_id'])->references(['tenant_id', 'id'])->on('tax_codes')->restrictOnDelete();
            $table->foreign(['tenant_id', 'tax_rate_id'])->references(['tenant_id', 'id'])->on('tax_rates')->restrictOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'business_unit_id'])->references(['tenant_id', 'id'])->on('business_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'journal_entry_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reversal_journal_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'source_type', 'source_id', 'line_number']);
            $table->index(['tenant_id', 'status', 'posting_date']);
            $table->index(['tenant_id', 'tax_code_id', 'status', 'posting_date']);
            $table->index(['tenant_id', 'tax_rate_id']);
            $table->index(['tenant_id', 'counterparty_id']);
        });
        DB::statement("ALTER TABLE tax_transactions ADD CONSTRAINT tax_transactions_enums_check CHECK (
            tax_type IN ('INPUT_TAX','OUTPUT_TAX','WITHHOLDING','OTHER') AND treatment IN ('STANDARD','ZERO_RATED','EXEMPT')
            AND calculation_method IN ('EXCLUSIVE','INCLUSIVE') AND direction IN ('INPUT','OUTPUT') AND status IN ('DRAFT','POSTED','REVERSED'))");
        DB::statement('ALTER TABLE tax_transactions ADD CONSTRAINT tax_transactions_amounts_check CHECK (
            base_amount > 0 AND tax_amount >= 0 AND rate >= 0 AND rate <= 100
            AND ((calculation_method = \'EXCLUSIVE\' AND entered_amount = base_amount) OR (calculation_method = \'INCLUSIVE\' AND entered_amount = base_amount + tax_amount))
            AND (treatment = \'STANDARD\' OR (rate = 0 AND tax_amount = 0)) AND (rate > 0 OR tax_amount = 0))');
        DB::statement("ALTER TABLE tax_transactions ADD CONSTRAINT tax_transactions_side_check CHECK (is_recoverable OR direction = 'INPUT')");
        // A recoverable tax is posted to an account (role or explicit); a non-recoverable one is part of the cost.
        DB::statement('ALTER TABLE tax_transactions ADD CONSTRAINT tax_transactions_account_check CHECK (NOT is_recoverable OR tax_amount = 0 OR account_role IS NOT NULL OR account_id IS NOT NULL)');
        DB::statement("ALTER TABLE tax_transactions ADD CONSTRAINT tax_transactions_state_check CHECK (
            ((status = 'DRAFT') = (journal_entry_id IS NULL)) AND ((status = 'REVERSED') = (reversal_journal_id IS NOT NULL))
            AND (status = 'DRAFT' OR (posting_date IS NOT NULL AND document_number IS NOT NULL AND posted_at IS NOT NULL)))");

        // --- Configuration guards.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tax_rates_guard() RETURNS trigger AS $$
            DECLARE c tax_codes%ROWTYPE;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM tax_transactions t WHERE t.tenant_id = OLD.tenant_id AND t.tax_rate_id = OLD.id) THEN
                        RAISE EXCEPTION 'a tax rate that taxed a transaction cannot be deleted' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;
                IF TG_OP = 'INSERT' THEN
                    SELECT * INTO c FROM tax_codes WHERE tenant_id = NEW.tenant_id AND id = NEW.tax_code_id;
                    IF c.treatment <> 'STANDARD' AND NEW.rate <> 0 THEN
                        RAISE EXCEPTION 'a zero rated or exempt tax code has a rate of zero' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                -- A rate is history: only its end can be set or moved earlier, never before a date it already taxed.
                IF NEW.tax_code_id <> OLD.tax_code_id OR NEW.rate <> OLD.rate OR NEW.effective_from <> OLD.effective_from OR NEW.tenant_id <> OLD.tenant_id THEN
                    RAISE EXCEPTION 'the rate and start of a tax rate cannot change; add a new rate' USING ERRCODE = '23514';
                END IF;
                IF NEW.effective_until IS DISTINCT FROM OLD.effective_until AND EXISTS (
                    SELECT 1 FROM tax_transactions t WHERE t.tenant_id = OLD.tenant_id AND t.tax_rate_id = OLD.id AND NEW.effective_until IS NOT NULL AND t.tax_date > NEW.effective_until) THEN
                    RAISE EXCEPTION 'a tax rate cannot end before a date it already taxed' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER tax_rates_guard BEFORE INSERT OR UPDATE OR DELETE ON tax_rates FOR EACH ROW EXECUTE FUNCTION tax_rates_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tax_codes_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF EXISTS (SELECT 1 FROM tax_transactions t WHERE t.tenant_id = OLD.tenant_id AND t.tax_code_id = OLD.id) THEN
                        RAISE EXCEPTION 'a tax code that taxed a transaction cannot be deleted' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;
                IF NEW.code <> OLD.code OR NEW.tenant_id <> OLD.tenant_id THEN
                    RAISE EXCEPTION 'the code of a tax code cannot change' USING ERRCODE = '23514';
                END IF;
                IF (NEW.tax_type <> OLD.tax_type OR NEW.calculation_method <> OLD.calculation_method OR NEW.treatment <> OLD.treatment OR NEW.is_recoverable <> OLD.is_recoverable)
                   AND EXISTS (SELECT 1 FROM tax_transactions t WHERE t.tenant_id = OLD.tenant_id AND t.tax_code_id = OLD.id) THEN
                    RAISE EXCEPTION 'a tax code that was used cannot change its type, method, treatment or recoverability' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER tax_codes_guard BEFORE UPDATE OR DELETE ON tax_codes FOR EACH ROW EXECUTE FUNCTION tax_codes_guard()');

        // --- The tax transaction: a draft follows its document, a posted one is a frozen fact.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION tax_transactions_guard() RETURNS trigger AS $$
            DECLARE j journal_entries%ROWTYPE; frozen text[] := ARRAY['status', 'posting_date', 'document_number', 'journal_entry_id', 'reversal_journal_id', 'posted_at', 'reversed_at', 'updated_at'];
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'a tax transaction starts as a draft' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'only a draft tax transaction can be deleted' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status = 'REVERSED' THEN
                    RAISE EXCEPTION 'a reversed tax transaction is final' USING ERRCODE = '23514';
                END IF;
                IF OLD.status = 'POSTED' AND NEW.status = 'POSTED' THEN
                    IF (to_jsonb(NEW) - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'updated_at') THEN
                        RAISE EXCEPTION 'a posted tax transaction is immutable' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF OLD.status = 'DRAFT' AND NEW.status = 'POSTED' THEN
                    SELECT * INTO j FROM journal_entries WHERE tenant_id = NEW.tenant_id AND id = NEW.journal_entry_id;
                    IF NOT FOUND OR j.status <> 'POSTED' OR j.posting_date <> NEW.posting_date THEN
                        RAISE EXCEPTION 'a posted tax transaction needs the posted journal of its posting date' USING ERRCODE = '23514';
                    END IF;
                ELSIF OLD.status = 'POSTED' AND NEW.status = 'REVERSED' THEN
                    IF (to_jsonb(NEW) - frozen) IS DISTINCT FROM (to_jsonb(OLD) - frozen) THEN
                        RAISE EXCEPTION 'a posted tax transaction can only receive its reversal' USING ERRCODE = '23514';
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.tenant_id = NEW.tenant_id AND r.id = NEW.reversal_journal_id
                                   AND r.status = 'POSTED' AND r.reverses_journal_id = NEW.journal_entry_id) THEN
                        RAISE EXCEPTION 'a tax transaction is reversed only with the posted reversal of its own journal' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                ELSIF NEW.status <> OLD.status THEN
                    RAISE EXCEPTION 'a tax transaction cannot move from % to %', OLD.status, NEW.status USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER tax_transactions_guard BEFORE INSERT OR UPDATE OR DELETE ON tax_transactions FOR EACH ROW EXECUTE FUNCTION tax_transactions_guard()');

        // --- The documents: a line (an expense, as a whole) may name a tax code; the amount as entered is kept beside the net amount.
        foreach (['ap_invoice_lines', 'ar_invoice_lines', 'expenses'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->uuid('tax_code_id')->nullable();
                $table->decimal('entered_amount', 20, 4)->nullable();
                $table->foreign(['tenant_id', 'tax_code_id'], "{$name}_tax_code_foreign")->references(['tenant_id', 'id'])->on('tax_codes')->restrictOnDelete();
            });
            DB::statement("ALTER TABLE {$name} ADD CONSTRAINT {$name}_tax_entered_check CHECK ((tax_code_id IS NULL) = (entered_amount IS NULL))");
        }
    }

    public function down(): void
    {
        foreach (['ap_invoice_lines', 'ar_invoice_lines', 'expenses'] as $name) {
            DB::statement("ALTER TABLE {$name} DROP CONSTRAINT IF EXISTS {$name}_tax_entered_check");
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropForeign("{$name}_tax_code_foreign");
                $table->dropColumn(['tax_code_id', 'entered_amount']);
            });
        }
        DB::unprepared('DROP TRIGGER IF EXISTS tax_transactions_guard ON tax_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS tax_transactions_guard()');
        Schema::dropIfExists('tax_transactions');
        DB::unprepared('DROP TRIGGER IF EXISTS tax_rates_guard ON tax_rates');
        DB::unprepared('DROP FUNCTION IF EXISTS tax_rates_guard()');
        Schema::dropIfExists('tax_rates');
        DB::unprepared('DROP TRIGGER IF EXISTS tax_codes_guard ON tax_codes');
        DB::unprepared('DROP FUNCTION IF EXISTS tax_codes_guard()');
        Schema::dropIfExists('tax_codes');
    }
};
