<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Services\BalanceCalculator;
use App\Domain\Accounting\Support\Money;
use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Models\ReportLine;
use App\Domain\Reporting\Support\DefinitionGraph;
use Brick\Math\BigDecimal;

/**
 * The cash flow statement, indirect method, for a period [from, to], computed live from POSTED lines in one pass over the trial-balance figures of the
 * range (`ReportQuery::trial`, year-end closing journals left out on both sides, the opening-balance journal counted as opening, not as activity).
 *
 *   cash accounts  the accounts mapped to the roles CASH and BANK and the GL account of every cash/bank account; they are what the statement explains
 *                  and can never be a line of it
 *   net result     the revenue and expense accounts' result for the range (the ledger's own figure, mapped or not)
 *   cash effect    credit minus debit of the movement of every other balance-sheet account (an asset that grew used cash, a liability that grew provided
 *                  it); a statement line of the account shows this figure, classified by the line's cash flow category
 *
 * Because every journal balances, opening cash + net result + the cash effect of ALL other balance-sheet accounts equals closing cash. `integrity` proves it
 * against the general ledger: opening cash plus the statement's net change versus the ledger's closing cash, the difference visible and never absorbed.
 * Accounts with movement that no line classifies (not on any line, or on a line categorised UNCLASSIFIED) are listed; they are added to the statement
 * only through the definition's UNCLASSIFIED_CASH_FLOW line, so a definition without it shows the gap as a difference.
 */
class CashFlowService
{
    private const DIMENSIONS = ['branch_id', 'business_unit_id', 'cost_center_id'];

    private const BALANCE_SHEET_TYPES = ['ASSET', 'LIABILITY', 'EQUITY'];

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
     * The statement together with what the comparative and drill-down reports build on.
     *
     * @param  array<string,mixed>  $filters
     * @return array{report:array<string,mixed>,rows:list<array<string,mixed>>,graph:DefinitionGraph,definition:ReportDefinition,built:array<string,mixed>,nets:array<string,BigDecimal>} nets: account id => net (debit - credit) in the statement's semantics, cash accounts left out of a cash flow's
     */
    public function evaluate(array $filters): array
    {
        $range = $this->balances->range($filters);
        $dimensions = array_intersect_key($filters, array_flip(self::DIMENSIONS));
        $includeZero = (bool) ($filters['include_zero'] ?? false);

        ['definition' => $definition, 'graph' => $graph, 'warnings' => $warnings] = $this->engine->prepare(ReportDefinition::CASH_FLOW, $range['from'], $range['to'], $filters['definition_id'] ?? null);
        $tree = $graph->accounts;
        $cashIds = array_flip($this->query->cashAccountIds());

        $movement = [];   // non-cash account id => net (debit - credit) of the range
        $cash = [];       // cash account id => [opening, movement]
        foreach ($this->query->trial($range['from'], $range['to'], $dimensions, false) as $accountId => $row) {
            $net = $row['debit']->minus($row['credit']);
            if (isset($cashIds[$accountId])) {
                $cash[$accountId] = ['opening' => $row['opening'], 'movement' => $net];
            } else {
                $movement[$accountId] = $net;
            }
        }
        $cashOpening = BigDecimal::zero();
        $cashMovement = BigDecimal::zero();
        foreach ($cash as $figure) {
            $cashOpening = $cashOpening->plus($figure['opening']);
            $cashMovement = $cashMovement->plus($figure['movement']);
        }
        $cashClosing = $cashOpening->plus($cashMovement);

        $netResult = $this->engine->netOfTypes($tree, ['REVENUE', 'EXPENSE'], $movement)->negated();
        $unmapped = $this->engine->unmapped($graph, self::BALANCE_SHEET_TYPES, $movement, true);
        $unmappedEffect = Money::sum(array_map(fn (array $a) => BigDecimal::of($a['amount']), $unmapped));

        $built = $this->engine->evaluate($graph, $movement, [
            'NET_RESULT' => $netResult, 'CASH_OPENING' => $cashOpening, 'CASH_CLOSING' => $cashClosing, 'NET_CASH_CHANGE' => $cashMovement, 'UNCLASSIFIED_CASH_FLOW' => $unmappedEffect,
        ], $includeZero);

        [$categories, $uncategorised] = $this->categories($graph, $movement);
        $classifiedEffect = Money::sum(array_values($categories));
        $showsUnclassified = array_filter($graph->byId, fn (ReportLine $l) => $l->system_key === 'UNCLASSIFIED_CASH_FLOW') !== [];
        $netChange = $netResult->plus($classifiedEffect)->plus($showsUnclassified ? $unmappedEffect : BigDecimal::zero());
        $calculatedClosing = $cashOpening->plus($netChange);
        $difference = $calculatedClosing->minus($cashClosing);

        $complete = $this->query->complete($dimensions);
        $unclassified = [...array_map(fn (array $a) => $a + ['reason' => 'NOT_MAPPED'], $unmapped), ...$uncategorised];
        $reconciled = $complete ? $difference->isZero() : null;

        $warnings = [...$warnings, ...$this->engine->integrityWarnings($complete, $unmapped, $difference, $complete)];
        if ($uncategorised !== []) {
            $warnings[] = [
                'code' => 'CASH_FLOW_CATEGORY_UNCLASSIFIED', 'message' => count($uncategorised).' account(s) with movement are on a line categorised UNCLASSIFIED; choose their category in the report definition.',
                'details' => ['count' => count($uncategorised), 'accounts' => array_slice($uncategorised, 0, 50)],
            ];
        }

        $report = [
            'statement' => ReportDefinition::CASH_FLOW,
            'definition' => $this->engine->describe($definition),
            'currency' => $this->engine->currency(),
            'range' => $range,
            'generated_at' => now()->toIso8601String(),
            'complete' => $complete,
            'filters' => $dimensions + ['include_zero' => $includeZero],
            'lines' => $built['lines'],
            'integrity' => [
                'net_result' => Money::str($netResult),
                'cash_opening' => Money::str($cashOpening),
                'net_change' => Money::str($netChange),
                'cash_closing_calculated' => Money::str($calculatedClosing),
                'cash_closing_ledger' => Money::str($cashClosing),
                'difference' => Money::str($difference),
                'reconciled' => $reconciled,
                'reliable' => $unclassified === [] && $reconciled !== false,
                'categories' => array_map(fn (BigDecimal $v) => Money::str($v), $categories),
                'unclassified' => $unclassified,
                'cash_accounts' => $this->cashAccounts($graph, $cash),
            ],
            'warnings' => $warnings,
        ];

        return ['report' => $report, 'rows' => $built['rows'], 'graph' => $graph, 'definition' => $definition, 'built' => $built, 'nets' => $movement];
    }

