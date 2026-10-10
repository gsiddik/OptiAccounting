<?php

namespace App\Domain\Tax\Services;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\ActorAuthority;
use App\Domain\Accounting\Support\Money;
use App\Domain\Currency\Services\ResolvedRate;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\DomainException;
use App\Domain\Tax\Models\TaxCode;
use App\Domain\Tax\Models\TaxRate;
use App\Domain\Tax\Models\TaxTransaction;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The seam between the tax configuration and the source documents (AP invoice, expense, AR invoice). A document never calculates tax:
 *
 *   prepare   apply()    turn the lines that name a tax code into base + tax (TaxCalculator, rate resolved on the tax date)
 *   save      sync()     keep the DRAFT tax transactions in step with the saved lines
 *   approve   assertCurrent()  the configuration still gives the amounts the document shows (else TAX_CONFIGURATION_CHANGED)
 *   post      postingParts()   the tax the Posting Engine books, by account, and any non-recoverable tax folded into the cost
 *             markPosted()     freeze the snapshot with the journal and the document number
 *   reverse   markReversed()
 *
 * A tax code is "unmapped" nowhere: the account a tax posts to is the code's role (resolved through the tenant's mapping by the engine) or
 * its explicit account; this class never names an account number. The tax point is the document's transaction date (document date of an
 * invoice, expense date of an expense); the rate in force on that date is used, whatever the posting date.
 */
class TaxDocumentService
{
    public const INPUT = 'INPUT';

    public const OUTPUT = 'OUTPUT';

    public function __construct(private readonly ActorAuthority $authority, private readonly TenantContext $context) {}

    // ------------------------------------------------------------------------------------------------ prepare

    /**
     * @param  self::INPUT|self::OUTPUT  $direction
     * @param  list<array<string,mixed>>  $lines  each with `amount` (BigDecimal, as entered) and optionally `tax_code_id`
     */
    public function apply(string $direction, array $lines, string $taxDate, int $scale, User $actor): TaxApplication
    {
        $wanted = array_values(array_unique(array_filter(array_column($lines, 'tax_code_id'))));
        if ($wanted === []) {
            return new TaxApplication($lines, [], BigDecimal::zero());
        }
        $this->authority->assertModuleWritable($actor, 'ACCOUNTING_TAX', 'TAX_CONFIGURATION');

        $codes = TaxCode::query()->whereIn('id', array_filter($wanted, fn ($id) => is_string($id) && Str::isUuid($id)))->get()->keyBy('id');
        $rates = [];
        $facts = [];
        $total = BigDecimal::zero();
        foreach ($lines as $i => $line) {
            $id = $line['tax_code_id'] ?? null;
            if ($id === null) {
                continue;
            }
            $n = $i + 1;
            $code = $codes[$id] ?? throw new DomainException('The tax code does not exist.', 'TAX_CODE_NOT_FOUND', 422, ['field' => 'tax_code_id', 'line' => $n]);
            $this->assertUsable($code, $direction, $n);
            $rate = $rates[$code->id] ??= $this->rateOn($code, $taxDate) ?? throw new DomainException("Tax code {$code->code} has no rate on {$taxDate}.", 'TAX_RATE_NOT_FOUND', 422, ['field' => 'tax_code_id', 'line' => $n, 'tax_code' => $code->code, 'date' => $taxDate]);

            $entered = $line['amount'] instanceof BigDecimal ? $line['amount'] : Money::parse($line['amount'], $scale, 'amount', $n);
            $calc = TaxCalculator::calculate($code->calculation_method, $code->treatment, $entered, BigDecimal::of($rate->rate), $scale);
            if (! $calc['base']->isPositive()) {
                throw new DomainException('The amount of a taxed line must leave a base greater than zero.', 'TAX_BASE_INVALID', 422, ['line' => $n]);
            }
            $lines[$i]['entered_amount'] = $calc['entered'];
            $lines[$i]['amount'] = $calc['base'];
            $facts[$i] = ['code' => $code, 'rate' => $rate, 'calc' => $calc];
            $total = $total->plus($calc['tax']);
        }

        return new TaxApplication($lines, $facts, $total);
    }

