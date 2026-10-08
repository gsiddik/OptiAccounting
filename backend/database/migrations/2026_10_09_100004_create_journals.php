<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA1 batches E-F: document sequences, journal entries and lines, dimensions on lines, transitions.
 * The database independently enforces the financial invariants the posting service also checks:
 * line shape, posted immutability, valid transitions, balance, account and period validity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('sequence_code', 40);
            $table->string('scope_key', 64)->default(''); // e.g. the fiscal year id: numbering restarts per scope
            $table->string('prefix', 20);
            $table->unsignedBigInteger('last_value')->default(0);
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->unique(['tenant_id', 'sequence_code', 'scope_key']);
        });

        Schema::create('journal_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('journal_number', 40)->nullable(); // issued at posting by DocumentNumbering, immutable afterwards
            $table->string('journal_type', 20);
            $table->string('status', 20)->default('DRAFT');
            $table->string('source_type', 40)->nullable(); // business fact that produced it (event journals)
            $table->string('source_id', 64)->nullable();
            $table->string('posting_purpose', 30)->default('POST');
            $table->date('document_date');
            $table->date('transaction_date')->nullable();
            $table->date('posting_date'); // decides the accounting period; never created_at
            $table->uuid('fiscal_year_id')->nullable(); // resolved at posting
            $table->uuid('accounting_period_id')->nullable();
            $table->string('description', 500);
            $table->string('reference', 100)->nullable();
            $table->char('currency', 3);
            $table->decimal('total_debit', 20, 4)->default(0); // server-computed from the lines
            $table->decimal('total_credit', 20, 4)->default(0);
            $table->uuid('created_by')->nullable(); // null = system
            $table->uuid('submitted_by')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('rejected_by')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->uuid('reverses_journal_id')->nullable();
            $table->uuid('reversed_by_journal_id')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->jsonb('posting_snapshot')->nullable(); // resolved rule version / role -> account facts of an event journal
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'fiscal_year_id'])->references(['tenant_id', 'id'])->on('fiscal_years')->restrictOnDelete();
            $table->foreign(['tenant_id', 'accounting_period_id'])->references(['tenant_id', 'id'])->on('accounting_periods')->restrictOnDelete();
            foreach (['created_by', 'submitted_by', 'approved_by', 'rejected_by', 'posted_by', 'cancelled_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->restrictOnDelete();
            }
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'status', 'posting_date']);
            $table->index(['tenant_id', 'created_by']);
        });
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'reverses_journal_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reversed_by_journal_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_type_check CHECK (journal_type IN ('MANUAL','OPENING','REVERSAL','SYSTEM'))");
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_status_check CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','REJECTED','POSTED','CANCELLED'))");
        DB::statement('ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_totals_check CHECK (total_debit >= 0 AND total_credit >= 0)');
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_posted_check CHECK (status <> 'POSTED' OR (
            journal_number IS NOT NULL AND posted_at IS NOT NULL AND fiscal_year_id IS NOT NULL AND accounting_period_id IS NOT NULL
            AND total_debit = total_credit AND total_debit > 0))");
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_reversal_check CHECK (
            (journal_type = 'REVERSAL') = (reverses_journal_id IS NOT NULL) AND reverses_journal_id IS DISTINCT FROM id AND reversed_by_journal_id IS DISTINCT FROM id)");
        DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
        // One number per tenant; at most one reversal per journal; one journal per business fact and purpose (idempotent posting).
        DB::statement('CREATE UNIQUE INDEX journal_entries_number_unique ON journal_entries (tenant_id, journal_number) WHERE journal_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX journal_entries_one_reversal ON journal_entries (tenant_id, reverses_journal_id) WHERE reverses_journal_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX journal_entries_source_unique ON journal_entries (tenant_id, source_type, source_id, posting_purpose) WHERE source_id IS NOT NULL');
        DB::statement("CREATE INDEX journal_entries_posted_date ON journal_entries (tenant_id, posting_date) WHERE status = 'POSTED'");

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('journal_entry_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('account_id');
            $table->string('description', 255)->nullable();
            $table->string('reference', 100)->nullable();
            // Functional-currency amounts: what the ledger sums. Exactly one side is positive.
            $table->decimal('debit', 20, 4)->default(0);
            $table->decimal('credit', 20, 4)->default(0);
            // Transaction-currency snapshot (rate 1 and the functional currency until OA4 adds multi-currency).
            $table->char('transaction_currency', 3);
            $table->decimal('transaction_debit', 20, 4)->default(0);
            $table->decimal('transaction_credit', 20, 4)->default(0);
            $table->decimal('exchange_rate', 20, 10)->default(1);
            $table->uuid('branch_id')->nullable();
            $table->uuid('business_unit_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'journal_entry_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign(['tenant_id', 'account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'branch_id'])->references(['tenant_id', 'id'])->on('branches')->restrictOnDelete();
            $table->foreign(['tenant_id', 'business_unit_id'])->references(['tenant_id', 'id'])->on('business_units')->restrictOnDelete();
            $table->foreign(['tenant_id', 'cost_center_id'])->references(['tenant_id', 'id'])->on('cost_centers')->restrictOnDelete();
            $table->unique(['journal_entry_id', 'line_number']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'account_id']);
            $table->index(['tenant_id', 'journal_entry_id']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'business_unit_id']);
            $table->index(['tenant_id', 'cost_center_id']);
        });
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_one_side CHECK (
            debit >= 0 AND credit >= 0 AND ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)))');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_transaction_side CHECK (
            transaction_debit >= 0 AND transaction_credit >= 0 AND (debit > 0) = (transaction_debit > 0) AND (credit > 0) = (transaction_credit > 0))');
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_rate_check CHECK (exchange_rate > 0)');

        // Reusable dimensions beyond branch / business unit / cost center (vehicle, work order, project, ...), by reference.
        Schema::create('journal_line_dimensions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('journal_line_id');
            $table->string('dimension_type', 40);
            $table->string('reference_id', 64);
            $table->string('reference_label')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'journal_line_id'])->references(['tenant_id', 'id'])->on('journal_lines')->cascadeOnDelete();
            $table->foreign('dimension_type')->references('code')->on('dimension_types')->restrictOnDelete();
            $table->unique(['journal_line_id', 'dimension_type']);
            $table->index(['tenant_id', 'dimension_type', 'reference_id']);
        });

        Schema::create('journal_transitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('journal_entry_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->uuid('actor_user_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'journal_entry_id'])->references(['tenant_id', 'id'])->on('journal_entries')->restrictOnDelete();
            $table->foreign('actor_user_id')->references('id')->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'journal_entry_id', 'occurred_at']);
        });

        $this->triggers();
    }

    private function triggers(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_entries_guard() RETURNS trigger AS $$
            DECLARE
                n integer; sd numeric; sc numeric; bad integer;
                p accounting_periods%ROWTYPE; fy_status text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status <> 'DRAFT' THEN
                        RAISE EXCEPTION 'only a draft journal can be deleted' USING ERRCODE = '23514';
                    END IF;
                    RETURN OLD;
                END IF;

                IF OLD.status = 'POSTED' THEN
                    -- the single allowed write: the reversal link, once
                    IF OLD.reversed_by_journal_id IS NOT NULL
                       OR NEW.reversed_by_journal_id IS NULL
                       OR (to_jsonb(NEW) - 'reversed_by_journal_id' - 'updated_at') IS DISTINCT FROM (to_jsonb(OLD) - 'reversed_by_journal_id' - 'updated_at') THEN
                        RAISE EXCEPTION 'a posted journal is immutable' USING ERRCODE = '23514';
                    END IF;
                    -- ...and only to the posted reversal journal that reverses exactly this journal
                    IF NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.tenant_id = NEW.tenant_id AND r.id = NEW.reversed_by_journal_id
                                   AND r.journal_type = 'REVERSAL' AND r.status = 'POSTED' AND r.reverses_journal_id = NEW.id) THEN
                        RAISE EXCEPTION 'a journal can only be linked to the posted reversal that reverses it' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;

                IF OLD.journal_number IS NOT NULL AND NEW.journal_number IS DISTINCT FROM OLD.journal_number THEN
                    RAISE EXCEPTION 'a journal number cannot change once issued' USING ERRCODE = '23514';
                END IF;

                IF NEW.status <> OLD.status AND NOT (
                    (OLD.status = 'DRAFT' AND NEW.status IN ('SUBMITTED','CANCELLED','POSTED'))
                    OR (OLD.status = 'SUBMITTED' AND NEW.status IN ('APPROVED','REJECTED','CANCELLED'))
                    OR (OLD.status = 'APPROVED' AND NEW.status IN ('POSTED','CANCELLED'))
                    OR (OLD.status = 'REJECTED' AND NEW.status IN ('DRAFT','CANCELLED'))) THEN
                    RAISE EXCEPTION 'journal status cannot move from % to %', OLD.status, NEW.status USING ERRCODE = '23514';
                END IF;

                IF NEW.status = 'POSTED' THEN
                    SELECT count(*), coalesce(sum(debit), 0), coalesce(sum(credit), 0) INTO n, sd, sc
                      FROM journal_lines WHERE tenant_id = NEW.tenant_id AND journal_entry_id = NEW.id;
                    IF n < 2 THEN
                        RAISE EXCEPTION 'a posted journal needs at least two lines' USING ERRCODE = '23514';
                    END IF;
                    IF sd <> sc OR sd <= 0 THEN
                        RAISE EXCEPTION 'a posted journal must balance (debit %, credit %)', sd, sc USING ERRCODE = '23514';
                    END IF;
                    IF sd <> NEW.total_debit OR sc <> NEW.total_credit THEN
                        RAISE EXCEPTION 'journal totals do not match its lines' USING ERRCODE = '23514';
                    END IF;
                    SELECT count(*) INTO bad FROM (
                        SELECT transaction_currency FROM journal_lines WHERE tenant_id = NEW.tenant_id AND journal_entry_id = NEW.id
                        GROUP BY transaction_currency HAVING sum(transaction_debit) <> sum(transaction_credit)) x;
                    IF bad > 0 THEN
                        RAISE EXCEPTION 'a posted journal must balance in every transaction currency' USING ERRCODE = '23514';
                    END IF;
                    SELECT count(*) INTO bad FROM journal_lines l
                      JOIN accounts a ON a.tenant_id = l.tenant_id AND a.id = l.account_id
                     WHERE l.tenant_id = NEW.tenant_id AND l.journal_entry_id = NEW.id
                       AND (a.status <> 'ACTIVE' OR NOT a.is_postable OR (NEW.journal_type = 'MANUAL' AND a.is_control));
                    IF bad > 0 THEN
                        RAISE EXCEPTION 'a posted journal can only use active posting accounts' USING ERRCODE = '23514';
                    END IF;
                    SELECT * INTO p FROM accounting_periods WHERE tenant_id = NEW.tenant_id AND id = NEW.accounting_period_id FOR SHARE;
                    IF NOT FOUND OR p.status NOT IN ('OPEN','SOFT_CLOSED') OR p.fiscal_year_id IS DISTINCT FROM NEW.fiscal_year_id
                       OR NEW.posting_date < p.start_date OR NEW.posting_date > p.end_date THEN
                        RAISE EXCEPTION 'the posting date is not in an open accounting period' USING ERRCODE = '23514';
                    END IF;
                    SELECT status INTO fy_status FROM fiscal_years WHERE tenant_id = NEW.tenant_id AND id = NEW.fiscal_year_id;
                    IF fy_status IS DISTINCT FROM 'OPEN' THEN
                        RAISE EXCEPTION 'the fiscal year is not open' USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER journal_entries_guard BEFORE UPDATE OR DELETE ON journal_entries FOR EACH ROW EXECUTE FUNCTION journal_entries_guard()');

        // Lines (and their dimensions) change only while the journal is a draft.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_lines_guard() RETURNS trigger AS $$
            DECLARE st text; je uuid; tn uuid;
            BEGIN
                IF TG_TABLE_NAME = 'journal_line_dimensions' THEN
                    tn := COALESCE(NEW.tenant_id, OLD.tenant_id);
                    SELECT journal_entry_id INTO je FROM journal_lines WHERE tenant_id = tn AND id = COALESCE(NEW.journal_line_id, OLD.journal_line_id);
                ELSE
                    tn := COALESCE(NEW.tenant_id, OLD.tenant_id);
                    je := COALESCE(NEW.journal_entry_id, OLD.journal_entry_id);
                END IF;
                SELECT status INTO st FROM journal_entries WHERE tenant_id = tn AND id = je;
                IF st IS DISTINCT FROM 'DRAFT' AND NOT (TG_OP = 'DELETE' AND st IS NULL) THEN
                    RAISE EXCEPTION 'journal lines can only change while the journal is a draft (now %)', st USING ERRCODE = '23514';
                END IF;
                RETURN COALESCE(NEW, OLD);
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER journal_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON journal_lines FOR EACH ROW EXECUTE FUNCTION journal_lines_guard()');
        DB::unprepared('CREATE TRIGGER journal_line_dimensions_guard BEFORE INSERT OR UPDATE OR DELETE ON journal_line_dimensions FOR EACH ROW EXECUTE FUNCTION journal_lines_guard()');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION journal_transitions_immutable() RETURNS trigger AS $$
            BEGIN RAISE EXCEPTION 'journal_transitions is append-only' USING ERRCODE = '23514'; END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER journal_transitions_append_only BEFORE UPDATE OR DELETE ON journal_transitions FOR EACH ROW EXECUTE FUNCTION journal_transitions_immutable()');

        // An account with journal history keeps its meaning: type, side, header/posting and currency cannot change.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION accounts_guard_used() RETURNS trigger AS $$
            BEGIN
                IF (NEW.account_type, NEW.normal_balance, NEW.is_postable, NEW.currency) IS DISTINCT FROM (OLD.account_type, OLD.normal_balance, OLD.is_postable, OLD.currency)
                   AND EXISTS (SELECT 1 FROM journal_lines WHERE tenant_id = OLD.tenant_id AND account_id = OLD.id) THEN
                    RAISE EXCEPTION 'account % has journal history; its type, normal balance and currency cannot change', OLD.code USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER accounts_guard_used BEFORE UPDATE ON accounts FOR EACH ROW EXECUTE FUNCTION accounts_guard_used()');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS accounts_guard_used ON accounts');
        DB::unprepared('DROP FUNCTION IF EXISTS accounts_guard_used()');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_transitions_append_only ON journal_transitions');
        DB::unprepared('DROP FUNCTION IF EXISTS journal_transitions_immutable()');
        Schema::dropIfExists('journal_transitions');
        Schema::dropIfExists('journal_line_dimensions');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_lines_guard ON journal_lines');
        Schema::dropIfExists('journal_lines');
        DB::unprepared('DROP FUNCTION IF EXISTS journal_lines_guard()');
        DB::unprepared('DROP TRIGGER IF EXISTS journal_entries_guard ON journal_entries');
        Schema::dropIfExists('journal_entries');
        DB::unprepared('DROP FUNCTION IF EXISTS journal_entries_guard()');
        Schema::dropIfExists('document_sequences');
    }
};
