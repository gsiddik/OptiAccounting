<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\BalanceCalculator;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place that turns POSTED journal lines into per-account figures for statements and balance reports. Everything is built on
 * `BalanceCalculator::base()` (tenant + data scope + POSTED + dimension filters), so a draft, submitted or approved journal never counts and
 * a user with a narrower scope gets narrower figures (`complete()` says so). Each method is a single `GROUP BY account_id` query: callers
 * (statement engine, cash flow, account balance, drill-down) combine the result with the chart of accounts in memory and never query per account.
 *
 *   asOf     cumulative balance up to a date (OPENING journals are ordinary lines here, so the opening balances are carried)
 *   movement activity inside [from, to] (OPENING journals are not activity)
 *   trial    opening + movement of the trial balance (BalanceCalculator semantics: opening = lines before `from` plus every OPENING journal up to `to`)
 *
 * Year-end closing journals (and their reversals) can be left out. A balance sheet keeps them (it is cumulative, so they are what makes the
 * temporary accounts read zero); a period report leaves them out so closing a year does not erase that year's profit and loss.
 * Amounts are functional-currency BigDecimals; `net` is debit minus credit.
 */
class ReportQuery
{
    public function __construct(private readonly BalanceCalculator $balances, private readonly TenantContext $context) {}

    /** @return Collection<string,array{debit:BigDecimal,credit:BigDecimal,net:BigDecimal}> keyed by account id */
    public function asOf(string $asOf, array $filters = [], bool $includeClosing = true): Collection
    {
        $query = $this->balances->base($filters)->where('je.posting_date', '<=', $asOf);

        return $this->figures($query, $includeClosing);
    }

    /** @return Collection<string,array{debit:BigDecimal,credit:BigDecimal,net:BigDecimal}> keyed by account id */
    public function movement(string $from, string $to, array $filters = [], bool $includeClosing = false): Collection
    {
        $query = $this->balances->base($filters)->whereBetween('je.posting_date', [$from, $to])->where('je.journal_type', '<>', 'OPENING');

        return $this->figures($query, $includeClosing);
    }

    /**
     * Opening net, movement debit and movement credit of every account that has lines up to `$to` (the trial balance semantics).
     *
     * @return Collection<string,array{opening:BigDecimal,debit:BigDecimal,credit:BigDecimal}> keyed by account id
     */
    public function trial(string $from, string $to, array $filters = [], bool $includeClosing = true): Collection
    {
        return $this->balances->totals($from, $to, $filters, $includeClosing)->map(fn ($row) => [
            'opening' => BigDecimal::of((string) $row->opening_net), 'debit' => BigDecimal::of((string) $row->debit), 'credit' => BigDecimal::of((string) $row->credit),
        ]);
    }

    /** Do the figures cover the whole ledger? False for a narrower data scope or a dimension / account / currency filter. */
    public function complete(array $filters = []): bool
    {
        return $this->balances->complete($filters);
    }

    /** @return Collection<string,Account> the tenant's chart of accounts keyed by id */
    public function accounts(): Collection
    {
        return Account::query()->orderBy('code')->get(['id', 'code', 'name', 'account_type', 'normal_balance', 'is_postable', 'parent_id', 'status'])->keyBy('id');
    }

    /**
     * The GL accounts that hold cash: the accounts mapped to the roles CASH and BANK plus the GL account of every cash/bank account. This is
     * configuration, never a code prefix; a cash flow statement leaves these accounts out of the working-capital lines and reports them as cash.
     *
     * @return list<string> account ids
     */
    public function cashAccountIds(): array
    {
        $tenant = $this->context->tenantId();
        $mapped = DB::table('account_mappings')->where('tenant_id', $tenant)->whereIn('account_role', ['CASH', 'BANK'])->where('status', 'ACTIVE')->pluck('account_id');
        $registered = DB::table('cash_bank_accounts')->where('tenant_id', $tenant)->pluck('account_id');

        return $mapped->merge($registered)->unique()->values()->all();
    }

    /** @return Collection<string,array{debit:BigDecimal,credit:BigDecimal,net:BigDecimal}> */
    private function figures($query, bool $includeClosing): Collection
    {
        if (! $includeClosing) {
            $this->balances->withoutClosing($query);
        }

        return $query->select('jl.account_id')
            ->selectRaw('coalesce(sum(jl.debit), 0) as debit, coalesce(sum(jl.credit), 0) as credit')
            ->groupBy('jl.account_id')->get()
            ->mapWithKeys(function ($row) {
                $debit = BigDecimal::of((string) $row->debit);
                $credit = BigDecimal::of((string) $row->credit);

                return [$row->account_id => ['debit' => $debit, 'credit' => $credit, 'net' => $debit->minus($credit)]];
            });
    }
}
