<?php

namespace App\Domain\Accounting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\FiscalYear;
use App\Domain\Accounting\Support\SourceTypes;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The only place that turns journal lines into balances. Everything comes from POSTED journal lines (drafts, submitted
 * and approved journals never count; there is no stored balance). Semantics shared by the general ledger and the trial
 * balance, for a range [from, to]:
 *
 *   opening  = lines posted before `from`, plus every OPENING journal dated up to `to`
 *   movement = lines posted in [from, to], except OPENING journals (debit and credit kept apart)
 *   ending   = opening + movement
 *
 * An account's balance is expressed in its normal-balance direction (a debit-normal account is debit minus credit).
 * Data scope narrows the lines, so a scoped user sees a scoped ledger; `complete()` says whether the figures cover the
 * whole ledger (then total debits equal total credits by construction).
 *
 * Filters understood by `base()` (all optional, all applied in SQL): `account_id`, the dimensions `branch_id` / `business_unit_id` /
 * `cost_center_id`, and (OA5) the account selection `account_ids` / `account_type` / `account_code_from` / `account_code_to`, the line's
 * `currency` (transaction currency), the journal's `source_type` and `journal_type`. The OA5 filters narrow the ledger on purpose, so
 * `complete()` is then false and no report may claim the figures balance.
 */
class BalanceCalculator
{
    public function __construct(private readonly TenantContext $context, private readonly AccountingScope $scope) {}

    /**
     * The reported range: a period, a fiscal year (whole, or year to date when `to` is given) or explicit dates; with nothing given, the fiscal
     * year of `to` (default: the tenant's business date) up to `to`.
     *
     * @return array{from:string,to:string}
     */
    public function range(array $filters): array
    {
        if (! empty($filters['period_id'])) {
            $period = AccountingPeriod::query()->find($filters['period_id'])
                ?? throw new DomainException('The period does not exist.', 'PERIOD_NOT_FOUND', 404);

            return ['from' => $period->start_date->toDateString(), 'to' => $period->end_date->toDateString()];
        }

        if (! empty($filters['fiscal_year_id'])) {
            $year = FiscalYear::query()->find($filters['fiscal_year_id'])
                ?? throw new DomainException('The fiscal year does not exist.', 'FISCAL_YEAR_NOT_FOUND', 404);
            $start = $year->start_date->toDateString();
            $end = $year->end_date->toDateString();
            $from = $filters['from'] ?? $start;
            $to = $filters['to'] ?? $end;
            if ($from < $start || $to > $end) {
                throw new DomainException('The dates must lie inside the fiscal year.', 'RANGE_OUTSIDE_FISCAL_YEAR', 422, ['fiscal_year_start' => $start, 'fiscal_year_end' => $end]);
            }
            if ($from > $to) {
                throw new DomainException('The start date is after the end date.', 'RANGE_INVALID', 422);
            }

            return ['from' => $from, 'to' => $to];
        }

        $to = $filters['to'] ?? Tenant::query()->findOrFail($this->context->tenantId())->businessDate();
        $from = $filters['from']
            ?? FiscalYear::query()->whereDate('start_date', '<=', $to)->whereDate('end_date', '>=', $to)->value('start_date')?->toDateString()
            ?? substr($to, 0, 8).'01';
        if ($from > $to) {
            throw new DomainException('The start date is after the end date.', 'RANGE_INVALID', 422);
        }

        return ['from' => $from, 'to' => $to];
    }

    /** POSTED lines of this tenant with their journal and account, narrowed by data scope and dimension filters (no date filter). */
    public function base(array $filters): Builder
    {
        $query = DB::table('journal_lines as jl')
            ->join('journal_entries as je', fn ($join) => $join->on('je.id', '=', 'jl.journal_entry_id')->on('je.tenant_id', '=', 'jl.tenant_id'))
            ->where('jl.tenant_id', $this->context->tenantId())
            ->where('je.status', 'POSTED');

        foreach (['account_id', 'branch_id', 'business_unit_id', 'cost_center_id'] as $column) {
            if (! empty($filters[$column])) {
                $query->where("jl.{$column}", $filters[$column]);
            }
        }
        if (($accounts = $this->accountSelection($filters)) !== null) {
            $query->whereIn('jl.account_id', $accounts);
        }
        if (! empty($filters['currency'])) {
            $query->where('jl.transaction_currency', $filters['currency']);
        }
        foreach (['source_type', 'journal_type'] as $column) {
            if (! empty($filters[$column])) {
                $query->where("je.{$column}", $filters[$column]);
            }
        }
        $this->scope->restrictLines($query);

        return $query;
    }