    /**
     * The header tax of a document. With tax codes on its lines the tax is the sum the codes give; the header cannot also carry a manual tax
     * or a discount (a discount would change the taxed base). Without tax codes the previous behaviour stays: a manual header tax.
     */
    public function headerTax(TaxApplication $app, array $data, ?string $sourceType, ?string $docId, mixed $existingTax, BigDecimal $discount, int $scale): BigDecimal
    {
        $sent = array_key_exists('tax_amount', $data) && $data['tax_amount'] !== null && $data['tax_amount'] !== '';
        if ($app->active()) {
            if ($discount->isPositive()) {
                throw new DomainException('A document with tax codes cannot carry a header discount; reduce the line amounts instead.', 'TAX_HEADER_ADJUSTMENT_UNSUPPORTED', 422, ['field' => 'discount_amount']);
            }
            if ($sent && ! Money::parse($data['tax_amount'], $scale, 'tax_amount')->isEqualTo($app->taxTotal)) {
                throw new DomainException('The tax of a document with tax codes is calculated from its lines; do not send a different tax_amount.', 'TAX_AMOUNT_CONFLICT', 422, ['field' => 'tax_amount', 'calculated' => Money::str($app->taxTotal)]);
            }

            return $app->taxTotal;
        }
        if (! $sent && $sourceType !== null && $docId !== null
            && TaxTransaction::query()->where('source_type', $sourceType)->where('source_id', $docId)->where('status', TaxTransaction::DRAFT)->exists()) {
            return BigDecimal::zero()->toScale($scale); // the tax codes were taken off the lines and no manual tax replaces them
        }

        return Money::parse($sent ? $data['tax_amount'] : ($existingTax ?? 0), $scale, 'tax_amount');
    }

    /** A document may use an active input/output (or other) code that fits its side; a withholding tax is not supported on documents. */
    private function assertUsable(TaxCode $code, string $direction, int $line): void
    {
        $details = ['field' => 'tax_code_id', 'line' => $line, 'tax_code' => $code->code];
        if ($code->status !== TaxCode::ACTIVE) {
            throw new DomainException("Tax code {$code->code} is inactive.", 'TAX_CODE_INACTIVE', 422, $details);
        }
        if ($code->tax_type === TaxCode::WITHHOLDING) {
            throw new DomainException('A withholding tax code cannot be used on a document yet; it is configured for the tax report only.', 'TAX_WITHHOLDING_UNSUPPORTED', 422, $details);
        }
        $allowed = $direction === self::INPUT ? [TaxCode::INPUT_TAX, TaxCode::OTHER] : [TaxCode::OUTPUT_TAX, TaxCode::OTHER];
        if (! in_array($code->tax_type, $allowed, true)) {
            throw new DomainException("Tax code {$code->code} is a {$code->tax_type} code and does not fit this document.", 'TAX_TYPE_MISMATCH', 422, $details + ['tax_type' => $code->tax_type]);
        }
    }

    /** What a tax code would give for an amount on a date: the same calculator the documents use, without saving anything. */
    public function preview(TaxCode $code, mixed $amount, string $date): array
    {
        $scale = (int) DB::table('accounting_profiles')->where('tenant_id', $this->context->tenantId())->value('currency_scale');
        $rate = $this->rateOn($code, $date) ?? throw new DomainException("Tax code {$code->code} has no rate on {$date}.", 'TAX_RATE_NOT_FOUND', 422, ['tax_code' => $code->code, 'date' => $date]);
        $calc = TaxCalculator::calculate($code->calculation_method, $code->treatment, Money::parse($amount, $scale, 'amount'), BigDecimal::of($rate->rate), $scale);

        return [
            'tax_code' => $code->code, 'calculation_method' => $code->calculation_method, 'treatment' => $code->treatment, 'is_recoverable' => (bool) $code->is_recoverable, 'tax_type' => $code->tax_type,
            'date' => $date, 'rate' => $calc['rate']->__toString(), 'entered_amount' => Money::str($calc['entered']), 'base_amount' => Money::str($calc['base']), 'tax_amount' => Money::str($calc['tax']),
            'total_amount' => Money::str($calc['base']->plus($calc['tax'])),
        ];
    }

