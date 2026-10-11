<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Services\BalanceCalculator;
use App\Domain\Accounting\Support\Money;
use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Support\DefinitionGraph;
use Brick\Math\BigDecimal;

/**
 * The profit and loss statement for a period [from, to], computed live from POSTED lines (`ReportQuery::movement` -> `StatementEngine`). Period
 * semantics: only the activity dated inside the range counts, so earlier periods never leak in; the opening-balance journal and the year-end
 * closing journals (and their reversals) are not activity and are left out, which keeps a year's profit and loss readable after the year is closed.
 * The range is a period, a fiscal year (year to date when `to` is given), explicit dates, or the current fiscal year up to the business date, and
 * the response always says which.
 *
 * `integrity` compares the ledger's own result for the range (every revenue and expense account, mapped or not) with the result the statement
 * shows; accounts with activity but no line are listed in `unmapped`, and any difference makes the statement `reliable = false`. Nothing is
 * absorbed into a line.
 */
class ProfitLossService
{
    private const DIMENSIONS = ['branch_id', 'business_unit_id', 'cost_center_id'];

    public function __construct(private readonly StatementEngine $engine, private readonly ReportQuery $query, private readonly BalanceCalculator $balances) {}

    /**
     * @param  array<string,mixed>  $filters  period_id | fiscal_year_id | from / to, definition_id, branch_id, business_unit_id, cost_center_id, include_zero
     * @return array<string,mixed>
     */
    public function report(array $filters): array
    {
        return $this->evaluate($filters)['report'];
    }

    /**
     * The statement together with what the comparative and drill-down reports build on: every line with its visibility, the definition graph and
     * the per-account amounts as presented.
     *
     * @param  array<string,mixed>  $filters
     * @return array{report:array<string,mixed>,rows:list<array<string,mixed>>,graph:DefinitionGraph,definition:ReportDefinition,built:array<string,mixed>,nets:array<string,BigDecimal>} nets: account id => net (debit - credit) in the statement's semantics, cash accounts left out of a cash flow's
     */
    public function evaluate(array $filters): array
    {
        $range = $this->balances->range($filters);
        $dimensions = array_intersect_key($filters, array_flip(self::DIMENSIONS));
        $includeZero = (bool) ($filters['include_zero'] ?? false);

        ['definition' => $definition, 'graph' => $graph, 'warnings' => $warnings] = $this->engine->prepare(ReportDefinition::PROFIT_LOSS, $range['from'], $range['to'], $filters['definition_id'] ?? null);
        $nets = $this->query->movement($range['from'], $range['to'], $dimensions)->map(fn (array $figure) => $figure['net'])->all();

        $ledgerResult = $this->engine->netOfTypes($graph->accounts, ['REVENUE', 'EXPENSE'], $nets)->negated();
        $built = $this->engine->evaluate($graph, $nets, ['NET_RESULT' => $ledgerResult], $includeZero);

        $mappedResult = $this->engine->presentedOfTypes($graph->accounts, ['REVENUE'], $built['presented'])
            ->minus($this->engine->presentedOfTypes($graph->accounts, ['EXPENSE'], $built['presented']));
        $difference = $ledgerResult->minus($mappedResult);
        $unmapped = $this->engine->unmapped($graph, ['REVENUE', 'EXPENSE'], $nets);
        $complete = $this->query->complete($dimensions);

        $report = [
            'statement' => ReportDefinition::PROFIT_LOSS,
            'definition' => $this->engine->describe($definition),
            'currency' => $this->engine->currency(),
            'range' => $range,
            'generated_at' => now()->toIso8601String(),
            'complete' => $complete,
            'filters' => $dimensions + ['include_zero' => $includeZero],
            'lines' => $built['lines'],
            'integrity' => [
                'ledger_result' => Money::str($ledgerResult),
                'mapped_result' => Money::str($mappedResult),
                'difference' => Money::str($difference),
                'reliable' => $unmapped === [] && $difference->isZero(),
                'unmapped' => $unmapped,
            ],
            'warnings' => [...$warnings, ...$this->engine->integrityWarnings($complete, $unmapped, $difference, true)],
        ];

        return ['report' => $report, 'rows' => $built['rows'], 'graph' => $graph, 'definition' => $definition, 'built' => $built, 'nets' => $nets];
    }
}