    /** The accounts named by `account_ids` / `account_type` / `account_code_from` / `account_code_to` as a tenant-scoped subquery, or null when none is given. */
    private function accountSelection(array $filters): ?EloquentBuilder
    {
        if (! self::narrowsAccounts($filters)) {
            return null;
        }

        return Account::query()->select('id')
            ->when(! empty($filters['account_ids']), fn ($q) => $q->whereIn('id', (array) $filters['account_ids']))
            ->when(! empty($filters['account_type']), fn ($q) => $q->where('account_type', $filters['account_type']))
            ->when(! empty($filters['account_code_from']), fn ($q) => $q->where('code', '>=', $filters['account_code_from']))
            ->when(! empty($filters['account_code_to']), fn ($q) => $q->where('code', '<=', $filters['account_code_to']));
    }

    /** Does any account-selection filter apply? */
    public static function narrowsAccounts(array $filters): bool
    {
        return ! empty($filters['account_ids']) || ! empty($filters['account_type']) || ! empty($filters['account_code_from']) || ! empty($filters['account_code_to']);
    }

    /** The in-memory twin of `accountSelection()` for callers that list accounts themselves (zero rows, hierarchy): does this posting account pass the filters? */
    public static function selectsAccount(Account $account, array $filters): bool
    {
        return $account->is_postable
            && (empty($filters['account_ids']) || in_array($account->id, (array) $filters['account_ids'], true))
            && (empty($filters['account_type']) || $account->account_type === $filters['account_type'])
            && (empty($filters['account_code_from']) || $account->code >= $filters['account_code_from'])
            && (empty($filters['account_code_to']) || $account->code <= $filters['account_code_to']);
    }

    /**
     * Leave out the year-end closing journals (they move temporary account balances to retained earnings, which is not period activity) and
     * the reversal of one. A reversal carries no source marker of its own, so it is recognised through the journal it reverses.
     */
    public function withoutClosing(Builder $query): Builder
    {
        return $query->whereRaw('je.source_type is distinct from ?', [SourceTypes::YEAR_END_CLOSING])
            ->whereRaw("not (je.journal_type = 'REVERSAL' and exists (select 1 from journal_entries cj where cj.tenant_id = je.tenant_id and cj.id = je.reverses_journal_id and cj.source_type = ?))", [SourceTypes::YEAR_END_CLOSING]);
    }

    /**
     * Opening net (debit - credit), period debit and period credit per account.
     *
     * @return Collection<string,object{account_id:string,opening_net:string,debit:string,credit:string}>
     */
    public function totals(string $from, string $to, array $filters, bool $includeClosing = true): Collection
    {
        $query = $this->base($filters);
        if (! $includeClosing) {
            $this->withoutClosing($query);
        }

        return $query->where('je.posting_date', '<=', $to)
            ->select('jl.account_id')
            ->selectRaw("coalesce(sum(case when je.posting_date < ? or je.journal_type = 'OPENING' then jl.debit - jl.credit else 0 end), 0) as opening_net", [$from])
            ->selectRaw("coalesce(sum(case when je.posting_date >= ? and je.journal_type <> 'OPENING' then jl.debit else 0 end), 0) as debit", [$from])
            ->selectRaw("coalesce(sum(case when je.posting_date >= ? and je.journal_type <> 'OPENING' then jl.credit else 0 end), 0) as credit", [$from])
            ->groupBy('jl.account_id')->get()->keyBy('account_id');
    }

    /**
     * Do the figures cover the whole ledger (tenant-wide data scope, no dimension filter, none of the OA5 narrowing filters)? A single
     * `account_id` does not count as narrowing: the ledger of one account is complete for that account.
     */
    public function complete(array $filters): bool
    {
        return $this->scope->isTenantWide() && empty($filters['branch_id']) && empty($filters['business_unit_id']) && empty($filters['cost_center_id'])
            && ! self::narrowsAccounts($filters) && empty($filters['currency']) && empty($filters['source_type']) && empty($filters['journal_type']);
    }

    /** Net (debit - credit) in the account's normal-balance direction. */
    public static function signed(BigDecimal $net, string $normalBalance): BigDecimal
    {
        return $normalBalance === 'CREDIT' ? $net->negated() : $net;
    }

    /** @return array{0:BigDecimal,1:BigDecimal} net debit-minus-credit as (debit side, credit side) */
    public static function split(BigDecimal $net): array
    {
        return $net->isNegative() ? [BigDecimal::zero(), $net->negated()] : [$net, BigDecimal::zero()];
    }
}
