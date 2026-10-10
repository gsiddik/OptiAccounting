<?php

namespace App\Domain\Budget\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Services\BalanceCalculator;
use App\Domain\Accounting\Support\Money;
use App\Domain\Budget\Models\Budget;
use App\Domain\Budget\Models\BudgetVersion;
use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Budget versus actual (OA4 batch B). Nothing is stored: the budget side is the lines of one approved version, the actual side is
 * summed on demand from POSTED journal lines only (BalanceCalculator::base: drafts, submitted and approved journals never count;
 * opening-balance journals are not period activity and are left out), so there is no editable "actual".
 *
 * Matching: an actual (account, period, branch, business unit, cost center) belongs to the budget line of the same period whose account
 * is the actual's account or one of its ancestors and whose dimensions are empty or equal. Lines of one version never overlap, so there
 * is at most one such line. Actual P&L postings that no line covers appear as `unbudgeted` rows (budget 0).
 *
 * Amounts are in the budget account's normal-balance direction. Variance = actual - budget; variance % is null when the budget is zero.
 * `favorable` is decided by account type (revenue above plan, expense below plan) and is null for balance-sheet accounts.
 * Data scope narrows both sides, so a scoped user sees a scoped report and `complete` is false.
 */
class BudgetVsActualService
{
    public const GROUPS = ['account', 'period', 'branch', 'business_unit', 'cost_center', 'line'];

    public function __construct(
        private readonly BudgetService $budgets,
        private readonly BalanceCalculator $balances,
        private readonly DocumentScope $documents,
    ) {}

