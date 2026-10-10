<?php

use App\Support\Database\DocumentGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA2 batch H: controlled cash/bank payments and receipts, and the manual bank reconciliation foundation.
 *
 * cash_transactions   A payment (Dr destination account, Cr cash/bank) or receipt (Dr cash/bank, Cr source account) outside AP and AR. It is a
 *                     document like every OA2 source: DRAFT then POSTED through the Posting Engine, immutable once posted, reversed by the shared
 *                     reversal. The counter account is explicit, and never a control account or another cash/bank account.
 * bank_statements     What the bank says: a statement balance entered by the user. It is reconciliation evidence, never GL truth.
 * bank_statement_items  Statement lines, matched one-to-one to posted journal lines of the account's GL account (UNMATCHED / MATCHED / EXCEPTION).
 *                     Matching only records a link; no journal is ever created or changed by reconciling.
 */
return new class extends Migration
{
    private const REVERSAL = ['reversal_journal_id', 'reversed_by', 'reversed_at', 'reversal_reason', 'reversal_posting_date'];

    public function up(): void
    {
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('document_number', 40)->nullable(); // issued at posting
            $table->string('kind', 10); // PAYMENT | RECEIPT
            $table->uuid('cash_bank_account_id');
            $table->uuid('counter_account_id'); // the destination (payment) or source (receipt) GL account, chosen by the user
            $table->date('transaction_date');
            $table->date('posting_date');
            $table->char('currency', 3);
            $table->decimal('amount', 20, 4);
            $table->string('purpose', 150); // why the money moves: required, shown on the document and in the journal
            $table->string('description', 500);
            $table->string('reference', 100)->nullable();
            $table->string('counterparty_name', 150)->nullable();
            $table->uuid('gl_account_id')->nullable(); // the cash/bank GL account, fixed at posting
            $table->uuid('branch_id')->nullable();
            $table->uuid('business_unit_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->string('status', 20)->default('DRAFT');
            $table->uuid('created_by')->nullable();
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
            $table->foreign(['tenant_id', 'cash_bank_account_id'])->references(['tenant_id', 'id'])->on('cash_bank_accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'counter_account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'gl_account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'business_unit_id'])->references(['tenant_id', 'id'])->on('business_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cost_center_id'])->references(['tenant_id', 'id'])->on('cost_centers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'journal_entry_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reversal_journal_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign('accounting_event_id')->references('id')->on('accounting_events')->restrictOnDelete();
            foreach (['created_by', 'cancelled_by', 'posted_by', 'reversed_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->restrictOnDelete();
            }
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'posting_date']);
            $table->index(['tenant_id', 'cash_bank_account_id', 'status']);
            $table->index(['tenant_id', 'created_by']);
        });
        DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_status_check CHECK (status IN ('DRAFT','POSTED','CANCELLED','REVERSED'))");
        DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_kind_check CHECK (kind IN ('PAYMENT','RECEIPT'))");
        DB::statement('ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_posted_check CHECK (status NOT IN ('POSTED','REVERSED') OR (
            document_number IS NOT NULL AND posted_at IS NOT NULL AND journal_entry_id IS NOT NULL AND gl_account_id IS NOT NULL AND gl_account_id <> counter_account_id))");
        DB::statement("ALTER TABLE cash_transactions ADD CONSTRAINT cash_transactions_reversed_check CHECK (
            (status = 'REVERSED') = (reversal_journal_id IS NOT NULL AND reversed_at IS NOT NULL AND reversal_posting_date IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX cash_transactions_number_unique ON cash_transactions (tenant_id, document_number) WHERE document_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX cash_transactions_journal_unique ON cash_transactions (tenant_id, journal_entry_id) WHERE journal_entry_id IS NOT NULL');
        DB::statement('CREATE INDEX cash_transactions_reversal_journal ON cash_transactions (tenant_id, reversal_journal_id) WHERE reversal_journal_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION cash_transactions_post_guard() RETURNS trigger AS $$
            DECLARE j journal_entries%ROWTYPE;
            BEGIN
                IF NEW.status = 'POSTED' AND OLD.status IS DISTINCT FROM 'POSTED' THEN
                    SELECT * INTO j FROM journal_entries WHERE tenant_id = NEW.tenant_id AND id = NEW.journal_entry_id;
                    IF NOT FOUND OR j.status <> 'POSTED' OR j.total_credit <> NEW.amount OR j.total_debit <> NEW.amount OR j.posting_date <> NEW.posting_date THEN
                        RAISE EXCEPTION 'a posted cash transaction needs the posted journal of exactly its amount and posting date' USING ERRCODE = '23514';
                    END IF;
                    -- The cash/bank GL account takes the debit of a receipt and the credit of a payment, the counter account the other side.
                    IF NOT EXISTS (SELECT 1 FROM journal_lines l WHERE l.tenant_id = NEW.tenant_id AND l.journal_entry_id = NEW.journal_entry_id AND l.account_id = NEW.gl_account_id
                                   AND (CASE WHEN NEW.kind = 'RECEIPT' THEN l.debit ELSE l.credit END) = NEW.amount)
                       OR NOT EXISTS (SELECT 1 FROM journal_lines l WHERE l.tenant_id = NEW.tenant_id AND l.journal_entry_id = NEW.journal_entry_id AND l.account_id = NEW.counter_account_id
                                   AND (CASE WHEN NEW.kind = 'RECEIPT' THEN l.credit ELSE l.debit END) = NEW.amount) THEN
                        RAISE EXCEPTION 'the journal of a cash transaction must move its amount between its cash/bank account and its counter account' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF NEW.status = 'REVERSED' AND OLD.status = 'POSTED' THEN
                    IF NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.tenant_id = NEW.tenant_id AND r.id = NEW.reversal_journal_id
                                   AND r.status = 'POSTED' AND r.reverses_journal_id = NEW.journal_entry_id) THEN
                        RAISE EXCEPTION 'a cash transaction is reversed only by the posted reversal of its own journal' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER cash_transactions_post_guard BEFORE UPDATE ON cash_transactions FOR EACH ROW EXECUTE FUNCTION cash_transactions_post_guard()');
        DocumentGuards::guardDocument('cash_transactions', self::REVERSAL);

        Schema::create('bank_statements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('cash_bank_account_id');
            $table->string('reference', 100); // the bank's own statement identifier, unique per account
            $table->date('statement_date'); // the closing date of the statement
            $table->date('period_start')->nullable();
            $table->decimal('opening_balance', 20, 4)->nullable();
            $table->decimal('closing_balance', 20, 4); // external truth as the bank states it; never GL truth
            $table->char('currency', 3);
            $table->string('notes', 500)->nullable();
            $table->uuid('branch_id')->nullable(); // copied from the account: data scope
            $table->uuid('business_unit_id')->nullable();
            $table->string('status', 12)->default('OPEN');
            $table->uuid('created_by')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            // Evidence frozen when the reconciliation was completed (nothing is adjusted to force a match).
            $table->decimal('book_balance', 20, 4)->nullable();
            $table->decimal('unmatched_book_net', 20, 4)->nullable();
            $table->decimal('exception_statement_net', 20, 4)->nullable();
            $table->decimal('unexplained_difference', 20, 4)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cash_bank_account_id'])->references(['tenant_id', 'id'])->on('cash_bank_accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'business_unit_id'])->references(['tenant_id', 'id'])->on('business_units')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('completed_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'cash_bank_account_id', 'reference']);
            $table->index(['tenant_id', 'cash_bank_account_id', 'statement_date']);
        });
        DB::statement("ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_status_check CHECK (status IN ('OPEN','COMPLETED'))");
        DB::statement("ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement("ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_completed_check CHECK (
            (status = 'COMPLETED') = (completed_at IS NOT NULL AND completed_by IS NOT NULL AND book_balance IS NOT NULL AND unexplained_difference IS NOT NULL))");
        DB::statement('ALTER TABLE bank_statements ADD CONSTRAINT bank_statements_period_check CHECK (period_start IS NULL OR period_start <= statement_date)');

        Schema::create('bank_statement_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('bank_statement_id');
            $table->unsignedSmallInteger('line_number');
            $table->date('item_date');
            $table->string('description', 255);
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 20, 4); // signed from the book's view: deposits positive (a debit of the GL account), withdrawals negative (a credit)
            $table->string('status', 12)->default('UNMATCHED');
            $table->uuid('matched_journal_line_id')->nullable();
            $table->uuid('matched_by')->nullable();
            $table->timestampTz('matched_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'bank_statement_id'])->references(['tenant_id', 'id'])->on('bank_statements')->restrictOnDelete();
            $table->foreign('matched_journal_line_id')->references('id')->on('journal_lines')->restrictOnDelete();
            $table->foreign('matched_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['bank_statement_id', 'line_number']);
            $table->index(['tenant_id', 'bank_statement_id', 'status']);
        });
        DB::statement("ALTER TABLE bank_statement_items ADD CONSTRAINT bank_statement_items_status_check CHECK (status IN ('UNMATCHED','MATCHED','EXCEPTION'))");
        DB::statement('ALTER TABLE bank_statement_items ADD CONSTRAINT bank_statement_items_amount_check CHECK (amount <> 0)');
        DB::statement("ALTER TABLE bank_statement_items ADD CONSTRAINT bank_statement_items_match_check CHECK (
            (status = 'MATCHED') = (matched_journal_line_id IS NOT NULL AND matched_by IS NOT NULL AND matched_at IS NOT NULL)
            AND (status <> 'EXCEPTION' OR (notes IS NOT NULL AND btrim(notes) <> ''))
            AND (status = 'MATCHED' OR matched_journal_line_id IS NULL))");
        // A book line is matched to at most one statement item, ever (across statements).
        DB::statement('CREATE UNIQUE INDEX bank_statement_items_line_unique ON bank_statement_items (matched_journal_line_id) WHERE matched_journal_line_id IS NOT NULL');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bank_statements_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'OPEN' THEN
                        RAISE EXCEPTION 'a completed bank reconciliation cannot be deleted' USING ERRCODE = '23514';
                    END IF;
                    IF EXISTS (SELECT 1 FROM bank_statement_items WHERE tenant_id = OLD.tenant_id AND bank_statement_id = OLD.id AND status = 'MATCHED') THEN
                        RAISE EXCEPTION 'unmatch the items before deleting a bank statement' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;
                IF OLD.status = 'COMPLETED' THEN
                    RAISE EXCEPTION 'a completed bank reconciliation is final and cannot change' USING ERRCODE = '23514';
                END IF;
                IF NEW.status = 'COMPLETED' AND EXISTS (SELECT 1 FROM bank_statement_items WHERE tenant_id = NEW.tenant_id AND bank_statement_id = NEW.id AND status = 'UNMATCHED') THEN
                    RAISE EXCEPTION 'every statement item must be matched or flagged as an exception before the reconciliation is completed' USING ERRCODE = '23514';
                END IF;
                IF NEW.cash_bank_account_id <> OLD.cash_bank_account_id AND EXISTS (SELECT 1 FROM bank_statement_items WHERE tenant_id = OLD.tenant_id AND bank_statement_id = OLD.id) THEN
                    RAISE EXCEPTION 'the account of a statement with items cannot change' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER bank_statements_guard BEFORE UPDATE OR DELETE ON bank_statements FOR EACH ROW EXECUTE FUNCTION bank_statements_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION bank_statement_items_guard() RETURNS trigger AS $$
            DECLARE
                st bank_statements%ROWTYPE; acc cash_bank_accounts%ROWTYPE; jl journal_lines%ROWTYPE; je journal_entries%ROWTYPE;
                owner uuid; parent uuid;
            BEGIN
                IF TG_OP = 'DELETE' THEN owner := OLD.tenant_id; parent := OLD.bank_statement_id; ELSE owner := NEW.tenant_id; parent := NEW.bank_statement_id; END IF;
                SELECT * INTO st FROM bank_statements WHERE tenant_id = owner AND id = parent;
                IF FOUND AND st.status <> 'OPEN' THEN
                    RAISE EXCEPTION 'the items of a completed bank reconciliation cannot change' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status = 'MATCHED' THEN
                        RAISE EXCEPTION 'unmatch an item before deleting it' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;

                IF NEW.matched_journal_line_id IS NOT NULL THEN
                    SELECT * INTO jl FROM journal_lines WHERE id = NEW.matched_journal_line_id;
                    SELECT * INTO je FROM journal_entries WHERE tenant_id = jl.tenant_id AND id = jl.journal_entry_id;
                    SELECT * INTO acc FROM cash_bank_accounts WHERE tenant_id = st.tenant_id AND id = st.cash_bank_account_id;
                    IF jl.tenant_id <> NEW.tenant_id OR je.status <> 'POSTED' OR jl.account_id <> acc.account_id THEN
                        RAISE EXCEPTION 'a statement item matches a posted line of the GL account of its own bank account' USING ERRCODE = '23514';
                    END IF;
                    IF (NEW.amount > 0 AND jl.debit <> NEW.amount) OR (NEW.amount < 0 AND jl.credit <> -NEW.amount) THEN
                        RAISE EXCEPTION 'a statement item matches a book line of the same amount and direction' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER bank_statement_items_guard BEFORE INSERT OR UPDATE OR DELETE ON bank_statement_items FOR EACH ROW EXECUTE FUNCTION bank_statement_items_guard()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS bank_statement_items_guard ON bank_statement_items');
        DB::unprepared('DROP FUNCTION IF EXISTS bank_statement_items_guard()');
        Schema::dropIfExists('bank_statement_items');
        DB::unprepared('DROP TRIGGER IF EXISTS bank_statements_guard ON bank_statements');
        DB::unprepared('DROP FUNCTION IF EXISTS bank_statements_guard()');
        Schema::dropIfExists('bank_statements');
        DocumentGuards::drop('cash_transactions');
        DB::unprepared('DROP TRIGGER IF EXISTS cash_transactions_post_guard ON cash_transactions');
        DB::unprepared('DROP FUNCTION IF EXISTS cash_transactions_post_guard()');
        Schema::dropIfExists('cash_transactions');
    }
};
