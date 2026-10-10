<?php

use App\Support\Database\DocumentGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA3 batch E: credit notes. A credit note reduces the receivable of exactly one posted customer invoice; the invoice itself is never
 * edited. While POSTED the note counts against the invoice (outstanding = total - effective receipt allocations - posted credit notes);
 * reversing it gives the amount back. The database repeats the rule: a note posts only against a posted invoice of the same customer,
 * under the invoice row lock, and never for more than is still outstanding, so receipts and credit notes can never over-settle together.
 * Debit notes are not part of OA3: an additional charge is a new customer invoice.
 */
return new class extends Migration
{
    private const REVERSAL = ['reversal_journal_id', 'reversed_by', 'reversed_at', 'reversal_reason', 'reversal_posting_date'];

    public function up(): void
    {
        Schema::create('ar_credit_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('document_number', 40)->nullable(); // issued at posting
            $table->uuid('customer_id');
            $table->uuid('ar_invoice_id'); // the invoice whose receivable the note reduces
            $table->date('document_date');
            $table->date('posting_date');
            $table->char('currency', 3);
            $table->string('reason', 500);
            $table->string('reference', 100)->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('business_unit_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->decimal('subtotal_amount', 20, 4)->default(0);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('total_amount', 20, 4)->default(0);
            $table->uuid('receivable_account_id')->nullable(); // the AR control account the posting credited (the invoice's)
            $table->string('status', 20)->default('DRAFT');
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
            $table->foreign(['tenant_id', 'ar_invoice_id'])->references(['tenant_id', 'id'])->on('ar_invoices')->restrictOnDelete();
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
            $table->index(['tenant_id', 'ar_invoice_id', 'status']);
            $table->index(['tenant_id', 'customer_id', 'status']);
            $table->index(['tenant_id', 'created_by']);
        });

        DB::statement("ALTER TABLE ar_credit_notes ADD CONSTRAINT ar_credit_notes_status_check CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','REJECTED','POSTED','CANCELLED','REVERSED'))");
        DB::statement('ALTER TABLE ar_credit_notes ADD CONSTRAINT ar_credit_notes_amounts_check CHECK (subtotal_amount >= 0 AND tax_amount >= 0 AND total_amount = subtotal_amount + tax_amount)');
        DB::statement("ALTER TABLE ar_credit_notes ADD CONSTRAINT ar_credit_notes_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement("ALTER TABLE ar_credit_notes ADD CONSTRAINT ar_credit_notes_posted_check CHECK (status NOT IN ('POSTED','REVERSED') OR (
            document_number IS NOT NULL AND posted_at IS NOT NULL AND journal_entry_id IS NOT NULL AND receivable_account_id IS NOT NULL AND total_amount > 0))");
        DB::statement("ALTER TABLE ar_credit_notes ADD CONSTRAINT ar_credit_notes_reversed_check CHECK (
            (status = 'REVERSED') = (reversal_journal_id IS NOT NULL AND reversed_at IS NOT NULL AND reversal_posting_date IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX ar_credit_notes_number_unique ON ar_credit_notes (tenant_id, document_number) WHERE document_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX ar_credit_notes_journal_unique ON ar_credit_notes (tenant_id, journal_entry_id) WHERE journal_entry_id IS NOT NULL');
        DB::statement('CREATE INDEX ar_credit_notes_reversal_journal ON ar_credit_notes (tenant_id, reversal_journal_id) WHERE reversal_journal_id IS NOT NULL');

        // The note names the customer of its invoice; it is born a draft.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ar_credit_notes_link_guard() RETURNS trigger AS $$
            DECLARE inv_customer uuid;
            BEGIN
                IF TG_OP = 'INSERT' AND NEW.status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'a credit note starts as a draft' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND (NEW.ar_invoice_id <> OLD.ar_invoice_id OR NEW.customer_id <> OLD.customer_id) AND OLD.status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'the invoice and customer of a credit note cannot change after the draft' USING ERRCODE = '23514';
                END IF;
                SELECT customer_id INTO inv_customer FROM ar_invoices WHERE tenant_id = NEW.tenant_id AND id = NEW.ar_invoice_id;
                IF inv_customer IS DISTINCT FROM NEW.customer_id THEN
                    RAISE EXCEPTION 'a credit note belongs to the customer of its invoice' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER ar_credit_notes_link_guard BEFORE INSERT OR UPDATE ON ar_credit_notes FOR EACH ROW EXECUTE FUNCTION ar_credit_notes_link_guard()');

        // Posting: a posted journal of exactly the total and date, a posted invoice of the same customer, and never more than is outstanding (under the invoice lock).
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ar_credit_notes_post_guard() RETURNS trigger AS $$
            DECLARE j journal_entries%ROWTYPE; inv ar_invoices%ROWTYPE; used numeric;
            BEGIN
                IF NEW.status = 'POSTED' AND OLD.status IS DISTINCT FROM 'POSTED' THEN
                    SELECT * INTO j FROM journal_entries WHERE tenant_id = NEW.tenant_id AND id = NEW.journal_entry_id;
                    IF NOT FOUND OR j.status <> 'POSTED' OR j.total_credit <> NEW.total_amount OR j.total_debit <> NEW.total_amount OR j.posting_date <> NEW.posting_date THEN
                        RAISE EXCEPTION 'a posted credit note needs the posted journal of exactly its total and posting date' USING ERRCODE = '23514';
                    END IF;
                    SELECT * INTO inv FROM ar_invoices WHERE tenant_id = NEW.tenant_id AND id = NEW.ar_invoice_id FOR UPDATE;
                    IF NOT FOUND OR inv.status <> 'POSTED' OR inv.customer_id <> NEW.customer_id THEN
                        RAISE EXCEPTION 'a credit note reduces a posted invoice of its own customer' USING ERRCODE = '23514';
                    END IF;
                    IF inv.posting_date > NEW.posting_date THEN
                        RAISE EXCEPTION 'a credit note cannot be posted before the invoice it reduces' USING ERRCODE = '23514';
                    END IF;
                    SELECT coalesce((SELECT sum(amount) FROM ar_receipt_allocations WHERE tenant_id = NEW.tenant_id AND ar_invoice_id = NEW.ar_invoice_id AND is_effective), 0)
                         + coalesce((SELECT sum(total_amount) FROM ar_credit_notes WHERE tenant_id = NEW.tenant_id AND ar_invoice_id = NEW.ar_invoice_id AND status = 'POSTED' AND id <> NEW.id), 0)
                      INTO used;
                    IF used + NEW.total_amount > inv.total_amount THEN
                        RAISE EXCEPTION 'the credit note exceeds what is outstanding on the invoice' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF NEW.status = 'REVERSED' AND OLD.status = 'POSTED' THEN
                    IF NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.tenant_id = NEW.tenant_id AND r.id = NEW.reversal_journal_id
                                   AND r.status = 'POSTED' AND r.reverses_journal_id = NEW.journal_entry_id) THEN
                        RAISE EXCEPTION 'a credit note is reversed only by the posted reversal of its own journal' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER ar_credit_notes_post_guard BEFORE UPDATE ON ar_credit_notes FOR EACH ROW EXECUTE FUNCTION ar_credit_notes_post_guard()');
        DocumentGuards::guardDocument('ar_credit_notes', self::REVERSAL);

        Schema::create('ar_credit_note_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('ar_credit_note_id');
            $table->unsignedSmallInteger('line_number');
            $table->string('description', 255);
            $table->decimal('quantity', 20, 4)->nullable();
            $table->decimal('unit_price', 20, 4)->nullable();
            $table->decimal('amount', 20, 4);
            $table->string('account_role', 40)->nullable();
            $table->uuid('account_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'ar_credit_note_id'])->references(['tenant_id', 'id'])->on('ar_credit_notes')->restrictOnDelete();
            $table->foreign('account_role')->references('code')->on('account_roles')->restrictOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cost_center_id'])->references(['tenant_id', 'id'])->on('cost_centers')->restrictOnDelete();
            $table->unique(['ar_credit_note_id', 'line_number']);
            $table->index(['tenant_id', 'ar_credit_note_id']);
        });
        DB::statement('ALTER TABLE ar_credit_note_lines ADD CONSTRAINT ar_credit_note_lines_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE ar_credit_note_lines ADD CONSTRAINT ar_credit_note_lines_qty_check CHECK ((quantity IS NULL) = (unit_price IS NULL) AND (quantity IS NULL OR (quantity > 0 AND unit_price >= 0)))');
        DocumentGuards::guardLines('ar_credit_note_lines', 'ar_credit_notes', 'ar_credit_note_id');
    }

    public function down(): void
    {
        DocumentGuards::drop('ar_credit_note_lines');
        Schema::dropIfExists('ar_credit_note_lines');
        DocumentGuards::drop('ar_credit_notes');
        DB::unprepared('DROP TRIGGER IF EXISTS ar_credit_notes_post_guard ON ar_credit_notes');
        DB::unprepared('DROP FUNCTION IF EXISTS ar_credit_notes_post_guard()');
        DB::unprepared('DROP TRIGGER IF EXISTS ar_credit_notes_link_guard ON ar_credit_notes');
        DB::unprepared('DROP FUNCTION IF EXISTS ar_credit_notes_link_guard()');
        Schema::dropIfExists('ar_credit_notes');
    }
};