    /**
     * @param  array{budget_id:string,version_id?:?string,as_of?:?string,period_from?:?string,period_to?:?string,group_by?:?string,account_id?:?string,branch_id?:?string,business_unit_id?:?string,cost_center_id?:?string}  $filter
     * @return array<string,mixed>
     */
    public function report(array $filter): array
    {
        $budget = Budget::query()->with('fiscalYear')->findOrFail($filter['budget_id']);
        $group = $filter['group_by'] ?? 'account';
        if (! in_array($group, self::GROUPS, true)) {
            throw new DomainException('Unknown grouping.', 'BUDGET_GROUP_INVALID', 422, ['field' => 'group_by']);
        }

        $periods = AccountingPeriod::query()->where('fiscal_year_id', $budget->fiscal_year_id)->orderBy('number')->get()->keyBy('id');
        [$first, $last] = $this->range($periods, $filter['period_from'] ?? null, $filter['period_to'] ?? null);
        $version = $this->version($budget, $filter, $last->end_date->toDateString());
        $inRange = $periods->filter(fn ($p) => $p->number >= $first->number && $p->number <= $last->number)->keys()->all();

        $accounts = Account::query()->get(['id', 'code', 'name', 'account_type', 'normal_balance', 'parent_id'])->keyBy('id');
        $parents = $accounts->pluck('parent_id', 'id')->all();
        $related = isset($filter['account_id']) ? $this->related($filter['account_id'], $accounts, $parents) : null;
        $dims = array_filter(['branch_id' => $filter['branch_id'] ?? null, 'business_unit_id' => $filter['business_unit_id'] ?? null, 'cost_center_id' => $filter['cost_center_id'] ?? null]);

        // Budget side: the version's lines inside the period range, narrowed by data scope, account filter and strict dimension filters.
        $lines = $this->budgets->visibleLines($version->id)->whereIn('accounting_period_id', $inRange);
        foreach ($dims as $column => $value) {
            $lines->where($column, $value);
        }
        if ($related !== null) {
            $lines->whereIn('account_id', $related['lines']);
        }
        $lines = $lines->get();

        $slots = [];   // period|account => list of line slots
        $rows = [];    // line id => accumulator
        foreach ($lines as $line) {
            $account = $accounts[$line->account_id];
            $rows[$line->id] = [
                'line' => $line, 'account' => $account, 'budget' => BigDecimal::of((string) $line->amount), 'actual' => BigDecimal::zero(), 'unbudgeted' => false,
                'period_id' => $line->accounting_period_id, 'branch_id' => $line->branch_id, 'business_unit_id' => $line->business_unit_id, 'cost_center_id' => $line->cost_center_id,
            ];
            $slots[$line->accounting_period_id.'|'.$line->account_id][] = $line->id;
        }

        // Actual side: POSTED journal lines per account, period and dimensions.
        $actual = $this->balances->base($dims)
            ->join('accounting_periods as ap', fn ($join) => $join->on('ap.tenant_id', '=', 'jl.tenant_id')->on('je.posting_date', '>=', 'ap.start_date')->on('je.posting_date', '<=', 'ap.end_date'))
            ->where('ap.fiscal_year_id', $budget->fiscal_year_id)->whereIn('ap.id', $inRange)
            ->where('je.journal_type', '<>', 'OPENING')
            ->when($related !== null, fn ($q) => $q->whereIn('jl.account_id', $related['actual']))
            ->groupBy('jl.account_id', 'ap.id', 'jl.branch_id', 'jl.business_unit_id', 'jl.cost_center_id')
            ->select('jl.account_id', 'ap.id as period_id', 'jl.branch_id', 'jl.business_unit_id', 'jl.cost_center_id')
            ->selectRaw('coalesce(sum(jl.debit), 0) as debit, coalesce(sum(jl.credit), 0) as credit')
            ->get();

        foreach ($actual as $fact) {
            $net = BigDecimal::of((string) $fact->debit)->minus((string) $fact->credit);
            $match = null;
            foreach (BudgetService::chain($fact->account_id, $parents) as $candidate) {
                foreach ($slots[$fact->period_id.'|'.$candidate] ?? [] as $lineId) {
                    $line = $rows[$lineId]['line'];
                    if (($line->branch_id === null || $line->branch_id === $fact->branch_id) && ($line->business_unit_id === null || $line->business_unit_id === $fact->business_unit_id)
                        && ($line->cost_center_id === null || $line->cost_center_id === $fact->cost_center_id)) {
                        $match = $lineId;
                        break 2;
                    }
                }
            }

            if ($match !== null) {
                $rows[$match]['actual'] = $rows[$match]['actual']->plus(BalanceCalculator::signed($net, $rows[$match]['account']->normal_balance));
                continue;
            }
            $account = $accounts[$fact->account_id];
            if (! in_array($account->account_type, ['REVENUE', 'EXPENSE'], true)) {
                continue; // only P&L activity is reported as unbudgeted; balance-sheet movement is not a budget miss
            }
            $key = implode('|', ['u', $fact->account_id, $fact->period_id, $fact->branch_id, $fact->business_unit_id, $fact->cost_center_id]);
            $rows[$key] = [
                'line' => null, 'account' => $account, 'budget' => BigDecimal::zero(), 'actual' => BalanceCalculator::signed($net, $account->normal_balance), 'unbudgeted' => true,
                'period_id' => $fact->period_id, 'branch_id' => $fact->branch_id, 'business_unit_id' => $fact->business_unit_id, 'cost_center_id' => $fact->cost_center_id,
            ];
        }

        $names = $this->labels($rows);
        $grouped = $this->group($rows, $group, $periods, $names);
        $totals = ['budget' => BigDecimal::zero(), 'actual' => BigDecimal::zero(), 'unbudgeted_actual' => BigDecimal::zero()];
        foreach ($rows as $row) {
            $totals['budget'] = $totals['budget']->plus($row['budget']);
            $totals['actual'] = $totals['actual']->plus($row['actual']);
            if ($row['unbudgeted']) {
                $totals['unbudgeted_actual'] = $totals['unbudgeted_actual']->plus($row['actual']);
            }
        }

        return [
            'budget' => ['id' => $budget->id, 'code' => $budget->code, 'name' => $budget->name, 'currency' => $budget->currency, 'status' => $budget->status],
            'fiscal_year' => ['id' => $budget->fiscalYear->id, 'code' => $budget->fiscalYear->code],
            'version' => ['id' => $version->id, 'version_number' => $version->version_number, 'label' => $version->label, 'status' => $version->status,
                'effective_from' => $version->effective_from?->toDateString(), 'effective_until' => $version->effective_until?->toDateString()],
            'period_from' => ['id' => $first->id, 'code' => $first->code], 'period_to' => ['id' => $last->id, 'code' => $last->code],
            'from' => $first->start_date->toDateString(), 'to' => $last->end_date->toDateString(),
            'group_by' => $group, 'rows' => $grouped,
            'totals' => $this->figures($totals['budget'], $totals['actual'], null) + ['unbudgeted_actual' => Money::str($totals['unbudgeted_actual'])],
            'complete' => $this->documents->isTenantWide() && $dims === [] && $related === null,
            'basis' => 'POSTED journal lines only; opening-balance journals excluded; amounts in the functional currency and each account\'s normal-balance direction',
        ];
    }

