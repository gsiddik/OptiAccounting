<?php

use App\Support\Database\DocumentGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA2 batches B-C: vendor invoices (the AP source document) and their lines.
 * An AP document is the payable the subledger tracks. A posted invoice is immutable in the service and in the database;
 * the only later write is the one-time reversal link. Expense payables use the same table (origin EXPENSE), so there is
 * exactly one payable ledger behind the AP control account.
 */
return new class extends Migration
{
    private const REVERSAL = ['reversal_journal_id', 'reversed_by', 'reversed_at', 'reversal_reason', 'reversal_posting_date'];

    public function up(): void
    {
        Schema::create('ap_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('document_number', 40)->nullable(); // issued at posting, immutable afterwards
            $table->string('origin', 10)->default('INVOICE'); // INVOICE | EXPENSE (payable expense)
            $table->uuid('vendor_id');
            $table->string('vendor_invoice_number', 100);
            $table->string('vendor_invoice_key', 100)->storedAs("upper(regexp_replace(btrim(vendor_invoice_number), '\\s+', ' ', 'g'))"); // duplicate identity
            $table->date('document_date');
            $table->date('posting_date'); // decides the accounting period
            $table->date('due_date');
            $table->uuid('payment_term_id')->nullable();
            $table->boolean('due_date_overridden')->default(false);
            $table->char('currency', 3);
            $table->string('description', 500);
            $table->string('reference', 100)->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('business_unit_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->decimal('subtotal_amount', 20, 4)->default(0); // server-computed from the lines
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('other_charges_amount', 20, 4)->default(0);
            $table->decimal('total_amount', 20, 4)->default(0);
            $table->uuid('payable_account_id')->nullable(); // the AP control account the posting credited
            $table->string('status', 20)->default('DRAFT');
            $table->string('source_type', 40)->nullable();
            $table->string('source_id', 64)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('submitted_by')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('journal_entry_id')->nullable();
            $table->uuid('accounting_event_id')->nullable();
            $table->uuid('reversal_journal_id')->nullable();
            $table->uuid('reversed_by')->nullable();
            $table->timestampTz('reversed_at')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->date('reversal_posting_date')->nullable();
            $table->uuid('duplicate_override_by')->nullable();
            $table->string('duplicate_override_reason', 255)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'vendor_id'])->references(['tenant_id', 'id'])->on('vendors')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_term_id'])->references(['tenant_id', 'id'])->on('payment_terms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'business_unit_id'])->references(['tenant_id', 'id'])->on('business_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cost_center_id'])->references(['tenant_id', 'id'])->on('cost_centers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payable_account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'journal_entry_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reversal_journal_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign('accounting_event_id')->references('id')->on('accounting_events')->restrictOnDelete();
            foreach (['created_by', 'submitted_by', 'approved_by', 'rejected_by', 'cancelled_by', 'posted_by', 'reversed_by', 'duplicate_override_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->restrictOnDelete();
            }
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'posting_date']);
            $table->index(['tenant_id', 'vendor_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'created_by']);
        });

        DB::statement("ALTER TABLE ap_invoices ADD CONSTRAINT ap_invoices_status_check CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','REJECTED','POSTED','CANCELLED','REVERSED'))");
        DB::statement("ALTER TABLE ap_invoices ADD CONSTRAINT ap_invoices_origin_check CHECK (origin IN ('INVOICE','EXPENSE'))");
        DB::statement('ALTER TABLE ap_invoices ADD CONSTRAINT ap_invoices_amounts_check CHECK (
            subtotal_amount >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND other_charges_amount >= 0 AND discount_amount <= subtotal_amount
            AND total_amount = subtotal_amount - discount_amount + tax_amount + other_charges_amount)');
        DB::statement('ALTER TABLE ap_invoices ADD CONSTRAINT ap_invoices_dates_check CHECK (due_date >= document_date)');
        DB::statement("ALTER TABLE ap_invoices ADD CONSTRAINT ap_invoices_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement("ALTER TABLE ap_invoices ADD CONSTRAINT ap_invoices_posted_check CHECK (status NOT IN ('POSTED','REVERSED') OR (
            document_number IS NOT NULL AND posted_at IS NOT NULL AND journal_entry_id IS NOT NULL AND payable_account_id IS NOT NULL AND total_amount > 0))");
        DB::statement("ALTER TABLE ap_invoices ADD CONSTRAINT ap_invoices_reversed_check CHECK (
            (status = 'REVERSED') = (reversal_journal_id IS NOT NULL AND reversed_at IS NOT NULL AND reversal_posting_date IS NOT NULL))");
        DB::statement('ALTER TABLE ap_invoices ADD CONSTRAINT ap_invoices_override_check CHECK ((duplicate_override_by IS NULL) = (duplicate_override_reason IS NULL))');
        DB::statement('CREATE UNIQUE INDEX ap_invoices_number_unique ON ap_invoices (tenant_id, document_number) WHERE document_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX ap_invoices_journal_unique ON ap_invoices (tenant_id, journal_entry_id) WHERE journal_entry_id IS NOT NULL');
        // Duplicate control: one live document per vendor and vendor invoice number, unless an authorized user recorded an explicit override.
        DB::statement("CREATE UNIQUE INDEX ap_invoices_vendor_number_unique ON ap_invoices (tenant_id, vendor_id, vendor_invoice_key)
            WHERE status IN ('DRAFT','SUBMITTED','APPROVED','POSTED') AND duplicate_override_by IS NULL");
        DB::statement("CREATE INDEX ap_invoices_open ON ap_invoices (tenant_id, vendor_id, due_date) WHERE status = 'POSTED'");

        // A posted invoice must carry a posted journal of exactly its total (the credit to the control account).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ap_invoices_post_guard() RETURNS trigger AS $$
            DECLARE j journal_entries%ROWTYPE;
            BEGIN
                IF NEW.status = 'POSTED' AND OLD.status IS DISTINCT FROM 'POSTED' THEN
                    SELECT * INTO j FROM journal_entries WHERE tenant_id = NEW.tenant_id AND id = NEW.journal_entry_id;
                    IF NOT FOUND OR j.status <> 'POSTED' OR j.total_credit <> NEW.total_amount OR j.posting_date <> NEW.posting_date THEN
                        RAISE EXCEPTION 'a posted payable needs the posted journal of exactly its total and posting date' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.vendor_id IS NULL OR NEW.payable_account_id IS NULL THEN
                        RAISE EXCEPTION 'a posted payable needs its vendor and control account' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF NEW.status = 'REVERSED' AND OLD.status = 'POSTED' THEN
                    IF NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.tenant_id = NEW.tenant_id AND r.id = NEW.reversal_journal_id
                                   AND r.status = 'POSTED' AND r.reverses_journal_id = NEW.journal_entry_id) THEN
                        RAISE EXCEPTION 'a payable is reversed only by the posted reversal of its own journal' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER ap_invoices_post_guard BEFORE UPDATE ON ap_invoices FOR EACH ROW EXECUTE FUNCTION ap_invoices_post_guard()');
        DocumentGuards::guardDocument('ap_invoices', self::REVERSAL, ['vendor_invoice_key']);

        Schema::create('ap_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('ap_invoice_id');
            $table->unsignedSmallInteger('line_number');
            $table->string('description', 255);
            $table->decimal('quantity', 20, 4)->nullable();
            $table->decimal('unit_price', 20, 4)->nullable();
            $table->decimal('amount', 20, 4);
            $table->uuid('expense_category_id')->nullable();
            $table->string('account_role', 40)->nullable();
            $table->uuid('account_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->jsonb('metadata')->nullable(); // reference / tax-note metadata; no tax logic in OA2
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'ap_invoice_id'])->references(['tenant_id', 'id'])->on('ap_invoices')->restrictOnDelete();
            $table->foreign(['tenant_id', 'expense_category_id'])->references(['tenant_id', 'id'])->on('expense_categories')->restrictOnDelete();
            $table->foreign('account_role')->references('code')->on('account_roles')->restrictOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cost_center_id'])->references(['tenant_id', 'id'])->on('cost_centers')->restrictOnDelete();
            $table->unique(['ap_invoice_id', 'line_number']);
            $table->index(['tenant_id', 'ap_invoice_id']);
        });
        DB::statement('ALTER TABLE ap_invoice_lines ADD CONSTRAINT ap_invoice_lines_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE ap_invoice_lines ADD CONSTRAINT ap_invoice_lines_qty_check CHECK ((quantity IS NULL) = (unit_price IS NULL) AND (quantity IS NULL OR (quantity > 0 AND unit_price >= 0)))');
        DocumentGuards::guardLines('ap_invoice_lines', 'ap_invoices', 'ap_invoice_id');
    }

    public function down(): void
    {
        DocumentGuards::drop('ap_invoice_lines');
        Schema::dropIfExists('ap_invoice_lines');
        DocumentGuards::drop('ap_invoices');
        DB::unprepared('DROP TRIGGER IF EXISTS ap_invoices_post_guard ON ap_invoices');
        DB::unprepared('DROP FUNCTION IF EXISTS ap_invoices_post_guard()');
        Schema::dropIfExists('ap_invoices');
    }
};
