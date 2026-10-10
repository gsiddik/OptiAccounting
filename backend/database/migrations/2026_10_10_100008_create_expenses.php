<?php

use App\Support\Database\DocumentGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA2 batch F: expenses. One expense is one classified cost with an explicit financial path:
 *   PAYABLE      Dr expense / Cr accounts payable. The payable is a posted ap_invoices row (origin EXPENSE) that shares the journal,
 *                so it is settled by the ordinary vendor payment and sits in the same subledger and control account as any invoice.
 *   DIRECT_PAID  Dr expense / Cr cash or bank, in one step.
 * Like every OA2 document a posted expense is immutable in the service and in the database.
 */
return new class extends Migration
{
    private const REVERSAL = ['reversal_journal_id', 'reversed_by', 'reversed_at', 'reversal_reason', 'reversal_posting_date'];

    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('document_number', 40)->nullable(); // issued at posting
            $table->string('settlement', 12); // PAYABLE | DIRECT_PAID
            $table->uuid('vendor_id')->nullable(); // payee vendor: required for PAYABLE, optional information for DIRECT_PAID
            $table->string('payee_name', 150)->nullable();
            $table->uuid('expense_category_id');
            $table->uuid('account_id')->nullable(); // explicit classification account (optional override of the category)
            $table->date('expense_date');
            $table->date('posting_date');
            $table->date('due_date')->nullable(); // PAYABLE only
            $table->uuid('payment_term_id')->nullable();
            $table->boolean('due_date_overridden')->default(false);
            $table->char('currency', 3);
            $table->string('description', 500);
            $table->string('reference', 100)->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('supporting_document', 150)->nullable(); // reference to the receipt / supporting document (metadata only)
            $table->uuid('cash_bank_account_id')->nullable(); // DIRECT_PAID only
            $table->uuid('gl_account_id')->nullable(); // the cash/bank GL account credited, fixed at posting
            $table->uuid('branch_id')->nullable();
            $table->uuid('business_unit_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->decimal('net_amount', 20, 4);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('total_amount', 20, 4);
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
            $table->foreign(['tenant_id', 'vendor_id'])->references(['tenant_id', 'id'])->on('vendors')->restrictOnDelete();
            $table->foreign(['tenant_id', 'expense_category_id'])->references(['tenant_id', 'id'])->on('expense_categories')->restrictOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_term_id'])->references(['tenant_id', 'id'])->on('payment_terms')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cash_bank_account_id'])->references(['tenant_id', 'id'])->on('cash_bank_accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'gl_account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'business_unit_id'])->references(['tenant_id', 'id'])->on('business_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cost_center_id'])->references(['tenant_id', 'id'])->on('cost_centers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'journal_entry_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reversal_journal_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign('accounting_event_id')->references('id')->on('accounting_events')->restrictOnDelete();
            foreach (['created_by', 'submitted_by', 'approved_by', 'rejected_by', 'cancelled_by', 'posted_by', 'reversed_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->restrictOnDelete();
            }
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'posting_date']);
            $table->index(['tenant_id', 'expense_category_id']);
            $table->index(['tenant_id', 'cash_bank_account_id', 'status']);
            $table->index(['tenant_id', 'created_by']);
        });
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_status_check CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','REJECTED','POSTED','CANCELLED','REVERSED'))");
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_settlement_check CHECK (settlement IN ('PAYABLE','DIRECT_PAID'))");
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amounts_check CHECK (net_amount > 0 AND tax_amount >= 0 AND total_amount = net_amount + tax_amount)');
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        // The financial path is explicit: a payable names its vendor and due date and no cash account; a direct payment names its cash account and no due date.
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_path_check CHECK (
            (settlement = 'PAYABLE' AND vendor_id IS NOT NULL AND due_date IS NOT NULL AND due_date >= expense_date AND cash_bank_account_id IS NULL)
            OR (settlement = 'DIRECT_PAID' AND cash_bank_account_id IS NOT NULL AND due_date IS NULL AND payment_term_id IS NULL))");
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_posted_check CHECK (status NOT IN ('POSTED','REVERSED') OR (
            document_number IS NOT NULL AND posted_at IS NOT NULL AND journal_entry_id IS NOT NULL AND (settlement = 'PAYABLE' OR gl_account_id IS NOT NULL)))");
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_reversed_check CHECK (
            (status = 'REVERSED') = (reversal_journal_id IS NOT NULL AND reversed_at IS NOT NULL AND reversal_posting_date IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX expenses_number_unique ON expenses (tenant_id, document_number) WHERE document_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX expenses_journal_unique ON expenses (tenant_id, journal_entry_id) WHERE journal_entry_id IS NOT NULL');
        DB::statement('CREATE INDEX expenses_reversal_journal ON expenses (tenant_id, reversal_journal_id) WHERE reversal_journal_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION expenses_post_guard() RETURNS trigger AS $$
            DECLARE j journal_entries%ROWTYPE;
            BEGIN
                IF NEW.status = 'POSTED' AND OLD.status IS DISTINCT FROM 'POSTED' THEN
                    SELECT * INTO j FROM journal_entries WHERE tenant_id = NEW.tenant_id AND id = NEW.journal_entry_id;
                    IF NOT FOUND OR j.status <> 'POSTED' OR j.total_credit <> NEW.total_amount OR j.total_debit <> NEW.total_amount OR j.posting_date <> NEW.posting_date THEN
                        RAISE EXCEPTION 'a posted expense needs the posted journal of exactly its total and posting date' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF NEW.status = 'REVERSED' AND OLD.status = 'POSTED' THEN
                    IF NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.tenant_id = NEW.tenant_id AND r.id = NEW.reversal_journal_id
                                   AND r.status = 'POSTED' AND r.reverses_journal_id = NEW.journal_entry_id) THEN
                        RAISE EXCEPTION 'an expense is reversed only by the posted reversal of its own journal' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER expenses_post_guard BEFORE UPDATE ON expenses FOR EACH ROW EXECUTE FUNCTION expenses_post_guard()');
        DocumentGuards::guardDocument('expenses', self::REVERSAL);
    }

    public function down(): void
    {
        DocumentGuards::drop('expenses');
        DB::unprepared('DROP TRIGGER IF EXISTS expenses_post_guard ON expenses');
        DB::unprepared('DROP FUNCTION IF EXISTS expenses_post_guard()');
        Schema::dropIfExists('expenses');
    }
};
