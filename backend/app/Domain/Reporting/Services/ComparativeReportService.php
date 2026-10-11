<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Support\Money;
use App\Domain\Budget\Models\Budget;
use App\Domain\Budget\Services\BudgetVsActualService;
use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Support\ComparisonPeriod;
use App\Domain\Reporting\Support\DefinitionGraph;
use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * A statement beside a comparison: the same lines of the same report definition for two dates, or for the actuals and the budget.
 *
 *   PRIOR_PERIOD  the period before (see ComparisonPeriod), produced by the statement's own service with the same definition
 *   PRIOR_YEAR    the same dates a year earlier, same way
 *   BUDGET        profit and loss only: the budget amounts of the approved version in force, taken from `BudgetVsActualService` (its `budget` column; the
 *                 actual side is this report's own statement, there is no second actual engine) and placed on the statement lines through the same
 *                 account mapping, so subtotals and results are evaluated by the one statement engine
 *
 * Each line has `current`, `comparison`, `variance` = current - comparison and `variance_pct` = variance / |comparison| in percent, null when the
 * comparison is zero. A line shows when either side shows it. Data scope and filters narrow both sides, so `complete` is false for a scoped user and
 * nothing is compared against figures the user cannot see. A budget row that cannot be placed on exactly one line is reported in
 * `integrity.budget.unplaced`, never spread or dropped silently.
 */
class ComparativeReportService
{
    public const MODES = ['PRIOR_PERIOD', 'PRIOR_YEAR', 'BUDGET'];

    private const NOT_FOR_THE_STATEMENT = ['statement', 'mode', 'budget_id', 'version_id'];

    public function __construct(
        private readonly BalanceSheetService $balanceSheet,
        private readonly ProfitLossService $profitLoss,
        private readonly CashFlowService $cashFlow,
        private readonly StatementEngine $engine,
        private readonly BudgetVsActualService $budgets,
        private readonly ReportAccess $access,
    ) {}

    /**
     * @param  array<string,mixed>  $filters  statement, mode, the statement's own date filters (as_of, or period_id | fiscal_year_id | from / to), definition_id, dimensions, include_zero, and for BUDGET budget_id, version_id
     * @return array<string,mixed>
     */
    public function report(array $filters): array
    {
        $statement = $filters['statement'];
        $mode = $filters['mode'];
        $this->access->assertStatement($statement);
        if ($mode === 'BUDGET') {
            if ($statement !== ReportDefinition::PROFIT_LOSS) {
                throw new DomainException('Only the profit and loss statement can be compared with a budget.', 'COMPARISON_MODE_UNSUPPORTED', 422, ['statement' => $statement, 'mode' => $mode]);
            }
            $this->access->assertBudget();
        }

        $own = array_diff_key($filters, array_flip(self::NOT_FOR_THE_STATEMENT));
        $current = $this->service($statement)->evaluate($own);
        $report = $current['report'];
        $dated = $statement === ReportDefinition::BALANCE_SHEET ? ['as_of' => $report['as_of']] : ['range' => $report['range']];
        $sameLayout = array_intersect_key($own, array_flip(['branch_id', 'business_unit_id', 'cost_center_id', 'include_zero'])) + ['definition_id' => $current['definition']->id];

        if ($mode === 'BUDGET') {
            [$comparison, $comparisonWarnings, $detail, $complete] = $this->budgetSide($filters, $current, $report['range']);
            $comparisonBlock = ['mode' => $mode, 'range' => $report['range'], 'budget' => $detail['budget'], 'version' => $detail['version']];
            $integrity = ['current' => $report['integrity'], 'budget' => $detail['integrity']];
        } else {
            $shifted = $this->shift($statement, $mode, $dated);
            $other = $this->service($statement)->evaluate($sameLayout + $shifted);
            $comparison = $other['rows'];
            $comparisonWarnings = $other['report']['warnings'];
            $complete = $other['report']['complete'];
            $comparisonBlock = ['mode' => $mode] + (isset($shifted['as_of']) ? ['as_of' => $shifted['as_of']] : ['range' => $shifted]);
            $integrity = ['current' => $report['integrity'], 'comparison' => $other['report']['integrity']];
        }

        return [
            'statement' => $statement,
            'mode' => $mode,
            'definition' => $report['definition'],
            'currency' => $report['currency'],
        ] + $dated + [
            'comparison' => $comparisonBlock,
            'generated_at' => now()->toIso8601String(),
            'complete' => $report['complete'] && $complete,
            'filters' => $report['filters'],
            'lines' => $this->merge($current['rows'], $comparison),
            'integrity' => $integrity,
            'warnings' => $this->warnings($report['warnings'], $comparisonWarnings),
        ];
    }

    /** current - comparison, and that as a percentage of the absolute comparison (two decimals); null when either amount is missing or the comparison is zero. @return array{variance:?string,variance_pct:?string} */
    public static function variance(?string $current, ?string $comparison): array
    {
        if ($current === null || $comparison === null) {
            return ['variance' => null, 'variance_pct' => null];
        }
        $other = BigDecimal::of($comparison);
        $variance = BigDecimal::of($current)->minus($other);

        return [
            'variance' => Money::str($variance),
            'variance_pct' => $other->isZero() ? null : (string) $variance->multipliedBy(100)->dividedBy($other->abs(), 2, RoundingMode::HalfUp),
        ];
    }

    // ------------------------------------------------------------------------------------------------ the two kinds of comparison

    /**
     * @param  array{as_of?:string,range?:array{from:string,to:string}}  $dated
     * @return array<string,string>
     */
    private function shift(string $statement, string $mode, array $dated): array
    {
        if ($statement === ReportDefinition::BALANCE_SHEET) {
            return ['as_of' => $mode === 'PRIOR_YEAR' ? ComparisonPeriod::priorYearDate($dated['as_of']) : ComparisonPeriod::priorPeriodDate($dated['as_of'])];
        }
        $range = $mode === 'PRIOR_YEAR' ? ComparisonPeriod::priorYearRange($dated['range']['from'], $dated['range']['to']) : ComparisonPeriod::priorPeriodRange($dated['range']['from'], $dated['range']['to']);

        return ['from' => $range['from'], 'to' => $range['to']];
    }

    /**
     * The budget of the range placed on the lines of the current statement.
     *
     * @param  array<string,mixed>  $filters
     * @param  array{rows:list<array<string,mixed>>,graph:DefinitionGraph,built:array<string,mixed>}  $current
     * @param  array{from:string,to:string}  $range
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>,2:array<string,mixed>,3:bool}
     */
    private function budgetSide(array $filters, array $current, array $range): array
    {
        $budget = Budget::query()->findOrFail($filters['budget_id']);
        $periods = AccountingPeriod::query()->where('fiscal_year_id', $budget->fiscal_year_id)->orderBy('number')->get();
        $first = $periods->first(fn (AccountingPeriod $p) => $p->start_date->toDateString() === $range['from']);
        $last = $periods->first(fn (AccountingPeriod $p) => $p->end_date->toDateString() === $range['to']);
        if ($first === null || $last === null || $first->number > $last->number) {
            throw new DomainException('A budget is kept per accounting period: the range must start on the first day and end on the last day of periods of the budget\'s fiscal year.', 'BUDGET_RANGE_NOT_PERIOD_ALIGNED', 422, [
                'range' => $range, 'budget_fiscal_year_id' => $budget->fiscal_year_id,
            ]);
        }

        $vsActual = $this->budgets->report(array_filter([
            'budget_id' => $budget->id, 'version_id' => $filters['version_id'] ?? null, 'period_from' => $first->id, 'period_to' => $last->id, 'group_by' => 'account',
            'as_of' => $range['to'], 'branch_id' => $filters['branch_id'] ?? null, 'business_unit_id' => $filters['business_unit_id'] ?? null, 'cost_center_id' => $filters['cost_center_id'] ?? null,
        ]));

        $graph = $current['graph'];
        $nets = [];
        $unplaced = [];
        foreach ($vsActual['rows'] as $row) {
            $amount = BigDecimal::of($row['budget']);
            $account = $graph->accounts->accounts[$row['key']] ?? null;
            if ($amount->isZero() || $account === null || ! in_array($account->account_type, ['REVENUE', 'EXPENSE'], true)) {
                continue; // a budget on a balance-sheet account has no line of a profit and loss statement
            }
            $holder = $this->holder($graph, $account->id);
            if ($holder === null) {
                $unplaced[] = ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'budget' => Money::str($amount)];

                continue;
            }
            $nets[$holder] = ($nets[$holder] ?? BigDecimal::zero())->plus($account->normal_balance === 'CREDIT' ? $amount->negated() : $amount);
        }

        $result = $this->engine->netOfTypes($graph->accounts, ['REVENUE', 'EXPENSE'], $nets)->negated();
        $built = $this->engine->evaluate($graph, $nets, ['NET_RESULT' => $result], (bool) ($filters['include_zero'] ?? false));
        $warnings = $unplaced === [] ? [] : [[
            'code' => 'BUDGET_NOT_ON_STATEMENT', 'message' => count($unplaced).' budgeted account(s) are not on exactly one line of the report definition, so their budget is not in the comparison.',
            'details' => ['count' => count($unplaced), 'accounts' => array_slice($unplaced, 0, 50)],
        ]];

        return [$built['rows'], $warnings, [
            'budget' => ['id' => $vsActual['budget']['id'], 'code' => $vsActual['budget']['code'], 'name' => $vsActual['budget']['name']],
            'version' => $vsActual['version'],
            'integrity' => ['budget_result' => Money::str($result), 'unplaced' => $unplaced],
        ], (bool) $vsActual['complete']];
    }

    /**
     * The posting account that carries a budget row on the statement: the account itself, or for a budget set on a header the first posting account
     * below it - provided every posting account below the header sits on one and the same line. Null when the budget has no single place.
     */
    private function holder(DefinitionGraph $graph, string $accountId): ?string
    {
        $posting = $graph->accounts->postingUnder($accountId);
        $lines = [];
        foreach ($posting as $id) {
            $lines[$graph->lineOf($id) ?? ''] = $id;
        }
        if (count($lines) !== 1 || isset($lines[''])) {
            return null;
        }

        return $posting[0];
    }

    // ------------------------------------------------------------------------------------------------ merging

    /**
     * @param  list<array<string,mixed>>  $current
     * @param  list<array<string,mixed>>  $comparison
     * @return list<array<string,mixed>>
     */
    private function merge(array $current, array $comparison): array
    {
        $out = [];
        foreach ($current as $i => $row) {
            $other = $comparison[$i];
            if (! $row['visible'] && ! $other['visible']) {
                continue;
            }
            $out[] = [
                'code' => $row['code'], 'label' => $row['label'], 'line_type' => $row['line_type'], 'depth' => $row['depth'], 'parent_code' => $row['parent_code'], 'emphasis' => $row['emphasis'],
                'current' => $row['amount'], 'comparison' => $other['amount'],
            ] + self::variance($row['amount'], $other['amount']) + ['accounts' => $this->accounts($row['accounts'], $other['accounts'])];
        }

        return $out;
    }

    /**
     * @param  list<array<string,string>>  $current
     * @param  list<array<string,string>>  $comparison
     * @return list<array<string,string>>
     */
    private function accounts(array $current, array $comparison): array
    {
        $byId = [];
        foreach ($current as $a) {
            $byId[$a['id']] = ['id' => $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'current' => $a['amount'], 'comparison' => Money::str(0)];
        }
        foreach ($comparison as $a) {
            $byId[$a['id']] ??= ['id' => $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'current' => Money::str(0), 'comparison' => Money::str(0)];
            $byId[$a['id']]['comparison'] = $a['amount'];
        }
        $out = array_map(fn (array $a) => $a + self::variance($a['current'], $a['comparison']), array_values($byId));
        usort($out, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return $out;
    }

    /**
     * @param  list<array<string,mixed>>  $current
     * @param  list<array<string,mixed>>  $comparison
     * @return list<array<string,mixed>> the warnings of both sides, once per code (the comparison's carry `side`)
     */
    private function warnings(array $current, array $comparison): array
    {
        $seen = array_flip(array_column($current, 'code'));
        $out = $current;
        foreach ($comparison as $warning) {
            if (! isset($seen[$warning['code']])) {
                $out[] = $warning + ['side' => 'comparison'];
                $seen[$warning['code']] = true;
            }
        }

        return $out;
    }

    private function service(string $statement): BalanceSheetService|ProfitLossService|CashFlowService
    {
        return match ($statement) {
            ReportDefinition::BALANCE_SHEET => $this->balanceSheet,
            ReportDefinition::PROFIT_LOSS => $this->profitLoss,
            ReportDefinition::CASH_FLOW => $this->cashFlow,
        };
    }
}
