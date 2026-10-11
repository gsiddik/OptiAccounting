<?php

use App\Support\Database\DocumentGuards;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OA4 batch E: asset disposal (sale, scrap or write-off) as a source document with its own approval workflow. Posting records the
 * cost, the accumulated depreciation, the net book value, the proceeds and the resulting gain or loss as a snapshot on the document, and
 * posts them through the Posting Engine. Nothing is deleted: the asset stays in the register as DISPOSED with its full depreciation
 * history. The database repeats the invariants: one live disposal per asset, a posted disposal has the arithmetic that balances
 * (cost + gain = accumulated + proceeds + loss), the posted journal of exactly the cost, and the asset it belongs to.
 */
return new class extends Migration
{
    private const REVERSAL = ['reversal_journal_id', 'reversed_by', 'reversed_at', 'reversal_reason', 'reversal_posting_date'];

    public function up(): void
    {
        Schema::create('asset_disposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('document_number', 40)->nullable(); // issued at posting
            $table->uuid('fixed_asset_id');
            $table->string('disposal_type', 10); // SALE | SCRAP
            $table->date('document_date');
            $table->date('disposal_date');
            $table->date('posting_date');
            $table->decimal('proceeds_amount', 20, 4)->default(0);
            $table->uuid('proceeds_account_id')->nullable(); // where the proceeds land (cash, bank, other receivable)
            $table->string('reason', 500);
            $table->string('reference', 100)->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('business_unit_id')->nullable();
            $table->uuid('cost_center_id')->nullable();
            // Snapshot taken at posting.
            $table->decimal('cost_amount', 20, 4)->nullable();
            $table->decimal('accumulated_depreciation', 20, 4)->nullable();
            $table->decimal('book_value', 20, 4)->nullable();
            $table->decimal('gain_amount', 20, 4)->nullable();
            $table->decimal('loss_amount', 20, 4)->nullable();
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
            $table->foreign(['tenant_id', 'fixed_asset_id'])->references(['tenant_id', 'id'])->on('fixed_assets')->restrictOnDelete();
            $table->foreign(['tenant_id', 'proceeds_account_id'])->references(['tenant_id', 'id'])->on('accounts')->restrictOnDelete();
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
            $table->index(['tenant_id', 'fixed_asset_id', 'status']);
            $table->index(['tenant_id', 'created_by']);
        });
        DB::statement("ALTER TABLE asset_disposals ADD CONSTRAINT asset_disposals_status_check CHECK (status IN ('DRAFT','SUBMITTED','APPROVED','REJECTED','POSTED','CANCELLED','REVERSED'))");
        DB::statement("ALTER TABLE asset_disposals ADD CONSTRAINT asset_disposals_type_check CHECK (disposal_type IN ('SALE','SCRAP'))");
        DB::statement('ALTER TABLE asset_disposals ADD CONSTRAINT asset_disposals_proceeds_check CHECK (proceeds_amount >= 0 AND (proceeds_amount = 0 OR proceeds_account_id IS NOT NULL))');
        DB::statement('ALTER TABLE asset_disposals ADD CONSTRAINT asset_disposals_dates_check CHECK (posting_date >= disposal_date)');
        DB::statement("ALTER TABLE asset_disposals ADD CONSTRAINT asset_disposals_posted_check CHECK (status NOT IN ('POSTED','REVERSED') OR (
            document_number IS NOT NULL AND posted_at IS NOT NULL AND journal_entry_id IS NOT NULL AND cost_amount > 0 AND accumulated_depreciation >= 0
            AND book_value = cost_amount - accumulated_depreciation AND gain_amount >= 0 AND loss_amount >= 0 AND (gain_amount = 0 OR loss_amount = 0)
            AND gain_amount - loss_amount = proceeds_amount - book_value))");
        DB::statement("ALTER TABLE asset_disposals ADD CONSTRAINT asset_disposals_reversed_check CHECK (
            (status = 'REVERSED') = (reversal_journal_id IS NOT NULL AND reversed_at IS NOT NULL AND reversal_posting_date IS NOT NULL))");
        DB::statement('CREATE UNIQUE INDEX asset_disposals_number_unique ON asset_disposals (tenant_id, document_number) WHERE document_number IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX asset_disposals_journal_unique ON asset_disposals (tenant_id, journal_entry_id) WHERE journal_entry_id IS NOT NULL');
        // One live disposal per asset: concurrent drafts for the same asset cannot both go through.
        DB::statement("CREATE UNIQUE INDEX asset_disposals_one_live ON asset_disposals (tenant_id, fixed_asset_id) WHERE status IN ('DRAFT','SUBMITTED','APPROVED','POSTED')");

        // The asset names its disposal; the disposal belongs to a capitalized asset and cannot precede its capitalization.
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->foreign(['tenant_id', 'disposal_id'])->references(['tenant_id', 'id'])->on('asset_disposals')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION asset_disposals_link_guard() RETURNS trigger AS $$
            DECLARE a fixed_assets%ROWTYPE;
            BEGIN
                IF TG_OP = 'INSERT' AND NEW.status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'a disposal starts as a draft' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND NEW.fixed_asset_id <> OLD.fixed_asset_id AND OLD.status <> 'DRAFT' THEN
                    RAISE EXCEPTION 'the asset of a disposal cannot change after the draft' USING ERRCODE = '23514';
                END IF;
                SELECT * INTO a FROM fixed_assets WHERE tenant_id = NEW.tenant_id AND id = NEW.fixed_asset_id;
                IF NOT FOUND OR a.capitalization_date > NEW.disposal_date THEN
                    RAISE EXCEPTION 'an asset cannot be disposed before its capitalization date' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER asset_disposals_link_guard BEFORE INSERT OR UPDATE ON asset_disposals FOR EACH ROW EXECUTE FUNCTION asset_disposals_link_guard()');

        // Posting: the posted journal of exactly the cost and date, an asset that is capitalized and not yet disposed.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION asset_disposals_post_guard() RETURNS trigger AS $$
            DECLARE j journal_entries%ROWTYPE; a fixed_assets%ROWTYPE;
            BEGIN
                IF NEW.status = 'POSTED' AND OLD.status IS DISTINCT FROM 'POSTED' THEN
                    SELECT * INTO j FROM journal_entries WHERE tenant_id = NEW.tenant_id AND id = NEW.journal_entry_id;
                    IF NOT FOUND OR j.status <> 'POSTED' OR j.posting_date <> NEW.posting_date OR j.total_debit <> j.total_credit THEN
                        RAISE EXCEPTION 'a posted disposal needs the posted journal of its posting date' USING ERRCODE = '23514';
                    END IF;
                    SELECT * INTO a FROM fixed_assets WHERE tenant_id = NEW.tenant_id AND id = NEW.fixed_asset_id FOR UPDATE;
                    IF NOT FOUND OR a.status NOT IN ('ACTIVE', 'FULLY_DEPRECIATED') THEN
                        RAISE EXCEPTION 'only a capitalized asset that is not yet disposed can be disposed' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.cost_amount <> a.acquisition_cost OR NEW.accumulated_depreciation <> a.accumulated_depreciation THEN
                        RAISE EXCEPTION 'the disposal snapshot must match the register at posting' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF NEW.status = 'REVERSED' AND OLD.status = 'POSTED' THEN
                    IF NOT EXISTS (SELECT 1 FROM journal_entries r WHERE r.tenant_id = NEW.tenant_id AND r.id = NEW.reversal_journal_id
                                   AND r.status = 'POSTED' AND r.reverses_journal_id = NEW.journal_entry_id) THEN
                        RAISE EXCEPTION 'a disposal is reversed only by the posted reversal of its own journal' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END; $$ LANGUAGE plpgsql
            SQL);
        DB::unprepared('CREATE TRIGGER asset_disposals_post_guard BEFORE UPDATE ON asset_disposals FOR EACH ROW EXECUTE FUNCTION asset_disposals_post_guard()');
        DocumentGuards::guardDocument('asset_disposals', self::REVERSAL);
    }

    public function down(): void
    {
        DocumentGuards::drop('asset_disposals');
        DB::unprepared('DROP TRIGGER IF EXISTS asset_disposals_post_guard ON asset_disposals');
        DB::unprepared('DROP FUNCTION IF EXISTS asset_disposals_post_guard()');
        DB::unprepared('DROP TRIGGER IF EXISTS asset_disposals_link_guard ON asset_disposals');
        DB::unprepared('DROP FUNCTION IF EXISTS asset_disposals_link_guard()');
        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'disposal_id']);
        });
        Schema::dropIfExists('asset_disposals');
    }
};