    /**
     * The cash effect of the mapped accounts per cash flow category, and the mapped accounts with movement on a line categorised UNCLASSIFIED.
     *
     * @param  array<string,BigDecimal>  $movement
     * @return array{0:array<string,BigDecimal>,1:list<array<string,mixed>>}
     */
    private function categories(DefinitionGraph $graph, array $movement): array
    {
        $totals = array_fill_keys(ReportLine::CATEGORIES, BigDecimal::zero());
        $uncategorised = [];
        foreach ($graph->byId as $line) {
            if ($line->cash_flow_category === null) {
                continue;
            }
            foreach ($graph->lineAccounts[$line->id] ?? [] as $accountId) {
                $effect = ($movement[$accountId] ?? BigDecimal::zero())->negated();
                $totals[$line->cash_flow_category] = $totals[$line->cash_flow_category]->plus($effect);
                if ($line->cash_flow_category === 'UNCLASSIFIED' && ! $effect->isZero()) {
                    $account = $graph->accounts->accounts[$accountId];
                    $uncategorised[] = ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'account_type' => $account->account_type,
                        'amount' => Money::str($effect), 'reason' => 'CATEGORY_UNCLASSIFIED'];
                }
            }
        }

        return [$totals, $uncategorised];
    }

    /**
     * @param  array<string,array{opening:BigDecimal,movement:BigDecimal}>  $cash
     * @return list<array<string,string>>
     */
    private function cashAccounts(DefinitionGraph $graph, array $cash): array
    {
        $out = [];
        foreach ($this->query->cashAccountIds() as $accountId) {
            $account = $graph->accounts->accounts[$accountId] ?? null;
            if ($account === null) {
                continue;
            }
            $figure = $cash[$accountId] ?? ['opening' => BigDecimal::zero(), 'movement' => BigDecimal::zero()];
            $out[] = [
                'id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'opening' => Money::str($figure['opening']),
                'movement' => Money::str($figure['movement']), 'closing' => Money::str($figure['opening']->plus($figure['movement'])),
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return $out;
    }
}
