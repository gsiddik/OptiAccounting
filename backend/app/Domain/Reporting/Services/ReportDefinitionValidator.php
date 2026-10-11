<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Models\ReportLine;
use App\Domain\Reporting\Support\AccountTree;
use App\Domain\Reporting\Support\DefinitionGraph;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Brick\Math\BigDecimal;

/**
 * Configuration validation before a report definition is used: the one place that knows what a sound definition is. It returns structured
 * findings and never throws for them, so the validate endpoint, publishing, the mapping editor and the statement services all see the same
 * answer; a statement refuses a definition with errors (422 REPORT_DEFINITION_INVALID) instead of producing a misleading figure.
 *
 * Errors (block publishing and statements):
 *   lines     LINE_CODE_DUPLICATE, LINE_PARENT_MISSING, LINE_PARENT_CYCLE, LINE_PARENT_TYPE_INVALID, LINE_BALANCE_SIDE_MISSING, LINE_SHAPE_INVALID,
 *             LINE_CASH_FLOW_CATEGORY_INVALID / _NOT_ALLOWED, LINE_SYSTEM_KEY_INVALID, SYSTEM_KEY_DUPLICATE, REQUIRED_SYSTEM_LINE_MISSING,
 *             OPERANDS_MISSING, OPERAND_LINE_MISSING, OPERAND_SIGN_INVALID, OPERAND_SELF_REFERENCE, OPERAND_LINE_NOT_NUMERIC, CALCULATION_CYCLE,
 *             SUBTOTAL_EMPTY, DEFINITION_EMPTY
 *   mappings  MAPPING_LINE_UNKNOWN, MAPPING_LINE_NOT_MAPPABLE, MAPPING_ACCOUNT_NOT_FOUND, MAPPING_ACCOUNT_INACTIVE, MAPPING_HEADER_NEEDS_DESCENDANTS,
 *             MAPPING_DUPLICATE_ACCOUNT, MAPPING_OVERLAP, MAPPING_ACCOUNT_TYPE_INVALID, MAPPING_CASH_ACCOUNT
 * Warnings (reported, never blocking): LINE_WITHOUT_MAPPING, MAPPING_HEADER_EMPTY, UNCLASSIFIED_LINE_MISSING, SECTION_MIXES_ASSETS_AND_FUNDING, UNMAPPED_ACCOUNT_WITH_BALANCE and, once a
 * definition is published, MAPPING_ACCOUNT_INACTIVE (an account deactivated after publishing must not make history unreportable).
 *
 * Account types by statement: balance sheet ASSET / LIABILITY / EQUITY; profit and loss REVENUE / EXPENSE; cash flow the balance-sheet types
 * but never an account that holds cash (cash is what the statement explains, not a line of it). Nothing here looks at an account code.
 */
class ReportDefinitionValidator
{
    public const ACCOUNT_TYPES = [
        ReportDefinition::BALANCE_SHEET => ['ASSET', 'LIABILITY', 'EQUITY'],
        ReportDefinition::PROFIT_LOSS => ['REVENUE', 'EXPENSE'],
        ReportDefinition::CASH_FLOW => ['ASSET', 'LIABILITY', 'EQUITY'],
    ];

    public const REQUIRED_SYSTEM_KEYS = [
        ReportDefinition::BALANCE_SHEET => ['CURRENT_RESULT'],
        ReportDefinition::PROFIT_LOSS => [],
        ReportDefinition::CASH_FLOW => ['NET_RESULT', 'CASH_OPENING', 'CASH_CLOSING'],
    ];

    /** How many unmapped accounts one warning lists in full. */
    private const LIST_LIMIT = 50;

    public function __construct(private readonly ReportQuery $query, private readonly TenantContext $context) {}

