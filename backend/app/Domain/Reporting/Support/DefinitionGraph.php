<?php

namespace App\Domain\Reporting\Support;

use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Models\ReportLine;
use App\Domain\Reporting\Models\ReportLineMapping;
use Illuminate\Support\Collection;

/**
 * One report definition in memory: its lines as a tree and its mappings expanded to the posting accounts each line covers. The validator, the
 * statement engine, the defaults builder and the drill-down all read this same structure, so "which accounts stand behind this line" has one answer.
 * It can be built from stored rows or from a proposed (unsaved) set of lines and mappings, so a change can be validated before it is written.
 *
 * A mapped posting account stands for itself; a mapped header account with `include_descendants` stands for every posting account below it;
 * a header without it covers nothing (the validator reports it). `coverage` lists, per posting account, every line that covers it: more than one
 * is an overlap, which makes the definition invalid, so statements never count an account twice.
 */
final class DefinitionGraph
{
    /** @var array<string,ReportLine> id => line */
    public array $byId = [];

    /** @var array<string,ReportLine> code => first line with that code */
    public array $byCode = [];

    /** @var list<string> */
    public array $duplicateCodes = [];

    /** @var array<string,list<ReportLine>> parent id ('' = root) => children in display order */
    public array $children = [];

    /** @var list<array{line_id:string,account_id:string,include_descendants:bool}> */
    public array $mappings = [];

    /** @var array<string,list<string>> posting account id => ids of the lines that cover it */
    public array $coverage = [];

    /** @var array<string,int> posting account id => how many mappings cover it (more than one is an overlap, even on the same line) */
    public array $coverCount = [];

    /** @var array<string,list<string>> line id => posting account ids it covers */
    public array $lineAccounts = [];

    /**
     * @param  Collection<int,ReportLine>  $lines
     * @param  iterable<array{line_id:string,account_id:string,include_descendants:bool}>  $mappings
     */
    public function __construct(Collection $lines, iterable $mappings, public readonly AccountTree $accounts)
    {
        foreach ($lines->sortBy([['display_order', 'asc'], ['code', 'asc']]) as $line) {
            $this->byId[$line->id] = $line;
            if (isset($this->byCode[$line->code])) {
                $this->duplicateCodes[] = $line->code;
            } else {
                $this->byCode[$line->code] = $line;
            }
            $this->children[$line->parent_id ?? ''][] = $line;
        }

        foreach ($mappings as $mapping) {
            $this->mappings[] = $mapping;
            $account = $this->accounts->accounts[$mapping['account_id']] ?? null;
            if ($account === null) {
                continue; // an account outside this tenant's chart covers nothing; the validator reports it
            }
            $covered = ($account->is_postable || $mapping['include_descendants']) ? $this->accounts->postingUnder($account->id) : [];
            foreach ($covered as $accountId) {
                $this->coverCount[$accountId] = ($this->coverCount[$accountId] ?? 0) + 1;
                $this->coverage[$accountId] = array_values(array_unique([...($this->coverage[$accountId] ?? []), $mapping['line_id']]));
                $this->lineAccounts[$mapping['line_id']] = array_values(array_unique([...($this->lineAccounts[$mapping['line_id']] ?? []), $accountId]));
            }
        }
    }

    /** The graph of a stored definition: its lines and mappings read from the database. */
    public static function forDefinition(ReportDefinition $definition, AccountTree $accounts): self
    {
        $lines = ReportLine::query()->where('report_definition_id', $definition->id)->get();
        $mappings = ReportLineMapping::query()->where('report_definition_id', $definition->id)->get()
            ->map(fn (ReportLineMapping $m) => ['line_id' => $m->report_line_id, 'account_id' => $m->account_id, 'include_descendants' => $m->include_descendants]);

        return new self($lines, $mappings, $accounts);
    }

    /** @return list<ReportLine> */
    public function childrenOf(?string $lineId): array
    {
        return $this->children[$lineId ?? ''] ?? [];
    }

    /** @return list<ReportLine> */
    public function roots(): array
    {
        return $this->children[''] ?? [];
    }

    /**
     * The lines whose amounts a SUBTOTAL adds up: its amount-bearing children and, through HEADER children, theirs; a nested subtotal counts as
     * one amount, not as its parts.
     *
     * @return list<ReportLine>
     */
    public function contributors(ReportLine $subtotal, int $depth = 0): array
    {
        $out = [];
        if ($depth > 50) {
            return $out;
        }
        foreach ($this->childrenOf($subtotal->id) as $child) {
            if ($child->line_type === ReportLine::HEADER) {
                array_push($out, ...$this->contributors($child, $depth + 1));
            } else {
                $out[] = $child;
            }
        }

        return $out;
    }

    /** The line that covers a posting account (the first one when the definition overlaps), or null when the account is unmapped. */
    public function lineOf(string $accountId): ?string
    {
        return ($this->coverage[$accountId] ?? [])[0] ?? null;
    }

    public function parentOf(ReportLine $line): ?ReportLine
    {
        return $line->parent_id !== null ? ($this->byId[$line->parent_id] ?? null) : null;
    }
}
