<?php

use App\Support\Database\DocumentGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA2 batch D: vendor payments and their allocations to vendor invoices.
 * A payment settles posted payables only: its allocations become effective in the transaction that posts it, and are released when it
 * is reversed. Outstanding = invoice total - effective allocations; nothing else stores a payable balance. The database repeats the
 * rules: an allocation never exceeds what is left on the invoice (under the invoice row lock), a posted payment is allocated in full,
 * and neither a posted payment nor an effective allocation can be edited.
 */
return new class extends Migration
{
    private const REVERSAL = ['reversal_journal_id', 'reversed_by', 'reversed_at', 'reversal_reason', 'reversal_posting_date'];

    public function up(): void
    {
        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('document_number', 40)->nullable(); // issued at posting
            $table->uuid('vendor_id');
            $table->uuid('cash_bank_account_id');
            $table->uuid('gl_account_id')->nullable(); // the GL account credited, fixed at posting
            $table->date('payment_date');
            $table->date('posting_date');
            $table->char('currency', 3);
            $table->decimal('amount', 20, 4);
            $table->string('payment_method', 20)->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('description', 500)->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('business_unit_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
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
            $table->index(['tenant_id', 'vendor_id', 'status']);
            $table->index(['tenant_id', 'cash_bank_account_id', 'status']);
            $table->index(['tenant_id', 'created_by']);
        });
        DB::statement("ALTER TABLE vendor_payments ADD CONSTRAINT vendor_payments_status_check CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','REJECTED','POSTED','CANCELLED','REVERSED'))");
        DB::statement('ALTER TABLE vendor_payments ADD CONSTRAINT vendor_payments_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE vendor_payments ADD CONSTRAINT vendor_payments_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        DB::statement("ALTER TABLE vendor_payments ADD CONSTRAINT vendor_payments_posted_check CHECK (status NOT IN ('POSTED','REVERSED') OR (
            document_number IS NOT NULL AND posted_at IS NOT NULL AND journal_entry_id IS NOT NULL AND gl_account_id IS NOT NULL))");
        DB::statement("ALTER TABLE vendor_payments ADD CONSTRAINT vendor_payments_reversed_check CHECK (
            (status = 'REVERSED') = (reversal_journal_id IS NOT NULL AND reversed_at IS NOT NULL AND reversal_posting_date IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX vendor_payments_number_unique ON vendor_payments (tenant_id, document_number) WHERE document_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX vendor_payments_journal_unique ON vendor_payments (tenant_id, journal_entry_id) WHERE journal_entry_id IS NOT NULL');
        DB::statement('CREATE INDEX vendor_payments_reversal_journal ON vendor_payments (tenant_id, reversal_journal_id) WHERE reversal_journal_id IS NOT NULL');

        // A posted payment carries the posted journal of exactly its amount; a reversed one the posted reversal of that journal.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION vendor_payments_post_guard() RETURNS trigger AS $$
            DECLARE j journal_entries%ROWTYPE;
            BEGIN
                IF NEW.status = 'POSTED' AND OLD.status IS DISTINCT FROM 'POSTED' THEN
                    SELECT * INTO j FROM journal_entries WHERE tenant_id = NEW.tenant_id AND id = NEW.journal_entry_id;
                    IF NOT FOUND OR j.status <> 'POSTED' OR j.total_credit <> NEW.amount OR j.total_debit <> NEW.amount OR j.posting_date <> NEW.posting_date THEN
                        RAISE EXCEPTION 'a posted payment needs the posted journal of exactly its amount and posting date' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF NEW.status = 'REVERSED' AND OLD.status = 'POSTED' THEN
                    IF NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.tenant_id = NEW.tenant_id AND r.id = NEW.reversal_journal_id
                                   AND r.status = 'POSTED' AND r.reverses_journal_id = NEW.journal_entry_id) THEN
                        RAISE EXCEPTION 'a payment is reversed only by the posted reversal of its own journal' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER vendor_payments_post_guard BEFORE UPDATE ON vendor_payments FOR EACH ROW EXECUTE FUNCTION vendor_payments_post_guard()');
        DocumentGuards::guardDocument('vendor_payments', self::REVERSAL);

        Schema::create('ap_payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('vendor_payment_id');
            $table->uuid('ap_invoice_id');
            $table->decimal('amount', 20, 4);
            $table->boolean('is_effective')->default(false); // true from the posting of the payment until its reversal
            $table->timestampTz('effective_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'vendor_payment_id'])->references(['tenant_id', 'id'])->on('vendor_payments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'ap_invoice_id'])->references(['tenant_id', 'id'])->on('ap_invoices')->restrictOnDelete();
            $table->unique(['vendor_payment_id', 'ap_invoice_id']);
            $table->index(['tenant_id', 'ap_invoice_id', 'is_effective']);
            $table->index(['tenant_id', 'vendor_payment_id']);
        });
        DB::statement('ALTER TABLE ap_payment_allocations ADD CONSTRAINT ap_payment_allocations_amount_check CHECK (amount > 0)');
        DB::statement('ALTER TABLE ap_payment_allocations ADD CONSTRAINT ap_payment_allocations_state_check CHECK (
            (NOT is_effective OR (effective_at IS NOT NULL AND released_at IS NULL))
            AND (released_at IS NULL OR (effective_at IS NOT NULL AND NOT is_effective)))');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION ap_payment_allocations_guard() RETURNS trigger AS $$
            DECLARE pay vendor_payments%ROWTYPE; inv ap_invoices%ROWTYPE; used numeric;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    SELECT * INTO pay FROM vendor_payments WHERE tenant_id = OLD.tenant_id AND id = OLD.vendor_payment_id;
                    IF OLD.is_effective OR OLD.released_at IS NOT NULL OR (FOUND AND pay.status <> 'DRAFT') THEN
                        RAISE EXCEPTION 'an allocation of a posted payment cannot be deleted' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;

                SELECT * INTO pay FROM vendor_payments WHERE tenant_id = NEW.tenant_id AND id = NEW.vendor_payment_id;

                IF TG_OP = 'INSERT' THEN
                    IF pay.status <> 'DRAFT' OR NEW.is_effective OR NEW.effective_at IS NOT NULL OR NEW.released_at IS NOT NULL THEN
                        RAISE EXCEPTION 'allocations are prepared on a draft payment only' USING ERRCODE = '23514';
                    END IF;
                    SELECT * INTO inv FROM ap_invoices WHERE tenant_id = NEW.tenant_id AND id = NEW.ap_invoice_id;
                    IF inv.vendor_id <> pay.vendor_id THEN
                        RAISE EXCEPTION 'a payment settles invoices of its own vendor only' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;

                -- UPDATE: identity and amount are fixed; only the effective flag moves, and only with the payment.
                IF NEW.vendor_payment_id <> OLD.vendor_payment_id OR NEW.ap_invoice_id <> OLD.ap_invoice_id OR NEW.amount <> OLD.amount OR NEW.tenant_id <> OLD.tenant_id THEN
                    RAISE EXCEPTION 'an allocation can change only while its payment is a draft' USING ERRCODE = '23514';
                END IF;
                IF NEW.is_effective AND NOT OLD.is_effective THEN
                    IF OLD.released_at IS NOT NULL OR pay.status <> 'POSTED' THEN
                        RAISE EXCEPTION 'an allocation becomes effective with the posting of its payment, once' USING ERRCODE = '23514';
                    END IF;
                    SELECT * INTO inv FROM ap_invoices WHERE tenant_id = NEW.tenant_id AND id = NEW.ap_invoice_id FOR UPDATE;
                    IF inv.status <> 'POSTED' THEN
                        RAISE EXCEPTION 'only a posted invoice can be settled' USING ERRCODE = '23514';
                    END IF;
                    SELECT coalesce(sum(amount), 0) INTO used FROM ap_payment_allocations WHERE tenant_id = NEW.tenant_id AND ap_invoice_id = NEW.ap_invoice_id AND is_effective AND id <> NEW.id;
                    IF used + NEW.amount > inv.total_amount THEN
                        RAISE EXCEPTION 'the allocation exceeds what is outstanding on the invoice' USING ERRCODE = '23514';
                    END IF;
                ELSIF NOT NEW.is_effective AND OLD.is_effective THEN
                    IF pay.status <> 'REVERSED' OR NEW.released_at IS NULL THEN
                        RAISE EXCEPTION 'an allocation is released only by the reversal of its payment' USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.is_effective IS DISTINCT FROM OLD.is_effective OR NEW.effective_at IS DISTINCT FROM OLD.effective_at OR NEW.released_at IS DISTINCT FROM OLD.released_at THEN
                    RAISE EXCEPTION 'an allocation cannot be edited' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER ap_payment_allocations_guard BEFORE INSERT OR UPDATE OR DELETE ON ap_payment_allocations FOR EACH ROW EXECUTE FUNCTION ap_payment_allocations_guard()');

        // At commit: a posted payment is allocated in full by effective allocations, and no other payment holds any.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION vendor_payments_allocation_check() RETURNS trigger AS $$
            DECLARE cur vendor_payments%ROWTYPE; effective numeric;
            BEGIN
                -- Deferred: judge the row as it is at commit, not the (older) version that queued this event.
                SELECT * INTO cur FROM vendor_payments WHERE tenant_id = NEW.tenant_id AND id = NEW.id;
                IF NOT FOUND THEN RETURN NULL; END IF;
                SELECT coalesce(sum(amount), 0) INTO effective FROM ap_payment_allocations WHERE tenant_id = cur.tenant_id AND vendor_payment_id = cur.id AND is_effective;
                IF cur.status = 'POSTED' AND effective <> cur.amount THEN
                    RAISE EXCEPTION 'a posted payment must be allocated in full to posted invoices' USING ERRCODE = '23514';
                END IF;
                IF cur.status <> 'POSTED' AND effective <> 0 THEN
                    RAISE EXCEPTION 'only a posted payment holds effective allocations' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE CONSTRAINT TRIGGER vendor_payments_allocation_check AFTER INSERT OR UPDATE ON vendor_payments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION vendor_payments_allocation_check()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS vendor_payments_allocation_check ON vendor_payments');
        DB::unprepared('DROP FUNCTION IF EXISTS vendor_payments_allocation_check()');
        DB::unprepared('DROP TRIGGER IF EXISTS ap_payment_allocations_guard ON ap_payment_allocations');
        DB::unprepared('DROP FUNCTION IF EXISTS ap_payment_allocations_guard()');
        Schema::dropIfExists('ap_payment_allocations');
        DocumentGuards::drop('vendor_payments');
        DB::unprepared('DROP TRIGGER IF EXISTS vendor_payments_post_guard ON vendor_payments');
        DB::unprepared('DROP FUNCTION IF EXISTS vendor_payments_post_guard()');
        Schema::dropIfExists('vendor_payments');
    }
};