    /** Figures of one row. @return array{budget:string,actual:string,variance:string,variance_pct:?string,favorable:?bool} */
    public function figures(BigDecimal $budget, BigDecimal $actual, ?string $accountType): array
    {
        $variance = $actual->minus($budget);

        return [
            'budget' => Money::str($budget), 'actual' => Money::str($actual), 'variance' => Money::str($variance),
            'variance_pct' => $budget->isZero() ? null : (string) $variance->multipliedBy(100)->dividedBy($budget, 2, RoundingMode::HalfUp),
            'favorable' => match ($accountType) {
                'REVENUE' => $variance->isGreaterThanOrEqualTo(0),
                'EXPENSE' => $variance->isLessThanOrEqualTo(0),
                default => null,
            },
        ];
    }

    // ------------------------------------------------------------------------------------------------ resolution

    /** @return array{0:AccountingPeriod,1:AccountingPeriod} */
    private function range($periods, ?string $from, ?string $to): array
    {
        if ($periods->isEmpty()) {
            throw new DomainException('The fiscal year of this budget has no periods.', 'BUDGET_PERIOD_INVALID', 422);
        }
        $first = $from ? ($periods[$from] ?? throw new DomainException('period_from does not belong to the fiscal year of this budget.', 'BUDGET_PERIOD_INVALID', 422, ['field' => 'period_from'])) : $periods->first();
        $last = $to ? ($periods[$to] ?? throw new DomainException('period_to does not belong to the fiscal year of this budget.', 'BUDGET_PERIOD_INVALID', 422, ['field' => 'period_to'])) : $periods->last();
        if ($first->number > $last->number) {
            throw new DomainException('period_from is after period_to.', 'RANGE_INVALID', 422);
        }

        return [$first, $last];
    }

    /** An explicit approved version, or the version in force on `as_of` (default: the last day of the reported range). */
    private function version(Budget $budget, array $filter, string $defaultAsOf): BudgetVersion
    {
        if (! empty($filter['version_id'])) {
            $version = BudgetVersion::query()->where('budget_id', $budget->id)->find($filter['version_id'])
                ?? throw new DomainException('The version does not exist in this budget.', 'BUDGET_VERSION_NOT_FOUND', 404);
            if (! in_array($version->status, ['APPROVED', 'ACTIVE', 'SUPERSEDED'], true)) {
                throw new DomainException('Only an approved version can be compared with actuals.', 'BUDGET_VERSION_NOT_APPROVED', 422, ['status' => $version->status]);
            }

            return $version;
        }

        $asOf = $filter['as_of'] ?? $defaultAsOf;

        return $this->budgets->effectiveVersion($budget, $asOf)
            ?? throw new DomainException('No budget version is in force on that date; activate one or choose a version.', 'BUDGET_NO_EFFECTIVE_VERSION', 422, ['as_of' => $asOf]);
    }

    /**
     * Accounts a filter on `account_id` touches: for budget lines the account, its ancestors (a group that covers it) and its subtree;
     * for actuals the account and its subtree.
     *
     * @return array{lines:list<string>,actual:list<string>}
     */
    private function related(string $accountId, $accounts, array $parents): array
    {
        if (! isset($accounts[$accountId])) {
            throw new DomainException('The account does not exist.', 'ACCOUNT_NOT_FOUND', 422, ['field' => 'account_id']);
        }
        $subtree = [];
        foreach ($accounts as $id => $_) {
            if (in_array($accountId, BudgetService::chain($id, $parents), true)) {
                $subtree[] = $id;
            }
        }

        return ['lines' => array_values(array_unique([...$subtree, ...BudgetService::chain($accountId, $parents)])), 'actual' => $subtree];
    }

