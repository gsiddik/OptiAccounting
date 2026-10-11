<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Support\Money;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Support\DefinitionGraph;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;

/**
 * The balance sheet (statement of financial position) as of a date, computed live from POSTED lines through the one calculation path
 * (`ReportQuery::asOf` -> `StatementEngine`). Cumulative semantics: every POSTED line up to the date counts, the opening-balance journal included,
 * and year-end closing journals included (they are what brings last year's revenue and expense accounts to zero). The result of the revenue and
 * expense accounts not yet closed is the synthetic equity line CURRENT_RESULT, derived from the account types, so it is zero once the year is closed.
 *
 * `integrity` adds up what the statement shows by account type: assets, and liabilities + equity + current result. The difference is reported as it
 * is and never absorbed into a line. `balanced` is true or false only when the figures cover the whole ledger (a data scope or filter that narrows
 * the ledger makes it null); accounts that carry a balance but sit on no line are listed in `unmapped` and make the statement `reliable = false`.
 */
class BalanceSheetService
{
    private const DIMENSIONS = ['branch_id', 'business_unit_id', 'cost_center_id'];

    public function __construct(private readonly StatementEngine $engine, private readonly ReportQuery $query, private readonly TenantContext $context) {}

    /**
     * @param  array<string,mixed>  $filters  as_of, definition_id, branch_id, business_unit_id, cost_center_id, include_zero
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
        $asOf = $filters['as_of'] ?? Tenant::query()->findOrFail($this->context->tenantId())->businessDate();
        $dimensions = array_intersect_key($filters, array_flip(self::DIMENSIONS));
        $includeZero = (bool) ($filters['include_zero'] ?? false);

        ['definition' => $definition, 'graph' => $graph, 'warnings' => $warnings] = $this->engine->prepare(ReportDefinition::BALANCE_SHEET, $asOf, $asOf, $filters['definition_id'] ?? null);
        $nets = $this->query->asOf($asOf, $dimensions)->map(fn (array $figure) => $figure['net'])->all();

        $currentResult = $this->engine->netOfTypes($graph->accounts, ['REVENUE', 'EXPENSE'], $nets)->negated();
        $built = $this->engine->evaluate($graph, $nets, ['CURRENT_RESULT' => $currentResult], $includeZero);

        $assets = $this->engine->presentedOfTypes($graph->accounts, ['ASSET'], $built['presented']);
        $funding = $this->engine->presentedOfTypes($graph->accounts, ['LIABILITY', 'EQUITY'], $built['presented'])->plus($currentResult);
        $difference = $assets->minus($funding);
        $unmapped = $this->engine->unmapped($graph, ['ASSET', 'LIABILITY', 'EQUITY'], $nets);
        $complete = $this->query->complete($dimensions);

        $report = [
            'statement' => ReportDefinition::BALANCE_SHEET,
            'definition' => $this->engine->describe($definition),
            'currency' => $this->engine->currency(),
            'as_of' => $asOf,
            'generated_at' => now()->toIso8601String(),
            'complete' => $complete,
            'filters' => $dimensions + ['include_zero' => $includeZero],
            'lines' => $built['lines'],
            'integrity' => [
                'assets' => Money::str($assets),
                'liabilities_and_equity' => Money::str($funding),
                'difference' => Money::str($difference),
                'balanced' => $complete ? $difference->isZero() : null,
                'reliable' => $unmapped === [] && ($difference->isZero() || ! $complete),
                'current_result' => Money::str($currentResult),
                'unmapped' => $unmapped,
            ],
            'warnings' => [...$warnings, ...$this->engine->integrityWarnings($complete, $unmapped, $difference, $complete)],
        ];

        return ['report' => $report, 'rows' => $built['rows'], 'graph' => $graph, 'definition' => $definition, 'built' => $built, 'nets' => $nets];
    }
}
