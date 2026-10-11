<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Support\Money;
use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Models\ReportLine;
use App\Domain\Reporting\Support\AccountTree;
use App\Domain\Reporting\Support\DefinitionGraph;
use App\Domain\Shared\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Turns a report definition (line tree + account mapping) and per-account figures into the ordered lines of a statement. It holds no query and no
 * rule about which figures a statement needs: the statement services (balance sheet, profit and loss, cash flow) pick the definition, fetch the
 * figures once through `ReportQuery` and hand them over, so every statement has the same evaluation and the same line shape.
 *
 * Evaluation (BigDecimal throughout, deterministic, each line computed once):
 *   ACCOUNT / ACCOUNT_GROUP  sum over the posting accounts the line covers of net (debit - credit), negated for a CREDIT balance side
 *   SUBTOTAL                 sum of the amounts of the lines directly below it (through HEADER lines), a nested subtotal counting once
 *   CALCULATED_TOTAL         sum of its operands, each added or subtracted; operands are other line codes, there is no formula language
 *   SYSTEM                   a figure the statement service supplies by key (current result, net result, cash opening, ...)
 *   HEADER                   a label; no amount
 * Order: parents before children, except a SUBTOTAL, which follows the lines it totals (a "Total ..." row sits under its section).
 * Zero lines are left out unless asked for (`include_zero`, the line's own `show_zero`, or an emphasised subtotal); a HEADER with nothing under it
 * is left out; totals that carry a result (calculated, system) always stay. Hiding a zero line never changes any total.
 *
 * `presented` is the amount each covered account shows on its line; the integrity blocks of the statements add these up, so a layout that puts an
 * account on the wrong side of the statement shows as a difference instead of hiding it.
 */
class StatementEngine
{
    /** Definition warnings that matter to the reader of a statement (the rest belong to the authoring screen). */
    private const READER_WARNINGS = ['SECTION_MIXES_ASSETS_AND_FUNDING'];

    public function __construct(private readonly ReportDefinitionResolver $resolver, private readonly ReportDefinitionValidator $validator) {}

    /**
     * The definition a statement uses and its in-memory graph; a definition with configuration errors is refused (422 REPORT_DEFINITION_INVALID).
     *
     * @return array{definition:ReportDefinition,graph:DefinitionGraph,warnings:list<array<string,mixed>>}
     */
    public function prepare(string $statementType, string $from, string $to, ?string $definitionId = null): array
    {
        ['definition' => $definition, 'warnings' => $warnings] = $this->resolver->resolveForRange($statementType, $from, $to, $definitionId);
        $usable = $this->validator->assertUsable($definition);
        $reader = array_values(array_filter($usable['warnings'], fn ($w) => in_array($w['code'], self::READER_WARNINGS, true)));

        return ['definition' => $definition, 'graph' => $usable['graph'], 'warnings' => [...$warnings, ...$reader]];
    }

    /**
     * `lines` are the visible lines; `rows` are every line in the same order with a `visible` flag (a comparison shows a line when either side does);
     * `amounts` is keyed by line code and holds every line, shown or not; `presented` is keyed by account id.
     *
     * @param  array<string,BigDecimal>  $nets  account id => net (debit - credit) in the statement's own semantics (as of a date, or movement)
     * @param  array<string,BigDecimal>  $system  system key => amount, for the SYSTEM lines of the definition
     * @return array{lines:list<array<string,mixed>>,rows:list<array<string,mixed>>,amounts:array<string,?BigDecimal>,presented:array<string,BigDecimal>}
     */
    public function evaluate(DefinitionGraph $graph, array $nets, array $system = [], bool $includeZero = false): array
    {
        $accounts = $graph->accounts->accounts;
        $amounts = [];
        $presented = [];
        $visiting = [];

        $amountOf = function (ReportLine $line) use (&$amountOf, &$amounts, &$presented, &$visiting, $graph, $nets, $system): ?BigDecimal {
            if (array_key_exists($line->id, $amounts)) {
                return $amounts[$line->id];
            }
            if (isset($visiting[$line->id])) {
                throw new DomainException('The report definition has a calculation loop.', 'REPORT_DEFINITION_INVALID', 422, ['line' => $line->code]);
            }
            $visiting[$line->id] = true;

            $amount = match ($line->line_type) {
                ReportLine::HEADER => null,
                ReportLine::ACCOUNT, ReportLine::ACCOUNT_GROUP => $this->mapped($graph, $line, $nets, $presented),
                ReportLine::SUBTOTAL => Money::sum(array_map(fn (ReportLine $l) => $amountOf($l) ?? BigDecimal::zero(), $graph->contributors($line))),
                ReportLine::CALCULATED_TOTAL => $this->calculated($graph, $line, $amountOf),
                ReportLine::SYSTEM => $system[$line->system_key] ?? BigDecimal::zero(),
            };
            unset($visiting[$line->id]);

            return $amounts[$line->id] = $amount;
        };

        foreach ($graph->byId as $line) {
            $amountOf($line);
        }

        $emit = function (ReportLine $line, int $depth) use (&$emit, $amounts, $presented, $graph, $accounts, $includeZero): array {
            $below = [];
            foreach ($graph->childrenOf($line->id) as $child) {
                array_push($below, ...$emit($child, $depth + 1));
            }
            $shownBelow = array_filter($below, fn (array $r) => $r['visible']) !== [];
            $amount = $amounts[$line->id];
            $visible = match ($line->line_type) {
                ReportLine::HEADER => $shownBelow || $includeZero,
                ReportLine::SUBTOTAL => $shownBelow || $includeZero || $line->show_zero || $line->emphasis || ! $amount->isZero(),
                ReportLine::ACCOUNT, ReportLine::ACCOUNT_GROUP => $includeZero || $line->show_zero || ! $amount->isZero(),
                default => true,
            };
            $row = $this->row($graph, $line, $depth, $amount, $presented, $accounts, $includeZero) + ['visible' => $visible];

            return $line->line_type === ReportLine::HEADER ? [$row, ...$below] : [...$below, $row];
        };

        $rows = [];
        foreach ($graph->roots() as $root) {
            array_push($rows, ...$emit($root, 0));
        }
        $lines = array_values(array_map(fn (array $r) => array_diff_key($r, ['visible' => 1]), array_filter($rows, fn (array $r) => $r['visible'])));

        $byCode = [];
        foreach ($graph->byId as $line) {
            $byCode[$line->code] = $amounts[$line->id];
        }

        return ['lines' => $lines, 'rows' => $rows, 'amounts' => $byCode, 'presented' => $presented];
    }

    /**
     * Posting accounts of the given types that no line of the definition covers and that carry a figure: what the statement would silently leave out.
     * The amount is in the type's natural direction (assets and expenses debit minus credit, the others credit minus debit), or with `$cashEffect`
     * the effect on cash (credit minus debit) that a cash flow statement shows.
     *
     * @param  list<string>  $types
     * @param  array<string,BigDecimal>  $nets
     * @return list<array{id:string,code:string,name:string,account_type:string,amount:string}>
     */
    public function unmapped(DefinitionGraph $graph, array $types, array $nets, bool $cashEffect = false): array
    {
        $out = [];
        foreach ($graph->accounts->accounts as $account) {
            $net = $nets[$account->id] ?? null;
            if (! $account->is_postable || ! in_array($account->account_type, $types, true) || isset($graph->coverCount[$account->id]) || $net === null || $net->isZero()) {
                continue;
            }
            $out[] = [
                'id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'account_type' => $account->account_type,
                'amount' => Money::str($cashEffect ? $net->negated() : self::natural($account->account_type, $net)),
            ];
        }

        return $out;
    }

    /** The sum of net (debit - credit) of every posting account of the given types, mapped or not: the ledger's own figure for those types. @param list<string> $types @param array<string,BigDecimal> $nets */
    public function netOfTypes(AccountTree $tree, array $types, array $nets): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($tree->accounts as $account) {
            if ($account->is_postable && in_array($account->account_type, $types, true)) {
                $total = $total->plus($nets[$account->id] ?? BigDecimal::zero());
            }
        }

        return $total;
    }

    /** Sum of what the covered accounts of the given types show on the statement. @param list<string> $types @param array<string,BigDecimal> $presented */
    public function presentedOfTypes(AccountTree $tree, array $types, array $presented): BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($presented as $accountId => $amount) {
            if (in_array($tree->accounts[$accountId]->account_type, $types, true)) {
                $total = $total->plus($amount);
            }
        }

        return $total;
    }

    /** A net figure in the natural direction of an account type. */
    public static function natural(string $accountType, BigDecimal $net): BigDecimal
    {
        return in_array($accountType, ['ASSET', 'EXPENSE'], true) ? $net : $net->negated();
    }

    /** The header block of a statement: which version of which definition produced the figures. @return array<string,mixed> */
    public function describe(ReportDefinition $definition): array
    {
        return $this->resolver->describe($definition);
    }

    /**
     * What a reader must be told about the reliability of a statement: figures that cover only part of the ledger, accounts the layout leaves out,
     * and a difference between the ledger and what the statement shows. None of them changes a figure; none is absorbed into a line.
     *
     * @param  list<array<string,mixed>>  $unmapped
     * @param  bool  $differenceMatters  false when a partial ledger makes a difference expected (a branch does not balance on its own)
     * @return list<array<string,mixed>>
     */
    public function integrityWarnings(bool $complete, array $unmapped, BigDecimal $difference, bool $differenceMatters): array
    {
        $warnings = [];
        if (! $complete) {
            $warnings[] = ['code' => 'PARTIAL_LEDGER', 'message' => 'The figures cover only part of the ledger (data scope or filters), so they are not expected to balance on their own.', 'details' => []];
        }
        if ($unmapped !== []) {
            $warnings[] = [
                'code' => 'UNMAPPED_ACCOUNT_WITH_BALANCE', 'message' => count($unmapped).' account(s) with a figure are not on any line of the report definition.',
                'details' => ['count' => count($unmapped), 'accounts' => array_slice($unmapped, 0, 50)],
            ];
        }
        if ($differenceMatters && ! $difference->isZero()) {
            $warnings[] = ['code' => 'STATEMENT_DIFFERENCE', 'message' => 'The statement does not agree with the ledger; the difference is shown, not absorbed.', 'details' => ['difference' => Money::str($difference)]];
        }

        return $warnings;
    }

    /** The functional currency every statement is expressed in. */
    public function currency(): ?string
    {
        return AccountingProfile::query()->value('functional_currency');
    }

    /** @param array<string,BigDecimal> $nets @param array<string,BigDecimal> $presented */
    private function mapped(DefinitionGraph $graph, ReportLine $line, array $nets, array &$presented): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ($graph->lineAccounts[$line->id] ?? [] as $accountId) {
            $net = $nets[$accountId] ?? BigDecimal::zero();
            $value = $line->balance_side === 'CREDIT' ? $net->negated() : $net;
            $presented[$accountId] = $value;
            $sum = $sum->plus($value);
        }

        return $sum;
    }

    private function calculated(DefinitionGraph $graph, ReportLine $line, callable $amountOf): BigDecimal
    {
        $sum = BigDecimal::zero();
        foreach ((array) $line->operands as $operand) {
            $target = $graph->byCode[$operand['line']];
            $value = $amountOf($target) ?? BigDecimal::zero();
            $sum = $operand['sign'] === '-' ? $sum->minus($value) : $sum->plus($value);
        }

        return $sum;
    }

    /**
     * @param  array<string,BigDecimal>  $presented
     * @param  Collection<string,Account>  $accounts
     * @return array<string,mixed>
     */
    private function row(DefinitionGraph $graph, ReportLine $line, int $depth, ?BigDecimal $amount, array $presented, Collection $accounts, bool $includeZero): array
    {
        $listed = [];
        foreach ($graph->lineAccounts[$line->id] ?? [] as $accountId) {
            $value = $presented[$accountId] ?? BigDecimal::zero();
            if ($includeZero || ! $value->isZero()) {
                $account = $accounts[$accountId];
                $listed[] = ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'amount' => Money::str($value)];
            }
        }
        usort($listed, fn ($a, $b) => strcmp($a['code'], $b['code']));

        return [
            'id' => $line->id, 'code' => $line->code, 'label' => $line->label, 'line_type' => $line->line_type, 'depth' => $depth,
            'parent_code' => $graph->parentOf($line)?->code, 'amount' => $amount === null ? null : Money::str($amount), 'emphasis' => $line->emphasis,
            'accounts' => $listed, 'balance_side' => $line->balance_side, 'system_key' => $line->system_key, 'cash_flow_category' => $line->cash_flow_category, 'notes' => $line->notes,
        ];
    }
}
