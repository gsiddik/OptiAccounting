<?php

namespace App\Domain\Closing\Services;

use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Services\ReportDefinitionResolver;
use App\Domain\Reporting\Services\ReportDefinitionValidator;
use App\Domain\Reporting\Services\ReportQuery;
use App\Domain\Reporting\Support\AccountTree;
use App\Domain\Reporting\Support\DefinitionGraph;
use App\Domain\Shared\DomainException;

/**
 * The REPORT_MAPPING close check's view of the report configuration: which accounts that carry a balance at a date are not covered by the
 * report definitions that apply on that date. The rules (what counts as covered, which account types a statement draws on, how a profit and
 * loss account that was closed to zero is judged) belong to the Reporting area; this class only asks its validator for the unmapped accounts of
 * the definition in force at the period end, for the balance sheet and the profit and loss. It stores nothing.
 */
class ReportMappingCoverage
{
    private const STATEMENTS = [ReportDefinition::BALANCE_SHEET, ReportDefinition::PROFIT_LOSS];

    public function __construct(
        private readonly ReportDefinitionResolver $definitions,
        private readonly ReportDefinitionValidator $validator,
        private readonly ReportQuery $query,
    ) {}

    /**
     * @return array{definitions:list<array<string,mixed>>,unmapped:list<array<string,mixed>>}|null null when no published definition applies on $asOf
     */
    public function unmappedWithBalance(string $asOf): ?array
    {
        $found = [];
        $unmapped = [];
        foreach (self::STATEMENTS as $statement) {
            try {
                $definition = $this->definitions->resolve($statement, $asOf);
            } catch (DomainException $e) {
                if ($e->errorCode === 'REPORT_DEFINITION_NOT_FOUND') {
                    continue;
                }
                throw $e;
            }
            $found[] = ['id' => $definition->id, 'statement_type' => $statement, 'code' => $definition->code, 'version' => (int) $definition->version];
            $graph = DefinitionGraph::forDefinition($definition, new AccountTree($this->query->accounts()));
            foreach ($this->validator->unmappedAccounts($statement, $graph, $asOf) as $account) {
                $unmapped[] = [
                    'definition' => $definition->code, 'version' => (int) $definition->version, 'statement_type' => $statement,
                    'account_id' => $account['id'], 'account_code' => $account['code'], 'account_name' => $account['name'], 'balance' => $account['balance'],
                ];
            }
        }

        return $found === [] ? null : ['definitions' => $found, 'unmapped' => $unmapped];
    }
}
