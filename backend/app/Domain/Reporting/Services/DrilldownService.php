<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Services\BalanceCalculator;
use App\Domain\Accounting\Support\Money;
use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Models\ReportLine;
use App\Domain\Reporting\Support\DefinitionGraph;
use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;

/**
 * What stands behind one line of a statement: the lines it adds up and the accounts under them, each with its amount and the exact general ledger
 * query (account, dates, dimensions) that lists the journal lines behind that amount.
 *
 * It re-runs the statement itself (same service, same filters, same user, so the same tenant and data scope), then reads the line from the result;
 * there is no second calculation and no total that the statement would not show to this user. An account the user's scope cannot see simply has no
 * figure here. The caller needs the view permission of the statement and of the general ledger it points to.
 *
 * `accounts[].contribution` is what the account adds to the line (signed, in the line's own presentation), so for a line built from accounts the
 * contributions add up to the line amount; a part that has no account behind it (a system figure inside a calculation) is `not_explained_by_accounts`.
 */
class DrilldownService
{
    /** Why a system line has the figure it has. */
    private const SYSTEM_EXPLANATION = [
        'CURRENT_RESULT' => 'Result of the revenue and expense accounts not yet closed to equity, as of the date: revenue minus expense, every such account with a balance.',
        'NET_RESULT' => 'Result of the revenue and expense accounts over the period: revenue minus expense, every such account with activity (the ledger\'s own figure, mapped or not).',
        'CASH_OPENING' => 'Balance of the cash and bank accounts at the start of the period.',
        'CASH_CLOSING' => 'Balance of the cash and bank accounts at the end of the period, from the ledger.',
        'NET_CASH_CHANGE' => 'Movement of the cash and bank accounts over the period, from the ledger.',
        'UNCLASSIFIED_CASH_FLOW' => 'Cash effect of balance-sheet accounts with movement that no line of the definition classifies.',
    ];

    public function __construct(
        private readonly BalanceSheetService $balanceSheet,
        private readonly ProfitLossService $profitLoss,
        private readonly CashFlowService $cashFlow,
        private readonly ReportAccess $access,
        private readonly BalanceCalculator $balances,
    ) {}

    /**
     * @param  array<string,mixed>  $filters  statement, line (code), the statement's own date filters, definition_id, dimensions, include_zero
     * @return array<string,mixed>
     */
    public function report(array $filters): array
    {
        $statement = $filters['statement'];
        $this->access->assertStatement($statement);
        $this->access->assertLedger();

        $run = match ($statement) {
            ReportDefinition::BALANCE_SHEET => $this->balanceSheet->evaluate($filters),
            ReportDefinition::PROFIT_LOSS => $this->profitLoss->evaluate($filters),
            default => $this->cashFlow->evaluate($filters),
        };
        /** @var DefinitionGraph $graph */
        $graph = $run['graph'];
        $report = $run['report'];
        $rows = array_column($run['rows'], null, 'code');
        $row = $rows[$filters['line']] ?? throw new DomainException('The report has no line with this code.', 'REPORT_LINE_NOT_FOUND', 404, ['line' => $filters['line']]);
        $line = $graph->byCode[$row['code']];
        if ($line->line_type === ReportLine::HEADER) {
            throw new DomainException('A header line has no amount to explain.', 'REPORT_LINE_NOT_DRILLABLE', 422, ['line' => $row['code']]);
        }

        $dimensions = array_intersect_key($filters, array_flip(['branch_id', 'business_unit_id', 'cost_center_id']));
        $window = $statement === ReportDefinition::BALANCE_SHEET
            ? ['from' => $this->balances->range(['to' => $report['as_of']])['from'], 'to' => $report['as_of']]
            : $report['range'];
        $gl = fn (string $accountId): array => ['account_id' => $accountId] + $window + $dimensions;

        $includeZero = (bool) ($filters['include_zero'] ?? false);
        $components = $this->components($graph, $line, $rows);
        $accounts = $line->line_type === ReportLine::SYSTEM
            ? $this->systemAccounts($statement, $line, $run)
            : $this->accountsBehind($graph, $line, $rows, 1, $statement, $run);
        $accounts = array_values(array_filter($accounts, fn (array $a) => $includeZero || ! BigDecimal::of($a['contribution'])->isZero()));
        $accounts = array_map(fn (array $a) => $a + ['gl_query' => $gl($a['id'])], $accounts);
        usort($accounts, fn (array $a, array $b) => [$a['code'], $a['line_code']] <=> [$b['code'], $b['line_code']]);

        $explained = Money::sum(array_map(fn (array $a) => BigDecimal::of($a['contribution']), $accounts));
        $periodStatement = $statement !== ReportDefinition::BALANCE_SHEET;

        return [
            'statement' => $statement,
            'definition' => $report['definition'],
            'currency' => $report['currency'],
        ] + ($statement === ReportDefinition::BALANCE_SHEET ? ['as_of' => $report['as_of']] : ['range' => $report['range']]) + [
            'generated_at' => now()->toIso8601String(),
            'complete' => $report['complete'],
            'filters' => $report['filters'],
            'line' => array_intersect_key($row, array_flip(['code', 'label', 'line_type', 'depth', 'parent_code', 'amount', 'emphasis', 'balance_side', 'system_key', 'cash_flow_category', 'notes'])),
            'explanation' => $line->line_type === ReportLine::SYSTEM ? (self::SYSTEM_EXPLANATION[$line->system_key] ?? null) : null,
            'components' => $components,
            'accounts' => $accounts,
            'accounts_total' => Money::str($explained),
            'not_explained_by_accounts' => Money::str(BigDecimal::of($row['amount'])->minus($explained)),
            'general_ledger' => [
                'endpoint' => 'general-ledger',
                'closing_journals_listed' => true,
                'note' => $periodStatement
                    ? 'The amount of an account is its movement in the period (the debit and credit totals of the ledger query); the ledger\'s opening and closing balances are cumulative. The ledger lists every posted journal, including year-end closing journals and their reversals, which this period statement leaves out: in a period that holds a closing journal the ledger totals of revenue and expense accounts differ from the statement by exactly those journals.'
                    : 'The general ledger lists every posted journal line of the dates given; the balance sheet is cumulative, so the ledger\'s opening balance of the first date plus its movement equals the amount of the account here.',
            ],
            'warnings' => $report['warnings'],
        ];
    }