    /**
     * Validate a stored definition. `$published` is true when the definition is in use (ACTIVE / ARCHIVED): then a mapped account that was
     * deactivated since is only a warning. `$withBalances` adds the warning about unmapped accounts that carry a balance.
     *
     * @return array{valid:bool,errors:list<array<string,mixed>>,warnings:list<array<string,mixed>>}
     */
    public function validate(ReportDefinition $definition, bool $withBalances = true): array
    {
        $graph = DefinitionGraph::forDefinition($definition, new AccountTree($this->query->accounts()));

        return $this->validateGraph($definition->statement_type, $graph, $definition->status !== ReportDefinition::DRAFT, $withBalances ? $this->businessDate() : null);
    }

    /**
     * Validate any graph (a stored definition or a proposed set of lines and mappings).
     *
     * @return array{valid:bool,errors:list<array<string,mixed>>,warnings:list<array<string,mixed>>}
     */
    public function validateGraph(string $statementType, DefinitionGraph $graph, bool $published = false, ?string $unmappedAsOf = null): array
    {
        $errors = [];
        $warnings = [];
        $this->lines($statementType, $graph, $errors, $warnings);
        $this->mappings($statementType, $graph, $published, $errors, $warnings);
        if ($unmappedAsOf !== null && $errors === []) {
            $this->unmappedWarning($statementType, $graph, $unmappedAsOf, $warnings);
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * What a statement service calls before using a definition: the graph of the definition and the warnings, or 422 REPORT_DEFINITION_INVALID
     * carrying every error.
     *
     * @return array{graph:DefinitionGraph,warnings:list<array<string,mixed>>}
     */
    public function assertUsable(ReportDefinition $definition): array
    {
        $graph = DefinitionGraph::forDefinition($definition, new AccountTree($this->query->accounts()));
        $result = $this->validateGraph($definition->statement_type, $graph, $definition->status !== ReportDefinition::DRAFT);
        if (! $result['valid']) {
            throw new DomainException('The report definition has configuration errors and cannot produce a statement.', 'REPORT_DEFINITION_INVALID', 422, [
                'definition_id' => $definition->id, 'errors' => $result['errors'], 'warnings' => $result['warnings'],
            ]);
        }

        return ['graph' => $graph, 'warnings' => $result['warnings']];
    }

    /**
     * Posting accounts of the statement's types that no line covers, with their cumulative balance at `$asOf` and whether they have any activity.
     * A tenant with data scope narrower than the whole ledger sees only the balances in its scope.
     *
     * @return list<array{id:string,code:string,name:string,account_type:string,balance:string,has_activity:bool,status:string}>
     */
    public function unmappedAccounts(string $statementType, DefinitionGraph $graph, string $asOf, bool $onlyWithBalance = true): array
    {
        $types = self::ACCOUNT_TYPES[$statementType];
        $cash = $statementType === ReportDefinition::CASH_FLOW ? array_flip($this->query->cashAccountIds()) : [];
        $figures = $this->query->asOf($asOf);
        $activity = $this->query->movement('0001-01-01', $asOf);

        $out = [];
        foreach ($graph->accounts->accounts as $account) {
            if (! $account->is_postable || ! in_array($account->account_type, $types, true) || isset($graph->coverCount[$account->id]) || isset($cash[$account->id])) {
                continue;
            }
            $balance = $figures[$account->id]['net'] ?? null;
            $hasBalance = $balance !== null && ! $balance->isZero();
            $hasActivity = isset($activity[$account->id]);
            if ($onlyWithBalance && ! $hasBalance && ! ($statementType !== ReportDefinition::BALANCE_SHEET && $hasActivity)) {
                continue; // a profit-and-loss account is judged by its activity: its balance may have been closed to zero
            }
            $out[] = [
                'id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'account_type' => $account->account_type,
                'balance' => ($balance ?? BigDecimal::zero())->toScale(4)->toString(), 'has_activity' => $hasActivity, 'status' => $account->status,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------------------------------------ lines

    private function lines(string $type, DefinitionGraph $g, array &$errors, array &$warnings): void
    {
        if ($g->byId === []) {
            $errors[] = $this->finding('DEFINITION_EMPTY', 'The definition has no lines.');

            return;
        }
        foreach (array_unique($g->duplicateCodes) as $code) {
            $errors[] = $this->finding('LINE_CODE_DUPLICATE', "More than one line has the code {$code}.", $code);
        }

        $keys = [];
        foreach ($g->byId as $line) {
            $this->shape($type, $g, $line, $errors);
            if ($line->line_type === ReportLine::SYSTEM && $line->system_key !== null) {
                if (! in_array($type, ReportLine::SYSTEM_KEYS[$line->system_key] ?? [], true)) {
                    $errors[] = $this->finding('LINE_SYSTEM_KEY_INVALID', "The system figure {$line->system_key} does not belong on a {$type} statement.", $line->code);
                }
                if (isset($keys[$line->system_key])) {
                    $errors[] = $this->finding('SYSTEM_KEY_DUPLICATE', "The system figure {$line->system_key} is used on more than one line.", $line->code);
                }
                $keys[$line->system_key] = true;
            }
        }
        foreach (self::REQUIRED_SYSTEM_KEYS[$type] as $key) {
            if (! isset($keys[$key])) {
                $errors[] = $this->finding('REQUIRED_SYSTEM_LINE_MISSING', "A {$type} statement needs a SYSTEM line for {$key}.", null, ['system_key' => $key]);
            }
        }
        if ($type === ReportDefinition::CASH_FLOW && ! isset($keys['UNCLASSIFIED_CASH_FLOW'])) {
            $warnings[] = $this->finding('UNCLASSIFIED_LINE_MISSING', 'Without an UNCLASSIFIED_CASH_FLOW line, movement of accounts that no line classifies is listed in the integrity block but not on the statement.');
        }

        $this->parentCycles($g, $errors);
        $this->calculationCycles($g, $errors);
        foreach ($g->byId as $line) {
            if ($line->line_type === ReportLine::SUBTOTAL && ! $this->hasAmountBelow($g, $line)) {
                $errors[] = $this->finding('SUBTOTAL_EMPTY', 'A subtotal needs at least one line with an amount below it.', $line->code);
            }
            if (in_array($line->line_type, ReportLine::MAPPED_TYPES, true) && ! array_key_exists($line->id, $g->lineAccounts)) {
                $warnings[] = $this->finding('LINE_WITHOUT_MAPPING', 'No account is mapped to this line, so its amount is always zero.', $line->code);
            }
            if ($type === ReportDefinition::BALANCE_SHEET && $line->line_type === ReportLine::SUBTOTAL) {
                $kinds = $this->kindsUnder($g, $line);
                if (isset($kinds['ASSET']) && (isset($kinds['LIABILITY']) || isset($kinds['EQUITY']))) {
                    $warnings[] = $this->finding('SECTION_MIXES_ASSETS_AND_FUNDING', 'This subtotal adds assets to liabilities or equity; use a calculated total for a net figure.', $line->code);
                }
            }
        }
    }

    /** The shape rules of one line: what its type requires and forbids, its parent, its operands. */
    private function shape(string $type, DefinitionGraph $g, ReportLine $line, array &$errors): void
    {
        $code = $line->code;
        $kind = $line->line_type;
        if (! in_array($kind, ReportLine::TYPES, true)) {
            $errors[] = $this->finding('LINE_SHAPE_INVALID', "Unknown line type {$kind}.", $code);

            return;
        }

        if ($line->parent_id !== null) {
            $parent = $g->byId[$line->parent_id] ?? null;
            if ($parent === null) {
                $errors[] = $this->finding('LINE_PARENT_MISSING', 'The parent line does not exist in this definition.', $code);
            } elseif (! in_array($parent->line_type, [ReportLine::HEADER, ReportLine::SUBTOTAL], true)) {
                $errors[] = $this->finding('LINE_PARENT_TYPE_INVALID', "Only a HEADER or a SUBTOTAL can contain lines; {$parent->code} is a {$parent->line_type}.", $code);
            }
        }

        $mapped = in_array($kind, ReportLine::MAPPED_TYPES, true);
        if ($mapped && $line->balance_side === null) {
            $errors[] = $this->finding('LINE_BALANCE_SIDE_MISSING', 'An account line needs a balance side (DEBIT or CREDIT).', $code);
        }
        if (! $mapped && $line->balance_side !== null) {
            $errors[] = $this->finding('LINE_SHAPE_INVALID', 'Only an account line has a balance side.', $code);
        }
        if ($kind === ReportLine::SYSTEM && $line->system_key === null) {
            $errors[] = $this->finding('LINE_SHAPE_INVALID', 'A SYSTEM line needs a system figure.', $code);
        }
        if ($kind !== ReportLine::SYSTEM && $line->system_key !== null) {
            $errors[] = $this->finding('LINE_SHAPE_INVALID', 'Only a SYSTEM line names a system figure.', $code);
        }
        if ($kind !== ReportLine::CALCULATED_TOTAL && ! empty($line->operands)) {
            $errors[] = $this->finding('LINE_SHAPE_INVALID', 'Only a CALCULATED_TOTAL has operands.', $code);
        }

        $category = $line->cash_flow_category;
        if ($type === ReportDefinition::CASH_FLOW) {
            if ($mapped && ! in_array($category, ReportLine::CATEGORIES, true)) {
                $errors[] = $this->finding('LINE_CASH_FLOW_CATEGORY_INVALID', 'A cash flow account line needs a category: '.implode(', ', ReportLine::CATEGORIES).'.', $code, ['category' => $category]);
            }
            if (! $mapped && $category !== null) {
                $errors[] = $this->finding('LINE_CASH_FLOW_CATEGORY_NOT_ALLOWED', 'Only a line that maps accounts has a cash flow category.', $code);
            }
        } elseif ($category !== null) {
            $errors[] = $this->finding('LINE_CASH_FLOW_CATEGORY_NOT_ALLOWED', 'A cash flow category belongs on a cash flow definition only.', $code);
        }

        if ($kind === ReportLine::CALCULATED_TOTAL) {
            $operands = (array) $line->operands;
            if ($operands === []) {
                $errors[] = $this->finding('OPERANDS_MISSING', 'A calculated total needs at least one operand.', $code);
            }
            foreach ($operands as $i => $operand) {
                $target = is_array($operand) ? ($g->byCode[$operand['line'] ?? ''] ?? null) : null;
                if (! is_array($operand) || ! in_array($operand['sign'] ?? null, ['+', '-'], true)) {
                    $errors[] = $this->finding('OPERAND_SIGN_INVALID', 'An operand is {"line": code, "sign": "+" or "-"}.', $code, ['operand' => $i]);
                } elseif ($target === null) {
                    $errors[] = $this->finding('OPERAND_LINE_MISSING', "The operand line {$operand['line']} does not exist in this definition.", $code, ['operand' => $operand['line']]);
                } elseif ($target->id === $line->id) {
                    $errors[] = $this->finding('OPERAND_SELF_REFERENCE', 'A calculated total cannot use itself.', $code);
                } elseif ($target->line_type === ReportLine::HEADER) {
                    $errors[] = $this->finding('OPERAND_LINE_NOT_NUMERIC', "{$target->code} is a header and has no amount to use.", $code, ['operand' => $target->code]);
                }
            }
        }
    }

    private function parentCycles(DefinitionGraph $g, array &$errors): void
    {
        $reported = [];
        foreach ($g->byId as $line) {
            $seen = [$line->id => true];
            for ($node = $g->parentOf($line); $node !== null; $node = $g->parentOf($node)) {
                if (isset($seen[$node->id])) {
                    if (! isset($reported[$node->id])) {
                        $errors[] = $this->finding('LINE_PARENT_CYCLE', 'The parent chain of this line loops back to itself.', $node->code);
                        $reported[$node->id] = true;
                    }
                    break;
                }
                $seen[$node->id] = true;
            }
        }
    }

    /** SUBTOTAL depends on the amount-bearing lines below it, CALCULATED_TOTAL on its operands; any loop would never evaluate. */
    private function calculationCycles(DefinitionGraph $g, array &$errors): void
    {
        $deps = [];
        foreach ($g->byId as $line) {
            if ($line->line_type === ReportLine::SUBTOTAL) {
                $deps[$line->id] = array_map(fn (ReportLine $l) => $l->id, $g->contributors($line));
            } elseif ($line->line_type === ReportLine::CALCULATED_TOTAL) {
                $deps[$line->id] = [];
                foreach ((array) $line->operands as $operand) {
                    if (is_array($operand) && isset($g->byCode[$operand['line'] ?? ''])) {
                        $deps[$line->id][] = $g->byCode[$operand['line']]->id;
                    }
                }
            }
        }

        $state = [];
        $loops = [];
        $visit = function (string $id) use (&$visit, &$state, &$loops, $deps): void {
            if (($state[$id] ?? 0) === 2) {
                return;
            }
            if (($state[$id] ?? 0) === 1) {
                $loops[$id] = true;

                return;
            }
            $state[$id] = 1;
            foreach ($deps[$id] ?? [] as $next) {
                $visit($next);
            }
            $state[$id] = 2;
        };
        foreach (array_keys($deps) as $id) {
            $visit($id);
        }
        foreach (array_keys($loops) as $id) {
            $errors[] = $this->finding('CALCULATION_CYCLE', 'This line (directly or through other totals) depends on itself.', $g->byId[$id]->code);
        }
    }

    /** @return array<string,true> the account types (and EQUITY for the current-result figure) that stand below a line */
    private function kindsUnder(DefinitionGraph $g, ReportLine $line, int $depth = 0): array
    {
        $kinds = [];
        if ($line->line_type === ReportLine::SYSTEM && $line->system_key === 'CURRENT_RESULT') {
            $kinds['EQUITY'] = true;
        }
        foreach ($g->lineAccounts[$line->id] ?? [] as $accountId) {
            $kinds[$g->accounts->accounts[$accountId]->account_type] = true;
        }
        if ($depth < 50) {
            foreach ($g->childrenOf($line->id) as $child) {
                $kinds += $this->kindsUnder($g, $child, $depth + 1);
            }
        }

        return $kinds;
    }

    private function hasAmountBelow(DefinitionGraph $g, ReportLine $subtotal): bool
    {
        return $g->contributors($subtotal) !== [];
    }

    // ------------------------------------------------------------------------------------------------ mappings

    private function mappings(string $type, DefinitionGraph $g, bool $published, array &$errors, array &$warnings): void
    {
        $allowed = self::ACCOUNT_TYPES[$type];
        $cash = $type === ReportDefinition::CASH_FLOW ? array_flip($this->query->cashAccountIds()) : [];
        $direct = [];
        $duplicated = [];

        foreach ($g->mappings as $mapping) {
            $line = $g->byId[$mapping['line_id']] ?? null;
            $accountId = $mapping['account_id'];
            if ($line === null) {
                $errors[] = $this->finding('MAPPING_LINE_UNKNOWN', 'A mapping points at a line that is not in this definition.', null, ['account_id' => $accountId]);

                continue;
            }
            if (! in_array($line->line_type, ReportLine::MAPPED_TYPES, true)) {
                $errors[] = $this->finding('MAPPING_LINE_NOT_MAPPABLE', "Accounts can only be mapped to ACCOUNT and ACCOUNT_GROUP lines; {$line->code} is a {$line->line_type}.", $line->code, ['account_id' => $accountId]);
            }
            $account = $g->accounts->accounts[$accountId] ?? null;
            if ($account === null) {
                $errors[] = $this->finding('MAPPING_ACCOUNT_NOT_FOUND', 'The mapped account is not in this tenant\'s chart of accounts.', $line->code, ['account_id' => $accountId]);

                continue;
            }
            if (isset($direct[$accountId])) {
                $duplicated[$accountId] = true;
                $errors[] = $this->finding('MAPPING_DUPLICATE_ACCOUNT', "Account {$account->code} is mapped more than once.", $line->code, ['account_id' => $accountId]);
            }
            $direct[$accountId] = true;

            if ($account->status !== Account::ACTIVE) {
                $finding = $this->finding('MAPPING_ACCOUNT_INACTIVE', "Account {$account->code} is inactive.", $line->code, ['account_id' => $accountId]);
                $published ? $warnings[] = $finding : $errors[] = $finding;
            }
            if (! $account->is_postable && ! $mapping['include_descendants']) {
                $errors[] = $this->finding('MAPPING_HEADER_NEEDS_DESCENDANTS', "Account {$account->code} is a header; map it with include_descendants to cover the accounts below it.", $line->code, ['account_id' => $accountId]);
            }
            $covered = ($account->is_postable || $mapping['include_descendants']) ? $g->accounts->postingUnder($account->id) : [];
            if (! $account->is_postable && $mapping['include_descendants'] && $covered === []) {
                $warnings[] = $this->finding('MAPPING_HEADER_EMPTY', "Header {$account->code} has no posting account below it.", $line->code, ['account_id' => $accountId]);
            }
            foreach (array_unique([$account->id, ...$covered]) as $coveredId) {
                $coveredAccount = $g->accounts->accounts[$coveredId];
                if (! in_array($coveredAccount->account_type, $allowed, true)) {
                    $errors[] = $this->finding('MAPPING_ACCOUNT_TYPE_INVALID', "A {$type} statement does not use {$coveredAccount->account_type} accounts ({$coveredAccount->code}).", $line->code, ['account_id' => $coveredId, 'account_type' => $coveredAccount->account_type]);
                }
                if (isset($cash[$coveredId])) {
                    $errors[] = $this->finding('MAPPING_CASH_ACCOUNT', "Account {$coveredAccount->code} holds cash; a cash flow statement explains cash and cannot list it as a line.", $line->code, ['account_id' => $coveredId]);
                }
            }
        }

        foreach ($g->coverCount as $accountId => $count) {
            if ($count > 1 && ! isset($duplicated[$accountId])) {
                $account = $g->accounts->accounts[$accountId];
                $errors[] = $this->finding('MAPPING_OVERLAP', "Account {$account->code} is covered by {$count} mappings; it would be counted more than once.", null, ['account_id' => $accountId, 'lines' => $this->codes($g, $g->coverage[$accountId] ?? [])]);
            }
        }
    }

    private function unmappedWarning(string $type, DefinitionGraph $g, string $asOf, array &$warnings): void
    {
        $unmapped = $this->unmappedAccounts($type, $g, $asOf);
        if ($unmapped !== []) {
            $warnings[] = $this->finding('UNMAPPED_ACCOUNT_WITH_BALANCE', count($unmapped).' account(s) with a balance or activity are not covered by any line.', null, [
                'as_of' => $asOf, 'count' => count($unmapped), 'accounts' => array_slice($unmapped, 0, self::LIST_LIMIT),
            ]);
        }
    }

    // ------------------------------------------------------------------------------------------------ helpers

    /** @param  list<string>  $lineIds @return list<string> */
    private function codes(DefinitionGraph $g, array $lineIds): array
    {
        return array_values(array_map(fn ($id) => $g->byId[$id]->code ?? $id, $lineIds));
    }

    /** @return array{code:string,message:string,line:?string,details:array<string,mixed>} */
    private function finding(string $code, string $message, ?string $line = null, array $details = []): array
    {
        return ['code' => $code, 'message' => $message, 'line' => $line, 'details' => $details];
    }

    private function businessDate(): string
    {
        return Tenant::query()->findOrFail($this->context->tenantId())->businessDate();
    }
}
