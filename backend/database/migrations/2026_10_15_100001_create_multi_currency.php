<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA4 multi-currency. The ledger stays in the functional currency; a foreign amount is carried beside it as a snapshot.
 *
 *  - `currencies` and `exchange_rates`: tenant-owned masters. A rate is a fact entered once (value, date and type never change).
 *  - Documents: a foreign AP/AR invoice stores the rate it was recognised with (value, id, date, type) and its functional total; a foreign
 *    payment/receipt stores its settlement rate, the functional amount that moves through the bank and the realised difference. Each
 *    allocation stores the functional value it released from the invoice (`carrying_amount`, at the invoice's rate) and what settled it
 *    (`settlement_amount`, at the payment's rate). The columns are NULL for a functional-currency document, which behaves exactly as before.
 *  - Journal lines already carry a transaction-currency snapshot; `is_fx_difference` marks the realised gain/loss line, which exists only in
 *    functional currency and is therefore left out of the per-transaction-currency balance (the functional balance still covers it).
 *  - Guards that compared a document total with the journal total now compare the functional amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->masters();
        $this->journalLines();
        $this->documents();
        $this->allocations();
        $this->taxTransactions();
        $this->catalog();
        foreach ($this->patchList() as [$signature, $changes]) {
            $this->apply($signature, $changes);
        }
    }

    // ------------------------------------------------------------------------------------------------ masters

    private function masters(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->char('code', 3);
            $table->string('name', 100);
            $table->string('symbol', 10)->nullable();
            $table->unsignedSmallInteger('decimal_places')->default(2);
            $table->string('status', 10)->default('ACTIVE');
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'code']);
        });
        DB::statement("ALTER TABLE currencies ADD CONSTRAINT currencies_code_check CHECK (code ~ '^[A-Z]{3}\$')");
        DB::statement('ALTER TABLE currencies ADD CONSTRAINT currencies_places_check CHECK (decimal_places BETWEEN 0 AND 4)');
        DB::statement("ALTER TABLE currencies ADD CONSTRAINT currencies_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");
        // the code and precision are identity once a rate or a document uses the currency; the service explains, this repeats it
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION currencies_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.code <> OLD.code OR NEW.tenant_id <> OLD.tenant_id THEN
                    RAISE EXCEPTION 'the code of a currency cannot change' USING ERRCODE = '23514';
                END IF;
                IF NEW.decimal_places <> OLD.decimal_places AND EXISTS (SELECT 1 FROM exchange_rates r WHERE r.tenant_id = OLD.tenant_id AND r.from_currency = OLD.code) THEN
                    RAISE EXCEPTION 'a currency with rates cannot change its decimal places' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER currencies_guard BEFORE UPDATE ON currencies FOR EACH ROW EXECUTE FUNCTION currencies_guard()');

        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->char('from_currency', 3);
            $table->char('to_currency', 3); // always the functional currency of the tenant when the rate was entered
            $table->decimal('rate', 20, 10); // 1 unit of from_currency = rate units of to_currency
            $table->date('effective_date');
            $table->string('rate_type', 10)->default('MANUAL');
            $table->string('source', 100)->nullable();
            $table->string('notes', 255)->nullable();
            $table->string('status', 10)->default('ACTIVE');
            $table->jsonb('metadata')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->foreign('tenant_id')->references('id')->on('tenants')->restrictOnDelete();
            $table->foreign(['tenant_id', 'from_currency'])->references(['tenant_id', 'code'])->on('currencies')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'from_currency', 'to_currency', 'effective_date']);
        });
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_rate_check CHECK (rate > 0 AND from_currency <> to_currency)');
        DB::statement("ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_type_check CHECK (rate_type IN ('SPOT','DAILY','MONTH_END','MANUAL'))");
        DB::statement("ALTER TABLE exchange_rates ADD CONSTRAINT exchange_rates_status_check CHECK (status IN ('ACTIVE','INACTIVE'))");
        // one live rate per currency, date and type; a correction withdraws the old one first
        DB::statement("CREATE UNIQUE INDEX exchange_rates_active_unique ON exchange_rates (tenant_id, from_currency, to_currency, effective_date, rate_type) WHERE status = 'ACTIVE'");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION exchange_rates_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RETURN OLD;
                END IF;
                IF NEW.tenant_id <> OLD.tenant_id OR NEW.from_currency <> OLD.from_currency OR NEW.to_currency <> OLD.to_currency OR NEW.rate <> OLD.rate
                   OR NEW.effective_date <> OLD.effective_date OR NEW.rate_type <> OLD.rate_type THEN
                    RAISE EXCEPTION 'an exchange rate is a fact: withdraw it and enter another' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER exchange_rates_guard BEFORE UPDATE OR DELETE ON exchange_rates FOR EACH ROW EXECUTE FUNCTION exchange_rates_guard()');
    }

    // ------------------------------------------------------------------------------------------------ journal lines

    private function journalLines(): void
    {
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->boolean('is_fx_difference')->default(false);
        });
        // the realised difference is a functional amount: it has no foreign leg to balance
        DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT journal_lines_fx_difference_check CHECK (NOT is_fx_difference OR exchange_rate = 1)');
    }

    // ------------------------------------------------------------------------------------------------ documents

    private function documents(): void
    {
        foreach (['ap_invoices' => 'invoice', 'ar_invoices' => 'invoice', 'vendor_payments' => 'payment', 'customer_receipts' => 'payment'] as $table => $kind) {
            Schema::table($table, function (Blueprint $t) use ($kind) {
                $t->decimal('exchange_rate', 20, 10)->default(1);
                $t->uuid('exchange_rate_id')->nullable();
                $t->date('exchange_rate_date')->nullable();
                $t->string('exchange_rate_type', 10)->nullable();
                if ($kind === 'invoice') {
                    $t->decimal('functional_total_amount', 20, 4)->nullable(); // NULL = the invoice is in the functional currency
                } else {
                    $t->decimal('functional_amount', 20, 4)->nullable(); // what moved through the bank, in functional currency
                    $t->decimal('fx_difference', 20, 4)->nullable(); // settlement - carrying value: AP positive = loss, AR positive = gain
                }
            });
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->foreign(['tenant_id', 'exchange_rate_id'], "{$table}_exchange_rate_foreign")->references(['tenant_id', 'id'])->on('exchange_rates')->restrictOnDelete();
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_rate_check CHECK (exchange_rate > 0)");
        }
        foreach (['ap_invoices', 'ar_invoices'] as $table) {
            // a foreign invoice cites a rate and a functional total; a functional one cites neither
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_foreign_check CHECK (
                (functional_total_amount IS NULL AND exchange_rate = 1 AND exchange_rate_id IS NULL)
                OR (functional_total_amount IS NOT NULL AND functional_total_amount > 0 AND exchange_rate_id IS NOT NULL AND exchange_rate_date IS NOT NULL))");
        }
        foreach (['vendor_payments', 'customer_receipts'] as $table) {
            // a foreign draft knows its functional amount from the rate; the difference depends on what the invoices still carry at posting, so it is set when the payment posts
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_foreign_check CHECK (
                (functional_amount IS NULL AND fx_difference IS NULL AND exchange_rate = 1 AND exchange_rate_id IS NULL)
                OR (functional_amount IS NOT NULL AND functional_amount > 0 AND exchange_rate_id IS NOT NULL AND exchange_rate_date IS NOT NULL
                    AND (fx_difference IS NOT NULL OR status NOT IN ('POSTED','REVERSED'))))");
        }
    }

    // ------------------------------------------------------------------------------------------------ allocations

    private function allocations(): void
    {
        foreach (['ap_payment_allocations', 'ar_receipt_allocations'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->decimal('carrying_amount', 20, 4)->nullable(); // functional value released from the invoice, at the invoice's rate
                $t->decimal('settlement_amount', 20, 4)->nullable(); // functional value of what settled it, at the payment's rate
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_functional_check CHECK (
                (carrying_amount IS NULL) = (settlement_amount IS NULL) AND (carrying_amount IS NULL OR (carrying_amount >= 0 AND settlement_amount >= 0)))");
        }
    }

    // ------------------------------------------------------------------------------------------------ tax

    private function taxTransactions(): void
    {
        // The tax report adds up functional amounts; a foreign document freezes them with the transaction (NULL = the amounts are functional already).
        Schema::table('tax_transactions', function (Blueprint $table) {
            $table->char('currency', 3)->nullable();
            $table->decimal('exchange_rate', 20, 10)->nullable();
            $table->decimal('functional_base_amount', 20, 4)->nullable();
            $table->decimal('functional_tax_amount', 20, 4)->nullable();
        });
        DB::statement('ALTER TABLE tax_transactions ADD CONSTRAINT tax_transactions_functional_check CHECK (
            (currency IS NULL AND exchange_rate IS NULL AND functional_base_amount IS NULL AND functional_tax_amount IS NULL)
            OR (currency IS NOT NULL AND exchange_rate > 0 AND functional_base_amount >= 0 AND functional_tax_amount >= 0))');
    }

    // ------------------------------------------------------------------------------------------------ catalog

    /** The roles and events of realised FX for installations that already exist (a fresh database gets the same rows from the catalog seeder). */
    private function catalog(): void
    {
        $now = now();
        foreach ([['FX_GAIN', 'Laba selisih kurs (terealisasi)', 160, ['VENDOR_PAYMENT_FX', 'CUSTOMER_RECEIPT_FX']], ['FX_LOSS', 'Rugi selisih kurs (terealisasi)', 170, ['VENDOR_PAYMENT_FX', 'CUSTOMER_RECEIPT_FX']]] as [$code, $name, $order, $restricted]) {
            DB::table('account_roles')->insertOrIgnore(['code' => $code, 'name' => $name, 'status' => 'ACTIVE', 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
            DB::table('account_roles')->where('code', $code)->whereNull('restricted_events')->update(['restricted_events' => json_encode($restricted)]);
        }
        foreach ([
            ['VENDOR_PAYMENT_FX', 'Pembayaran vendor mata uang asing', 'Komponen: carrying, settlement, fx_gain, fx_loss. Diaktifkan oleh OA4.', ['carrying', 'settlement', 'fx_gain', 'fx_loss'], 150],
            ['CUSTOMER_RECEIPT_FX', 'Penerimaan pelanggan mata uang asing', 'Komponen: carrying, settlement, fx_gain, fx_loss. Diaktifkan oleh OA4.', ['carrying', 'settlement', 'fx_gain', 'fx_loss'], 160],
        ] as [$code, $name, $description, $components, $order]) {
            DB::table('accounting_event_types')->insertOrIgnore([
                'code' => $code, 'name' => $name, 'description' => $description, 'components' => json_encode($components),
                'status' => 'ACTIVE', 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        // the subledger control roles may be used by the foreign settlement events of their own subledger
        foreach (['ACCOUNTS_PAYABLE' => 'VENDOR_PAYMENT_FX', 'ACCOUNTS_RECEIVABLE' => 'CUSTOMER_RECEIPT_FX'] as $role => $event) {
            $restricted = json_decode((string) DB::table('account_roles')->where('code', $role)->value('restricted_events'), true);
            if (is_array($restricted) && ! in_array($event, $restricted, true)) {
                DB::table('account_roles')->where('code', $role)->update(['restricted_events' => json_encode([...$restricted, $event])]);
            }
        }
    }

    // ------------------------------------------------------------------------------------------------ guards

    /**
     * The changes to existing trigger functions, in order: function signature, then each text in its current body => the text that replaces it.
     *
     * @return list<array{0:string,1:array<string,string>}>
     */
    private function patchList(): array
    {
        // the per-currency balance of a posted journal leaves out the realised difference line (the functional balance still covers it)
        $patches = [['journal_entries_guard()', [
            'SELECT transaction_currency FROM journal_lines WHERE tenant_id = NEW.tenant_id AND journal_entry_id = NEW.id' => 'SELECT transaction_currency FROM journal_lines WHERE tenant_id = NEW.tenant_id AND journal_entry_id = NEW.id AND NOT is_fx_difference',
        ]]];

        // the posted journal carries the functional value of the document
        $patches[] = ['ap_invoices_post_guard()', ['j.total_credit <> NEW.total_amount' => 'j.total_credit <> coalesce(NEW.functional_total_amount, NEW.total_amount)']];
        $patches[] = ['ar_invoices_post_guard()', ['j.total_debit <> NEW.total_amount' => 'j.total_debit <> coalesce(NEW.functional_total_amount, NEW.total_amount)']];
        // a payment's journal balances at the larger of the carrying value and the settlement value: with a loss the cash side is the total, with a gain the payable side is
        $payment = ['j.total_credit <> NEW.amount OR j.total_debit <> NEW.amount' => 'j.total_credit <> j.total_debit OR j.total_debit <> coalesce(NEW.functional_amount, NEW.amount) + greatest(0, -coalesce(NEW.fx_difference, 0))'];
        $patches[] = ['vendor_payments_post_guard()', $payment];
        $patches[] = ['customer_receipts_post_guard()', $payment];

        foreach (['ap_payment_allocations' => ['vendor_payment_id', 'vendor_payments_allocation_check()', 'ap_payment_allocations_guard()'],
            'ar_receipt_allocations' => ['customer_receipt_id', 'customer_receipts_allocation_check()', 'ar_receipt_allocations_guard()']] as $table => [$key, $check, $guard]) {
            // once the allocation took effect its functional values are history
            $patches[] = [$guard, ['NEW.amount <> OLD.amount OR NEW.tenant_id <> OLD.tenant_id THEN' => 'NEW.amount <> OLD.amount OR NEW.tenant_id <> OLD.tenant_id
                   OR (OLD.effective_at IS NOT NULL AND (NEW.carrying_amount IS DISTINCT FROM OLD.carrying_amount OR NEW.settlement_amount IS DISTINCT FROM OLD.settlement_amount)) THEN']];
            // at commit a posted foreign payment settles exactly its functional amount and carries exactly its difference
            $patches[] = [$check, ["IF cur.status <> 'POSTED' AND effective <> 0 THEN" => "IF cur.status = 'POSTED' AND cur.functional_amount IS NOT NULL AND (
                    (SELECT coalesce(sum(settlement_amount), 0) FROM {$table} WHERE tenant_id = cur.tenant_id AND {$key} = cur.id AND is_effective) <> cur.functional_amount
                    OR (SELECT coalesce(sum(settlement_amount - carrying_amount), 0) FROM {$table} WHERE tenant_id = cur.tenant_id AND {$key} = cur.id AND is_effective) <> cur.fx_difference) THEN
                    RAISE EXCEPTION 'a posted foreign payment must settle its functional amount and carry its exchange difference' USING ERRCODE = '23514';
                END IF;
                IF cur.status <> 'POSTED' AND effective <> 0 THEN"]];
        }

        return $patches;
    }

    /**
     * Re-create a trigger function with small, named changes. The current definition is read back from the database and each replaced text
     * must occur exactly once, so a guard is never replaced by a stale copy of itself and a drifted definition fails the migration loudly.
     *
     * @param  array<string,string>  $changes  text in the current body => new text
     */
    private function apply(string $signature, array $changes): void
    {
        $definition = DB::selectOne('select pg_get_functiondef(?::regprocedure) as definition', [$signature])->definition;
        foreach ($changes as $from => $to) {
            if (substr_count($definition, $from) !== 1) {
                throw new RuntimeException("Cannot patch {$signature}: the expected text was not found exactly once ({$from}).");
            }
            $definition = str_replace($from, $to, $definition);
        }
        DB::unprepared($definition);
    }

    public function down(): void
    {
        foreach (array_reverse($this->patchList()) as [$signature, $changes]) {
            $this->apply($signature, array_flip($changes));
        }

        DB::table('account_roles')->whereIn('code', ['FX_GAIN', 'FX_LOSS'])->update(['restricted_events' => null]);
        DB::statement('ALTER TABLE tax_transactions DROP CONSTRAINT IF EXISTS tax_transactions_functional_check');
        Schema::table('tax_transactions', fn (Blueprint $table) => $table->dropColumn(['currency', 'exchange_rate', 'functional_base_amount', 'functional_tax_amount']));
        foreach (['ap_payment_allocations', 'ar_receipt_allocations'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_functional_check");
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['carrying_amount', 'settlement_amount']));
        }
        foreach (['ap_invoices', 'ar_invoices', 'vendor_payments', 'customer_receipts'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_foreign_check");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_rate_check");
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropForeign("{$table}_exchange_rate_foreign");
                $t->dropColumn(['exchange_rate', 'exchange_rate_id', 'exchange_rate_date', 'exchange_rate_type'] + (str_ends_with($table, 'invoices') ? [4 => 'functional_total_amount'] : [4 => 'functional_amount', 5 => 'fx_difference']));
            });
        }
        DB::statement('ALTER TABLE journal_lines DROP CONSTRAINT IF EXISTS journal_lines_fx_difference_check');
        Schema::table('journal_lines', fn (Blueprint $table) => $table->dropColumn('is_fx_difference'));
        DB::unprepared('DROP TRIGGER IF EXISTS exchange_rates_guard ON exchange_rates');
        DB::unprepared('DROP FUNCTION IF EXISTS exchange_rates_guard()');
        Schema::dropIfExists('exchange_rates');
        DB::unprepared('DROP TRIGGER IF EXISTS currencies_guard ON currencies');
        DB::unprepared('DROP FUNCTION IF EXISTS currencies_guard()');
        Schema::dropIfExists('currencies');
        // The catalog rows (roles, event types) may already be referenced by rules, mappings and posted events; a rollback never deletes them.
    }
};