    public function rateOn(TaxCode $code, string $date): ?TaxRate
    {
        return TaxRate::query()->where('tax_code_id', $code->id)->where('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date))->first();
    }

    // ------------------------------------------------------------------------------------------------ save

    /**
     * Replace the DRAFT tax transactions of a document with those of its saved lines.
     *
     * @param  bool  $perLine  true when the lines are the document's own numbered lines; false for a document taxed as a whole (an expense, line 0)
     * @param  array{type:string,id:?string,name:?string,tax_id:?string}  $counterparty
     */
    public function sync(string $sourceType, string $direction, Model $doc, bool $perLine, TaxApplication $app, string $taxDate, array $counterparty, User $actor): void
    {
        TaxTransaction::query()->where('source_type', $sourceType)->where('source_id', $doc->id)->where('status', TaxTransaction::DRAFT)->delete();
        if (! $app->active()) {
            return;
        }
        $now = now();
        $rows = [];
        foreach ($app->facts as $i => ['code' => $code, 'rate' => $rate, 'calc' => $calc]) {
            $rows[] = [
                'id' => (string) Str::uuid7(), 'tenant_id' => $doc->tenant_id, 'tax_code_id' => $code->id, 'tax_rate_id' => $rate->id,
                'tax_code' => $code->code, 'tax_name' => $code->name, 'tax_type' => $code->tax_type, 'treatment' => $code->treatment, 'calculation_method' => $code->calculation_method,
                'is_recoverable' => $code->is_recoverable, 'rate' => $calc['rate']->__toString(), 'account_role' => $code->account_role, 'account_id' => $code->account_id,
                'direction' => $direction, 'source_type' => $sourceType, 'source_id' => $doc->id, 'line_number' => $perLine ? $i + 1 : 0,
                'entered_amount' => Money::str($calc['entered']), 'base_amount' => Money::str($calc['base']), 'tax_amount' => Money::str($calc['tax']),
                'tax_date' => $taxDate, 'counterparty_type' => $counterparty['type'] ?? null, 'counterparty_id' => $counterparty['id'] ?? null,
                'counterparty_name' => isset($counterparty['name']) ? mb_substr($counterparty['name'], 0, 255) : null, 'counterparty_tax_id' => $counterparty['tax_id'] ?? null,
                'branch_id' => $doc->branch_id, 'business_unit_id' => $doc->business_unit_id, 'status' => TaxTransaction::DRAFT, 'created_by' => $actor->id,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        DB::table('tax_transactions')->insert($rows);
    }

    /** A cancelled document takes its draft tax with it. */
    public function discard(string $sourceType, string $docId): void
    {
        TaxTransaction::query()->where('source_type', $sourceType)->where('source_id', $docId)->where('status', TaxTransaction::DRAFT)->delete();
    }

    // ------------------------------------------------------------------------------------------------ approve / post

    /**
     * The configuration still gives the amounts the document shows. With $lock the codes are held FOR SHARE until the posting commits, so a
     * rate change, a new mapping or a deactivation waits for the posting instead of slipping in between the check and the journal.
     */
    public function assertCurrent(string $sourceType, string $docId, User $actor, bool $lock): void
    {
        $rows = TaxTransaction::query()->where('source_type', $sourceType)->where('source_id', $docId)->where('status', TaxTransaction::DRAFT)->orderBy('line_number')->get();
        if ($rows->isEmpty()) {
            return;
        }
        $this->authority->assertModuleWritable($actor, 'ACCOUNTING_TAX', 'TAX_CONFIGURATION');
        $codes = TaxCode::query()->whereIn('id', $rows->pluck('tax_code_id')->unique()->all())->orderBy('id')->when($lock, fn ($q) => $q->sharedLock())->get()->keyBy('id');

        $scale = (int) DB::table('accounting_profiles')->where('tenant_id', $this->context->tenantId())->value('currency_scale');
        foreach ($rows as $row) {
            $code = $codes[$row->tax_code_id] ?? null;
            $details = ['tax_code' => $row->tax_code, 'line' => $row->line_number];
            if ($code === null || $code->status !== TaxCode::ACTIVE) {
                throw new DomainException("Tax code {$row->tax_code} is no longer active; save the document again.", 'TAX_CODE_INACTIVE', 409, $details);
            }
            $rate = $this->rateOn($code, $row->tax_date->toDateString());
            $calc = $rate === null ? null : TaxCalculator::calculate($code->calculation_method, $code->treatment, BigDecimal::of($row->entered_amount), BigDecimal::of($rate->rate), $scale);
            $same = $rate !== null && $rate->id === $row->tax_rate_id
                && $code->tax_type === $row->tax_type && $code->treatment === $row->treatment && $code->calculation_method === $row->calculation_method
                && (bool) $code->is_recoverable === (bool) $row->is_recoverable && $code->account_role === $row->account_role && $code->account_id === $row->account_id
                && $calc['tax']->isEqualTo($row->tax_amount) && $calc['base']->isEqualTo($row->base_amount);
            if (! $same) {
                throw new DomainException("The configuration of tax code {$row->tax_code} changed after this document was saved. Send it back to draft and save it again to recalculate its tax, or cancel it and enter it again.", 'TAX_CONFIGURATION_CHANGED', 409, $details);
            }
        }
    }

    /**
     * What the Posting Engine books for a document's tax.
     *
     * @return array{managed:bool,recoverable:BigDecimal,total:BigDecimal,parts:list<array<string,mixed>>,cost:array<int,BigDecimal>} `managed`: the document carries tax codes (otherwise its manual header tax stands); `parts`: the recoverable tax by
     *         account (explicit account or role); `cost`: the non-recoverable tax per line number, added to the cost of that line.
     *         `$perRow`: one part per tax transaction instead of one per account (a foreign document converts each tax on its own, so the ledger holds the
     *         exact sum of the functional tax amounts the tax report shows)
     */
    public function postingParts(string $sourceType, string $docId, bool $perRow = false): array
    {
        $rows = TaxTransaction::query()->where('source_type', $sourceType)->where('source_id', $docId)->where('status', TaxTransaction::DRAFT)->orderBy('line_number')->get();
        $recoverable = $total = BigDecimal::zero();
        $groups = [];
        $cost = [];
        foreach ($rows as $row) {
            $tax = BigDecimal::of($row->tax_amount);
            $total = $total->plus($tax);
            if ($tax->isZero()) {
                continue;
            }
            // An output tax is always the tax authority's money; only an input tax that cannot be recovered becomes a cost of its line.
            if ($row->direction === self::INPUT && ! $row->is_recoverable) {
                $cost[$row->line_number] = ($cost[$row->line_number] ?? BigDecimal::zero())->plus($tax);

                continue;
            }
            $key = ($row->account_id ? "A:{$row->account_id}" : "R:{$row->account_role}").($perRow ? ":{$row->line_number}" : '');
            $groups[$key] ??= ['amount' => BigDecimal::zero(), 'codes' => [], 'account_id' => $row->account_id, 'account_role' => $row->account_id ? null : $row->account_role];
            $groups[$key]['amount'] = $groups[$key]['amount']->plus($tax);
            $groups[$key]['codes'][$row->tax_code] = true;
            $recoverable = $recoverable->plus($tax);
        }
        ksort($groups);
        $parts = array_values(array_map(fn ($g) => array_filter([
            'amount' => Money::str($g['amount']), 'account_id' => $g['account_id'], 'account_role' => $g['account_role'],
            'description' => mb_substr('Pajak '.implode(', ', array_keys($g['codes'])), 0, 255),
        ], fn ($v) => $v !== null), $groups));

        return ['managed' => $rows->isNotEmpty(), 'recoverable' => $recoverable, 'total' => $total, 'parts' => $parts, 'cost' => $cost];
    }

    public function markPosted(string $sourceType, string $docId, JournalEntry $journal, string $documentNumber, ?ResolvedRate $rate = null): void
    {
        $count = TaxTransaction::query()->where('source_type', $sourceType)->where('source_id', $docId)->where('status', TaxTransaction::DRAFT)->count();
        if ($count === 0) {
            return;
        }
        if ($rate?->foreign()) { // a foreign document freezes the functional amounts the tax report adds up, each converted on its own like the ledger parts
            foreach (TaxTransaction::query()->where('source_type', $sourceType)->where('source_id', $docId)->where('status', TaxTransaction::DRAFT)->get() as $row) {
                DB::table('tax_transactions')->where('id', $row->id)->update([
                    'currency' => $rate->currency, 'exchange_rate' => $rate->rateString(),
                    'functional_base_amount' => Money::str($rate->convert(BigDecimal::of($row->base_amount))), 'functional_tax_amount' => Money::str($rate->convert(BigDecimal::of($row->tax_amount))),
                ]);
            }
        }
        $updated = DB::table('tax_transactions')->where('tenant_id', $journal->tenant_id)->where('source_type', $sourceType)->where('source_id', $docId)->where('status', TaxTransaction::DRAFT)
            ->update([
                'status' => TaxTransaction::POSTED, 'posting_date' => $journal->posting_date->toDateString(), 'document_number' => $documentNumber,
                'journal_entry_id' => $journal->id, 'posted_at' => now(), 'updated_at' => now(),
            ]);
        if ($updated !== $count) {
            throw new DomainException('The tax of this document changed while it was posted; nothing was posted.', 'TAX_CONFIGURATION_CHANGED', 409);
        }
    }

    public function markReversed(string $sourceType, string $docId, JournalEntry $reversal): void
    {
        DB::table('tax_transactions')->where('tenant_id', $reversal->tenant_id)->where('source_type', $sourceType)->where('source_id', $docId)->where('status', TaxTransaction::POSTED)
            ->update(['status' => TaxTransaction::REVERSED, 'reversal_journal_id' => $reversal->id, 'reversed_at' => now(), 'updated_at' => now()]);
    }
}