    /** @param array<string,array<string,mixed>> $rows @return array<string,array<string,mixed>> */
    private function labels(array $rows): array
    {
        $ids = ['branches' => [], 'business_units' => [], 'cost_centers' => []];
        foreach ($rows as $row) {
            foreach (['branches' => 'branch_id', 'business_units' => 'business_unit_id', 'cost_centers' => 'cost_center_id'] as $table => $column) {
                if ($row[$column] !== null) {
                    $ids[$table][$row[$column]] = true;
                }
            }
        }
        $out = [];
        foreach ($ids as $table => $set) {
            $out[$table] = $set === [] ? collect() : DB::table($table)->whereIn('id', array_keys($set))->get(['id', 'code', 'name'])->keyBy('id');
        }

        return $out;
    }

    /**
     * @param  array<string,array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function group(array $rows, string $group, $periods, array $names): array
    {
        $buckets = [];
        foreach ($rows as $key => $row) {
            $id = match ($group) {
                'account' => $row['account']->id, 'period' => $row['period_id'], 'branch' => $row['branch_id'], 'business_unit' => $row['business_unit_id'],
                'cost_center' => $row['cost_center_id'], default => (string) $key,
            };
            $bucketKey = (string) ($id ?? '');
            $bucket = &$buckets[$bucketKey];
            $bucket ??= ['key' => $id, 'budget' => BigDecimal::zero(), 'actual' => BigDecimal::zero(), 'unbudgeted' => true, 'lines' => 0, 'sample' => $row];
            $bucket['budget'] = $bucket['budget']->plus($row['budget']);
            $bucket['actual'] = $bucket['actual']->plus($row['actual']);
            $bucket['unbudgeted'] = $bucket['unbudgeted'] && $row['unbudgeted'];
            $bucket['lines']++;
            unset($bucket);
        }

        $out = [];
        foreach ($buckets as $bucket) {
            $sample = $bucket['sample'];
            $label = match ($group) {
                'account' => ['code' => $sample['account']->code, 'name' => $sample['account']->name],
                'period' => ['code' => $periods[$sample['period_id']]->code, 'name' => $periods[$sample['period_id']]->name],
                'branch' => $this->label($names['branches'], $sample['branch_id']),
                'business_unit' => $this->label($names['business_units'], $sample['business_unit_id']),
                'cost_center' => $this->label($names['cost_centers'], $sample['cost_center_id']),
                default => ['code' => $sample['account']->code, 'name' => $sample['account']->name],
            };
            $accountType = in_array($group, ['account', 'line'], true) ? $sample['account']->account_type : null;
            $row = ['key' => $bucket['key'], 'code' => $label['code'], 'name' => $label['name'], 'account_type' => $accountType, 'unbudgeted' => $bucket['unbudgeted'], 'lines' => $bucket['lines']]
                + $this->figures($bucket['budget'], $bucket['actual'], $accountType);
            if ($group === 'line') {
                $row += [
                    'period' => ['id' => $sample['period_id'], 'code' => $periods[$sample['period_id']]->code],
                    'branch' => $this->label($names['branches'], $sample['branch_id']) + ['id' => $sample['branch_id']],
                    'business_unit' => $this->label($names['business_units'], $sample['business_unit_id']) + ['id' => $sample['business_unit_id']],
                    'cost_center' => $this->label($names['cost_centers'], $sample['cost_center_id']) + ['id' => $sample['cost_center_id']],
                    'description' => $sample['line']?->description,
                ];
            }
            $out[] = $row;
        }

        usort($out, fn ($a, $b) => [$group === 'period' ? ($periods[$a['key']]->number ?? 0) : 0, (string) $a['code'], (string) $a['name']] <=> [$group === 'period' ? ($periods[$b['key']]->number ?? 0) : 0, (string) $b['code'], (string) $b['name']]);

        return $out;
    }

    /** @return array{code:?string,name:?string} */
    private function label($names, ?string $id): array
    {
        if ($id === null) {
            return ['code' => null, 'name' => null];
        }

        return ['code' => $names[$id]->code ?? null, 'name' => $names[$id]->name ?? null];
    }
}
