<?php

use App\Support\Database\DocumentGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA3 batches B-C: customer invoices (the AR source document) and their lines. The invoice is the receivable the subledger tracks.
 * A posted invoice is immutable in the service and in the database; the only later write is the one-time reversal link. The AR
 * subledger stores no balance: outstanding = total - effective receipt allocations - posted credit notes (see ArSubledgerService).
 */
return new class extends Migration
{
    private const REVERSAL = ['reversal_journal_id', 'reversed_by', 'reversed_at', 'reversal_reason', 'reversal_posting_date'];

    public function up(): void
    {
        Schema::create('ar_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('document_number', 40)->nullable(); // issued at posting, immutable afterwards
            $table->uuid('customer_id');
            $table->string('customer_reference', 100)->nullable(); // the customer's own reference (purchase order); duplicates are warned, not refused
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
            $table->uuid('receivable_account_id')->nullable(); // the AR control account the posting debited
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
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_term_id'])->references(['tenant_id', 'id'])->on('payment_terms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'business_unit_id'])->references(['tenant_id', 'id'])->on('business_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cost_center_id'])->references(['tenant_id', 'id'])->on('cost_centers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'receivable_account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'journal_entry_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reversal_journal_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign('accounting_event_id')->references('id')->on('accounting_events')->restrictOnDelete();
            foreach (['created_by', 'submitted_by', 'approved_by', 'rejected_by', 'cancelled_by', 'posted_by', 'reversed_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->restrictOnDelete();
            }
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'posting_date']);
            $table->index(['tenant_id', 'customer_id', 'status']);
            $table->index(['tenant_id', 'due_date']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'created_by']);
        });

        DB::statement("ALTER TABLE ar_invoices ADD CONSTRAINT ar_invoices_status_check CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','REJECTED','POSTED','CANCELLED','REVERSED'))");
        DB::statement('ALTER TABLE ar_invoices ADD CONSTRAINT ar_invoices_amounts_check CHECK (
            subtotal_amount >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND other_charges_amount >= 0 AND discount_amount <= subtotal_amount
            AND total_amount = subtotal_amount - discount_amount + tax_amount + other_charges_amount)');
        DB::statement('ALTER TABLE ar_invoices ADD CONSTRAINT ar_invoices_dates_check CHECK (due_date >= document_date)');
        DB::statement("ALTER TABLE ar_invoices ADD CONSTRAINT ar_invoices_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement("ALTER TABLE ar_invoices ADD CONSTRAINT ar_invoices_posted_check CHECK (status NOT IN ('POSTED','REVERSED') OR (
            document_number IS NOT NULL AND posted_at IS NOT NULL AND journal_entry_id IS NOT NULL AND receivable_account_id IS NOT NULL AND total_amount > 0))");
        DB::statement("ALTER TABLE ar_invoices ADD CONSTRAINT ar_invoices_reversed_check CHECK (
            (status = 'REVERSED') = (reversal_journal_id IS NOT NULL AND reversed_at IS NOT NULL AND reversal_posting_date IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX ar_invoices_source_unique ON ar_invoices (tenant_id, source_type, source_id) WHERE source_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX ar_invoices_number_unique ON ar_invoices (tenant_id, document_number) WHERE document_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX ar_invoices_journal_unique ON ar_invoices (tenant_id, journal_entry_id) WHERE journal_entry_id IS NOT NULL');
        DB::statement('CREATE INDEX ar_invoices_reversal_journal ON ar_invoices (tenant_id, reversal_journal_id) WHERE reversal_journal_id IS NOT NULL');
        DB::statement('CREATE INDEX ar_invoices_customer_reference ON ar_invoices (tenant_id, customer_id, customer_reference) WHERE customer_reference IS NOT NULL');
        DB::statement("CREATE INDEX ar_invoices_open ON ar_invoices (tenant_id, customer_id, due_date) WHERE status = 'POSTED'");

        // A posted invoice must carry a posted journal that debits exactly its total on its posting date.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ar_invoices_post_guard() RETURNS trigger AS $$
            DECLARE j journal_entries%ROWTYPE; settled boolean := false;
            BEGIN
                IF NEW.status = 'POSTED' AND OLD.status IS DISTINCT FROM 'POSTED' THEN
                    SELECT * INTO j FROM journal_entries WHERE tenant_id = NEW.tenant_id AND id = NEW.journal_entry_id;
                    IF NOT FOUND OR j.status <> 'POSTED' OR j.total_debit <> NEW.total_amount OR j.posting_date <> NEW.posting_date THEN
                        RAISE EXCEPTION 'a posted receivable needs the posted journal of exactly its total and posting date' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF NEW.status = 'REVERSED' AND OLD.status = 'POSTED' THEN
                    -- Receipts and credit notes applied to the invoice must be reversed first, so the subledger never goes negative.
                    IF to_regclass('ar_receipt_allocations') IS NOT NULL THEN
                        EXECUTE 'SELECT EXISTS (SELECT 1 FROM ar_receipt_allocations WHERE tenant_id = $1 AND ar_invoice_id = $2 AND is_effective)' INTO settled USING NEW.tenant_id, NEW.id;
                        IF settled THEN
                            RAISE EXCEPTION 'an invoice with effective receipt allocations cannot be reversed' USING ERRCODE = '23514';
                        END IF;
                    END IF;
                    IF to_regclass('ar_credit_notes') IS NOT NULL THEN
                        EXECUTE 'SELECT EXISTS (SELECT 1 FROM ar_credit_notes WHERE tenant_id = $1 AND ar_invoice_id = $2 AND status = ''POSTED'')' INTO settled USING NEW.tenant_id, NEW.id;
                        IF settled THEN
                            RAISE EXCEPTION 'an invoice with posted credit notes cannot be reversed' USING ERRCODE = '23514';
                        END IF;
                    END IF;
                    IF NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.tenant_id = NEW.tenant_id AND r.id = NEW.reversal_journal_id
                                   AND r.status = 'POSTED' AND r.reverses_journal_id = NEW.journal_entry_id) THEN
                        RAISE EXCEPTION 'a receivable is reversed only by the posted reversal of its own journal' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER ar_invoices_post_guard BEFORE UPDATE ON ar_invoices FOR EACH ROW EXECUTE FUNCTION ar_invoices_post_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ar_invoices_insert_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'a customer invoice starts as a draft' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER ar_invoices_insert_guard BEFORE INSERT ON ar_invoices FOR EACH ROW EXECUTE FUNCTION ar_invoices_insert_guard()');
        DocumentGuards::guardDocument('ar_invoices', self::REVERSAL);

        Schema::create('ar_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('ar_invoice_id');
            $table->unsignedSmallInteger('line_number');
            $table->string('description', 255);
            $table->decimal('quantity', 20, 4)->nullable();
            $table->decimal('unit_price', 20, 4)->nullable();
            $table->decimal('amount', 20, 4);
            $table->string('account_role', 40)->nullable(); // revenue classification: a semantic role (tenant mapping) ...
            $table->uuid('account_id')->nullable(); // ... or a revenue account named on the line
            $table->uuid('cost_center_id')->nullable();
            $table->jsonb('metadata')->nullable(); // reference / tax-note metadata; no tax logic in OA3
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'ar_invoice_id'])->references(['tenant_id', 'id'])->on('ar_invoices')->restrictOnDelete();
            $table->foreign('account_role')->references('code')->on('account_roles')->restrictOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cost_center_id'])->references(['tenant_id', 'id'])->on('cost_centers')->restrictOnDelete();
            $table->unique(['ar_invoice_id', 'line_number']);
            $table->index(['tenant_id', 'ar_invoice_id']);
        });
        DB::statement('ALTER TABLE ar_invoice_lines ADD CONSTRAINT ar_invoice_lines_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE ar_invoice_lines ADD CONSTRAINT ar_invoice_lines_qty_check CHECK ((quantity IS NULL) = (unit_price IS NULL) AND (quantity IS NULL OR (quantity > 0 AND unit_price >= 0)))');
        DocumentGuards::guardLines('ar_invoice_lines', 'ar_invoices', 'ar_invoice_id');
    }

    public function down(): void
    {
        DocumentGuards::drop('ar_invoice_lines');
        Schema::dropIfExists('ar_invoice_lines');
        DocumentGuards::drop('ar_invoices');
        DB::unprepared('DROP TRIGGER IF EXISTS ar_invoices_insert_guard ON ar_invoices');
        DB::unprepared('DROP FUNCTION IF EXISTS ar_invoices_insert_guard()');
        DB::unprepared('DROP TRIGGER IF EXISTS ar_invoices_post_guard ON ar_invoices');
        DB::unprepared('DROP FUNCTION IF EXISTS ar_invoices_post_guard()');
        Schema::dropIfExists('ar_invoices');
    }
};