    /**
     * The lines the amount of a line is made of, with their amounts, signed by their effect on it (an exact decomposition: they add up to the line).
     *
     * @param  array<string,array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function components(DefinitionGraph $graph, ReportLine $line, array $rows): array
    {
        $parts = match ($line->line_type) {
            ReportLine::SUBTOTAL => array_map(fn (ReportLine $l) => ['code' => $l->code, 'sign' => '+'], $graph->contributors($line)),
            ReportLine::CALCULATED_TOTAL => array_map(fn (array $o) => ['code' => $o['line'], 'sign' => $o['sign']], (array) $line->operands),
            default => [],
        };

        return array_map(function (array $part) use ($rows) {
            $row = $rows[$part['code']];

            return ['code' => $row['code'], 'label' => $row['label'], 'line_type' => $row['line_type'], 'sign' => $part['sign'], 'amount' => $row['amount']];
        }, $parts);
    }

    /**
     * The accounts that stand behind a line, found by following its components down to the lines that map accounts.
     *
     * @param  array<string,array<string,mixed>>  $rows
     * @param  array<string,mixed>  $run
     * @return list<array<string,mixed>>
     */
    private function accountsBehind(DefinitionGraph $graph, ReportLine $line, array $rows, int $sign, string $statement, array $run, int $depth = 0): array
    {
        if ($depth > 50) {
            return [];
        }
        $out = [];
        switch ($line->line_type) {
            case ReportLine::ACCOUNT:
            case ReportLine::ACCOUNT_GROUP:
                foreach ($rows[$line->code]['accounts'] as $account) {
                    $contribution = $sign === 1 ? BigDecimal::of($account['amount']) : BigDecimal::of($account['amount'])->negated();
                    $out[] = $account + ['line_code' => $line->code, 'contribution' => Money::str($contribution)];
                }
                break;
            case ReportLine::SUBTOTAL:
                foreach ($graph->contributors($line) as $child) {
                    array_push($out, ...$this->accountsBehind($graph, $child, $rows, $sign, $statement, $run, $depth + 1));
                }
                break;
            case ReportLine::CALCULATED_TOTAL:
                foreach ((array) $line->operands as $operand) {
                    array_push($out, ...$this->accountsBehind($graph, $graph->byCode[$operand['line']], $rows, $operand['sign'] === '-' ? -$sign : $sign, $statement, $run, $depth + 1));
                }
                break;
            case ReportLine::SYSTEM:
                foreach ($this->systemAccounts($statement, $line, $run) as $account) {
                    $contribution = BigDecimal::of($account['contribution']);
                    $out[] = ['contribution' => Money::str($sign === 1 ? $contribution : $contribution->negated())] + $account;
                }
                break;
            default:
                break; // a header carries no amount
        }

        return $out;
    }

    /**
     * The accounts behind a system figure, taken from the same numbers the statement was built from.
     *
     * @param  array<string,mixed>  $run
     * @return list<array<string,mixed>>
     */
    private function systemAccounts(string $statement, ReportLine $line, array $run): array
    {
        $tree = $run['graph']->accounts;
        $integrity = $run['report']['integrity'];
        $entry = fn (string $id, string $code, string $name, BigDecimal|string $value): array => [
            'id' => $id, 'code' => $code, 'name' => $name, 'amount' => Money::str($value), 'line_code' => $line->code, 'contribution' => Money::str($value),
        ];

        switch ($line->system_key) {
            case 'CURRENT_RESULT':
            case 'NET_RESULT':
                $out = [];
                foreach ($run['nets'] as $accountId => $net) {
                    $account = $tree->accounts[$accountId] ?? null;
                    if ($account !== null && $account->is_postable && in_array($account->account_type, ['REVENUE', 'EXPENSE'], true)) {
                        $out[] = $entry($account->id, $account->code, $account->name, $net->negated());
                    }
                }

                return $out;
            case 'CASH_OPENING':
            case 'CASH_CLOSING':
            case 'NET_CASH_CHANGE':
                $field = ['CASH_OPENING' => 'opening', 'CASH_CLOSING' => 'closing', 'NET_CASH_CHANGE' => 'movement'][$line->system_key];

                return array_map(fn (array $a) => $entry($a['id'], $a['code'], $a['name'], $a[$field]), (array) ($integrity['cash_accounts'] ?? []));
            case 'UNCLASSIFIED_CASH_FLOW':
                $out = [];
                foreach ((array) ($integrity['unclassified'] ?? []) as $a) {
                    if (($a['reason'] ?? null) === 'NOT_MAPPED') {
                        $out[] = $entry($a['id'], $a['code'], $a['name'], $a['amount']);
                    }
                }

                return $out;
            default:
                return [];
        }
    }
}
