<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Accounting\Models\Account;
use App\Domain\Audit\Services\AuditService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Models\ReportLine;
use App\Domain\Reporting\Models\ReportLineMapping;
use App\Domain\Reporting\Support\AccountTree;
use App\Domain\Reporting\Support\DefinitionGraph;
use App\Domain\Shared\DomainException;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Authoring of report definitions: create a draft, edit its lines and account mapping, validate, publish, derive a new version, archive, delete.
 *
 * Only a DRAFT changes (the database refuses anything else, trigger-enforced; the service says so first with REPORT_DEFINITION_NOT_DRAFT). A revision
 * of a published definition is a new version copied from it. Publishing runs the validator, sets the effective window and archives the previous
 * version the day before, in one transaction under row locks (the tenant row serializes authors, the definition rows are locked), so exactly one
 * version applies on any date. Per statement type a tenant has at most one draft at a time.
 *
 * Tenant safety: every lookup goes through the tenant scope, so an id of another tenant is "not found" exactly like an unknown one; mapped accounts
 * are resolved through the tenant's own chart and a mapping to an unknown account is 404 ACCOUNT_NOT_FOUND without saying whether it exists elsewhere.
 * Every change is audited with a before / after summary.
 */
class ReportDefinitionService
{
    /** Default line-code family per statement type for the first definition of a type. */
    private const DEFAULT_CODES = [ReportDefinition::BALANCE_SHEET => 'BALANCE_SHEET', ReportDefinition::PROFIT_LOSS => 'PROFIT_LOSS', ReportDefinition::CASH_FLOW => 'CASH_FLOW'];

    public function __construct(
        private readonly ReportDefinitionValidator $validator,
        private readonly ReportQuery $query,
        private readonly AuditService $audit,
        private readonly TenantContext $context,
    ) {}

    // ------------------------------------------------------------------------------------------------ reading

    /** @return Collection<int,ReportDefinition> */
    public function list(?string $statementType = null, ?string $status = null): Collection
    {
        return ReportDefinition::query()->withCount(['lines', 'mappings'])
            ->when($statementType, fn ($q) => $q->where('statement_type', $statementType))->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('statement_type')->orderByDesc('version')->get();
    }

    /**
     * One definition with its lines as a flat tree (parent first, `depth`, `parent_code`) and the accounts mapped to each line.
     *
     * @return array<string,mixed>
     */
    public function detail(ReportDefinition $definition, bool $withValidation = false): array
    {
        $definition = ReportDefinition::query()->findOrFail($definition->id);
        $tree = new AccountTree($this->query->accounts());
        $graph = DefinitionGraph::forDefinition($definition, $tree);

        $mapped = [];
        foreach ($graph->mappings as $m) {
            $account = $tree->accounts[$m['account_id']] ?? null;
            if ($account !== null) {
                $mapped[$m['line_id']][] = ['account_id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'account_type' => $account->account_type,
                    'is_postable' => $account->is_postable, 'status' => $account->status, 'include_descendants' => $m['include_descendants']];
            }
        }
        $lines = [];
        $walk = function (array $siblings, int $depth) use (&$walk, &$lines, $graph, $mapped) {
            foreach ($siblings as $line) {
                $lines[] = [
                    'id' => $line->id, 'code' => $line->code, 'label' => $line->label, 'line_type' => $line->line_type, 'parent_code' => $graph->parentOf($line)?->code, 'depth' => $depth,
                    'display_order' => $line->display_order, 'balance_side' => $line->balance_side, 'cash_flow_category' => $line->cash_flow_category, 'system_key' => $line->system_key,
                    'operands' => $line->operands, 'show_zero' => $line->show_zero, 'emphasis' => $line->emphasis, 'notes' => $line->notes, 'mappings' => $mapped[$line->id] ?? [],
                ];
                $walk($graph->childrenOf($line->id), $depth + 1);
            }
        };
        $walk($graph->roots(), 0);

        $out = $this->header($definition) + ['lines' => $lines];
        if ($withValidation) {
            $out['validation'] = $this->validator->validate($definition, false);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function header(ReportDefinition $d): array
    {
        return [
            'id' => $d->id, 'statement_type' => $d->statement_type, 'code' => $d->code, 'name' => $d->name, 'description' => $d->description, 'version' => $d->version,
            'status' => $d->status, 'based_on_id' => $d->based_on_id, 'effective_from' => $d->effective_from?->toDateString(), 'effective_to' => $d->effective_to?->toDateString(),
            'published_by' => $d->published_by, 'published_at' => $d->published_at?->toIso8601String(), 'archived_at' => $d->archived_at?->toIso8601String(),
            'lines_count' => $d->lines_count ?? null, 'mappings_count' => $d->mappings_count ?? null,
            'created_at' => $d->created_at?->toIso8601String(), 'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }

    /** @return array{valid:bool,errors:list<array<string,mixed>>,warnings:list<array<string,mixed>>} */
    public function validate(ReportDefinition $definition): array
    {
        return $this->validator->validate(ReportDefinition::query()->findOrFail($definition->id));
    }

    /** @return list<array<string,mixed>> */
    public function unmapped(ReportDefinition $definition, ?string $asOf, bool $onlyWithBalance): array
    {
        $definition = ReportDefinition::query()->findOrFail($definition->id);
        $graph = DefinitionGraph::forDefinition($definition, new AccountTree($this->query->accounts()));

        return $this->validator->unmappedAccounts($definition->statement_type, $graph, $asOf ?? $this->today(), $onlyWithBalance);
    }

    // ------------------------------------------------------------------------------------------------ definitions

    /** @param array{statement_type:string,name:string,description?:?string,code?:?string} $data */
    public function create(array $data): ReportDefinition
    {
        return $this->guarded(fn () => DB::transaction(function () use ($data) {
            $this->lockAuthors();
            $type = $data['statement_type'];
            $this->assertNoDraft($type);
            $latest = ReportDefinition::query()->where('statement_type', $type)->orderByDesc('version')->first();
            $code = $latest?->code ?? ($data['code'] ?? self::DEFAULT_CODES[$type]);
            if ($latest !== null && isset($data['code']) && $data['code'] !== $latest->code) {
                throw new DomainException("This tenant's {$type} definitions use the code {$latest->code}.", 'REPORT_DEFINITION_CODE_MISMATCH', 422, ['code' => $latest->code]);
            }

            $definition = new ReportDefinition(['code' => $code, 'statement_type' => $type, 'name' => $data['name'], 'description' => $data['description'] ?? null]);
            $definition->version = (int) ReportDefinition::query()->where('statement_type', $type)->max('version') + 1;
            $definition->status = ReportDefinition::DRAFT;
            $definition->created_by = $this->context->user()?->id;
            $definition->save();
            $this->audit->record('accounting.report_definition.created', 'report_definition', $definition->id, null, $this->summary($definition));

            return $definition;
        }));
    }

    /** @param array{name?:string,description?:?string} $data */
    public function update(ReportDefinition $definition, array $data): ReportDefinition
    {
        return DB::transaction(function () use ($definition, $data) {
            $definition = $this->lockedDraft($definition);
            $before = $definition->only(['name', 'description']);
            $definition->fill(collect($data)->only(['name', 'description'])->all())->save();
            $this->audit->record('accounting.report_definition.updated', 'report_definition', $definition->id, $before, $definition->only(['name', 'description']));

            return $definition;
        });
    }

    /** Only a draft that never took effect can be removed (its lines and mappings go with it). */
    public function delete(ReportDefinition $definition): void
    {
        DB::transaction(function () use ($definition) {
            $definition = $this->lockedDraft($definition);
            $summary = $this->summary($definition);
            $definition->delete(); // the lines and mappings go with it (cascade; a whole draft tree can be removed in one statement)
            $this->audit->record('accounting.report_definition.deleted', 'report_definition', $definition->id, $summary, null);
        });
    }

    /** A new DRAFT copied from a published or archived version: same lines and mapping, `based_on_id` pointing back, next version number. */
    public function newVersion(ReportDefinition $source): ReportDefinition
    {
        return $this->guarded(fn () => DB::transaction(function () use ($source) {
            $this->lockAuthors();
            $source = ReportDefinition::query()->findOrFail($source->id);
            if ($source->isDraft()) {
                throw new DomainException('This version is still a draft; edit it directly.', 'REPORT_DEFINITION_IS_DRAFT', 409);
            }
            $this->assertNoDraft($source->statement_type);

            $copy = new ReportDefinition(['code' => $source->code, 'statement_type' => $source->statement_type, 'name' => $source->name, 'description' => $source->description]);
            $copy->version = (int) ReportDefinition::query()->where('statement_type', $source->statement_type)->max('version') + 1;
            $copy->status = ReportDefinition::DRAFT;
            $copy->based_on_id = $source->id;
            $copy->created_by = $this->context->user()?->id;
            $copy->save();

            $graph = DefinitionGraph::forDefinition($source, new AccountTree($this->query->accounts()));
            $ids = [];
            $copyTree = function (array $siblings, ?string $parentId) use (&$copyTree, &$ids, $copy, $graph) {
                foreach ($siblings as $line) {
                    $new = new ReportLine($line->only(['code', 'label', 'line_type', 'display_order', 'balance_side', 'cash_flow_category', 'system_key', 'operands', 'show_zero', 'emphasis', 'notes']));
                    $new->report_definition_id = $copy->id;
                    $new->parent_id = $parentId;
                    $new->save();
                    $ids[$line->id] = $new->id;
                    $copyTree($graph->childrenOf($line->id), $new->id);
                }
            };
            $copyTree($graph->roots(), null);
            foreach ($graph->mappings as $m) {
                $row = new ReportLineMapping(['account_id' => $m['account_id'], 'include_descendants' => $m['include_descendants']]);
                $row->report_definition_id = $copy->id;
                $row->report_line_id = $ids[$m['line_id']];
                $row->save();
            }
            $this->audit->record('accounting.report_definition.created', 'report_definition', $copy->id, null, $this->summary($copy) + ['based_on' => $source->id, 'based_on_version' => $source->version]);

            return $copy;
        }));
    }

    /**
     * DRAFT -> ACTIVE. The definition must validate. The first version of a type takes effect from `$effectiveFrom` (null = from the beginning); a
     * later version needs a date (default: today) after the previous version started, and the previous ACTIVE version ends the day before, so
     * history keeps the layout it was produced with. Windows never overlap (database constraint too).
     *
     * @return array{definition:ReportDefinition,warnings:list<array<string,mixed>>}
     */
    public function publish(ReportDefinition $definition, ?string $effectiveFrom = null): array
    {
        return $this->guarded(fn () => DB::transaction(function () use ($definition, $effectiveFrom) {
            $this->lockAuthors();
            $definition = $this->lockedDraft($definition);

            $result = $this->validator->validate($definition, false);
            if (! $result['valid']) {
                throw new DomainException('The report definition has configuration errors and cannot be published.', 'REPORT_DEFINITION_INVALID', 422, [
                    'definition_id' => $definition->id, 'errors' => $result['errors'], 'warnings' => $result['warnings'],
                ]);
            }

            $siblings = ReportDefinition::query()->where('statement_type', $definition->statement_type)->where('id', '!=', $definition->id)
                ->whereIn('status', [ReportDefinition::ACTIVE, ReportDefinition::ARCHIVED])->lockForUpdate()->get();
            $previous = $siblings->sortBy(fn ($d) => $d->effective_from?->toDateString() ?? '')->last();

            if ($previous === null) {
                $from = $effectiveFrom;
            } else {
                $from = $effectiveFrom ?? $this->today();
                if ($previous->effective_from !== null && $from <= $previous->effective_from->toDateString()) {
                    throw new DomainException('A new version must take effect after the previous one started.', 'REPORT_EFFECTIVE_FROM_INVALID', 422, [
                        'previous_effective_from' => $previous->effective_from->toDateString(),
                    ]);
                }
                if ($previous->effective_to !== null && $from <= $previous->effective_to->toDateString()) {
                    throw $this->overlap($definition);
                }
            }

            $archived = null;
            if ($previous !== null && $previous->status === ReportDefinition::ACTIVE) {
                $end = CarbonImmutable::parse($from)->subDay()->toDateString();
                $before = $this->summary($previous);
                $previous->effective_to = $end;
                $previous->status = ReportDefinition::ARCHIVED;
                $previous->archived_at = now();
                $previous->save();
                $archived = $previous->id;
                $this->audit->record('accounting.report_definition.archived', 'report_definition', $previous->id, $before, $this->summary($previous) + ['superseded_by' => $definition->id]);
            }

            $before = $this->summary($definition);
            $definition->effective_from = $from;
            $definition->effective_to = null;
            $definition->status = ReportDefinition::ACTIVE;
            $definition->published_by = $this->context->user()?->id;
            $definition->published_at = now();
            $definition->save();
            $this->audit->record('accounting.report_definition.published', 'report_definition', $definition->id, $before, $this->summary($definition) + ['archived_previous' => $archived]);

            return ['definition' => $definition, 'warnings' => $result['warnings']];
        }));
    }

    /** Retire a published version without a successor: it keeps serving the dates up to `$effectiveTo` (default: today, but not before it started). */
    public function archive(ReportDefinition $definition, ?string $effectiveTo = null): ReportDefinition
    {
        return DB::transaction(function () use ($definition, $effectiveTo) {
            $this->lockAuthors();
            $definition = ReportDefinition::query()->lockForUpdate()->findOrFail($definition->id);
            if ($definition->status !== ReportDefinition::ACTIVE) {
                throw new DomainException('Only a published report definition can be archived.', 'REPORT_DEFINITION_NOT_ACTIVE', 409, ['status' => $definition->status]);
            }
            $start = $definition->effective_from?->toDateString();
            $to = $effectiveTo ?? max($this->today(), (string) $start);
            if ($start !== null && $to < $start) {
                throw new DomainException('The end date cannot be before the definition took effect.', 'REPORT_EFFECTIVE_TO_INVALID', 422, ['effective_from' => $start]);
            }

            $before = $this->summary($definition);
            $definition->effective_to = $to;
            $definition->status = ReportDefinition::ARCHIVED;
            $definition->archived_at = now();
            $definition->save();
            $this->audit->record('accounting.report_definition.archived', 'report_definition', $definition->id, $before, $this->summary($definition));

            return $definition;
        });
    }

    // ------------------------------------------------------------------------------------------------ lines

    /**
     * Replace the whole line tree of a draft with the given flat list (each line names its parent by code). Lines are matched by code: a kept
     * line keeps its id and its mapping, a new one is inserted, a missing one is removed together with its mapping.
     *
     * @param  list<array<string,mixed>>  $lines
     */
    public function replaceLines(ReportDefinition $definition, array $lines): ReportDefinition
    {
        return DB::transaction(function () use ($definition, $lines) {
            $definition = $this->lockedDraft($definition);
            $incoming = $this->normalizeAll($lines);
            $existing = ReportLine::query()->where('report_definition_id', $definition->id)->get()->keyBy('code');
            $mappingsBefore = ReportLineMapping::query()->where('report_definition_id', $definition->id)->count();

            $ids = [];
            foreach ($incoming as $code => $_) {
                $ids[$code] = $existing->get($code)?->id ?? (string) Str::uuid7();
            }
            $created = $updated = 0;
            foreach ($this->parentFirst($incoming) as $code) {
                $row = $incoming[$code];
                $parentId = $row['parent_code'] !== null ? $ids[$row['parent_code']] : null;
                if (isset($existing[$code])) {
                    $line = $existing[$code];
                    $line->fill($row['attributes']);
                    $line->parent_id = $parentId;
                    $line->save();
                    $updated++;
                } else {
                    $line = new ReportLine($row['attributes']);
                    $line->id = $ids[$code];
                    $line->report_definition_id = $definition->id;
                    $line->parent_id = $parentId;
                    $line->save();
                    $created++;
                }
            }
            $removed = $existing->keys()->diff(array_keys($incoming))->map(fn ($c) => $existing[$c]->id)->values()->all();
            if ($removed !== []) {
                ReportLine::query()->whereIn('id', $removed)->delete(); // one statement: a removed parent and its removed children go together; their mappings cascade
            }

            $mappingsAfter = ReportLineMapping::query()->where('report_definition_id', $definition->id)->count();
            $this->audit->record('accounting.report_definition.updated', 'report_definition', $definition->id,
                ['lines' => $existing->count(), 'mappings' => $mappingsBefore], ['lines' => count($incoming), 'mappings' => $mappingsAfter, 'created' => $created, 'updated' => $updated, 'removed' => count($removed)]);

            return $definition;
        });
    }

    /** @param array<string,mixed> $data one line, its parent named by `parent_code` */
    public function addLine(ReportDefinition $definition, array $data): ReportDefinition
    {
        return DB::transaction(function () use ($definition, $data) {
            $definition = $this->lockedDraft($definition);
            $lines = ReportLine::query()->where('report_definition_id', $definition->id)->get()->keyBy('code');
            $row = $this->normalize($data, $lines->count());
            if ($lines->has($row['code'])) {
                throw new DomainException("A line with the code {$row['code']} already exists.", 'REPORT_LINE_CODE_DUPLICATE', 422, ['code' => $row['code']]);
            }
            $parent = $this->parentLine($lines, $row['parent_code']);

            $line = new ReportLine($row['attributes']);
            $line->report_definition_id = $definition->id;
            $line->parent_id = $parent?->id;
            $line->save();
            $this->audit->record('accounting.report_definition.updated', 'report_definition', $definition->id, null, ['line_added' => $line->code, 'line_type' => $line->line_type, 'parent' => $parent?->code]);

            return $definition;
        });
    }

    /** @param array<string,mixed> $data fields to change (the code is the line's identity and never changes); `parent_code` moves the line */
    public function updateLine(ReportDefinition $definition, ReportLine $line, array $data): ReportDefinition
    {
        return DB::transaction(function () use ($definition, $line, $data) {
            $definition = $this->lockedDraft($definition);
            $lines = ReportLine::query()->where('report_definition_id', $definition->id)->get()->keyBy('code');
            $line = $lines->first(fn ($l) => $l->id === $line->id) ?? throw new DomainException('The report line does not exist.', 'REPORT_LINE_NOT_FOUND', 404);

            $current = $line->only(['label', 'line_type', 'display_order', 'balance_side', 'cash_flow_category', 'system_key', 'operands', 'show_zero', 'emphasis', 'notes'])
                + ['parent_code' => $lines->first(fn ($l) => $l->id === $line->parent_id)?->code];
            $row = $this->normalize(array_merge($current, collect($data)->except('code')->all(), ['code' => $line->code]), 0);
            $parent = $this->parentLine($lines, $row['parent_code']);
            for ($node = $parent; $node !== null; $node = $lines->first(fn ($l) => $l->id === $node->parent_id)) {
                if ($node->id === $line->id) {
                    throw new DomainException('A line cannot be moved below itself.', 'LINE_PARENT_CYCLE', 422);
                }
            }

            $before = $line->only(['label', 'line_type', 'display_order', 'balance_side', 'cash_flow_category', 'system_key', 'show_zero', 'emphasis']);
            $line->fill($row['attributes']);
            $line->parent_id = $parent?->id;
            $line->save();
            $this->audit->record('accounting.report_definition.updated', 'report_definition', $definition->id, ['line' => $line->code] + $before,
                ['line' => $line->code] + $line->only(['label', 'line_type', 'display_order', 'balance_side', 'cash_flow_category', 'system_key', 'show_zero', 'emphasis']));

            return $definition;
        });
    }

    public function deleteLine(ReportDefinition $definition, ReportLine $line): ReportDefinition
    {
        return DB::transaction(function () use ($definition, $line) {
            $definition = $this->lockedDraft($definition);
            $line = ReportLine::query()->where('report_definition_id', $definition->id)->find($line->id) ?? throw new DomainException('The report line does not exist.', 'REPORT_LINE_NOT_FOUND', 404);
            if (ReportLine::query()->where('parent_id', $line->id)->exists()) {
                throw new DomainException('Remove or move the lines below this one first.', 'REPORT_LINE_HAS_CHILDREN', 409, ['code' => $line->code]);
            }
            $mappings = ReportLineMapping::query()->where('report_line_id', $line->id)->count();
            $line->delete();
            $this->audit->record('accounting.report_definition.updated', 'report_definition', $definition->id, ['line_removed' => $line->code, 'line_type' => $line->line_type, 'mappings' => $mappings], null);

            return $definition;
        });
    }

    // ------------------------------------------------------------------------------------------------ mappings

    /**
     * Replace the account mapping of a draft. Each entry names a line (by code or id of THIS definition) and an account of this tenant. The proposed
     * mapping is validated as a whole first (inactive, wrong-type, cash, duplicate, overlapping, unmappable-line findings refuse the save), so the
     * stored mapping never starts out invalid.
     *
     * @param  list<array{line_code?:?string,report_line_id?:?string,account_id:string,include_descendants?:bool}>  $mappings
     */
    public function replaceMappings(ReportDefinition $definition, array $mappings): ReportDefinition
    {
        return DB::transaction(function () use ($definition, $mappings) {
            $definition = $this->lockedDraft($definition);
            $lines = ReportLine::query()->where('report_definition_id', $definition->id)->get();
            $byCode = $lines->keyBy('code');
            $byId = $lines->keyBy('id');

            $accountIds = array_values(array_unique(array_column($mappings, 'account_id')));
            if (Account::query()->whereIn('id', $accountIds)->count() !== count($accountIds)) {
                throw new DomainException('The account does not exist.', 'ACCOUNT_NOT_FOUND', 404); // unknown and foreign ids look the same
            }

            $rows = [];
            foreach ($mappings as $m) {
                $line = isset($m['report_line_id']) ? $byId[$m['report_line_id']] ?? null : ($byCode[$m['line_code'] ?? ''] ?? null);
                if ($line === null) {
                    throw new DomainException('The report line does not exist.', 'REPORT_LINE_NOT_FOUND', 404);
                }
                $rows[] = ['line_id' => $line->id, 'account_id' => $m['account_id'], 'include_descendants' => (bool) ($m['include_descendants'] ?? false)];
            }

            $tree = new AccountTree($this->query->accounts());
            $result = $this->validator->validateGraph($definition->statement_type, new DefinitionGraph($lines, $rows, $tree));
            $refused = array_values(array_filter($result['errors'], fn ($e) => str_starts_with($e['code'], 'MAPPING_')));
            if ($refused !== []) {
                throw new DomainException('The mapping has errors and was not saved.', 'REPORT_MAPPING_INVALID', 422, ['errors' => $refused, 'warnings' => $result['warnings']]);
            }

            $before = $this->mappingSummary($definition, $tree);
            ReportLineMapping::query()->where('report_definition_id', $definition->id)->delete();
            foreach ($rows as $row) {
                $mapping = new ReportLineMapping(['account_id' => $row['account_id'], 'include_descendants' => $row['include_descendants']]);
                $mapping->report_definition_id = $definition->id;
                $mapping->report_line_id = $row['line_id'];
                $mapping->save();
            }
            $after = $this->mappingSummary($definition, $tree);
            $this->audit->record('accounting.report_mapping.changed', 'report_definition', $definition->id,
                ['mappings' => count($before)], ['mappings' => count($after), 'added' => array_slice(array_values(array_diff($after, $before)), 0, 200), 'removed' => array_slice(array_values(array_diff($before, $after)), 0, 200)]);

            return $definition;
        });
    }

    // ------------------------------------------------------------------------------------------------ internals

    /**
     * Normalize and shape-check a list of lines (the database-level coherence only; findings such as cycles or bad operands stay with the validator).
     *
     * @param  list<array<string,mixed>>  $lines
     * @return array<string,array{code:string,parent_code:?string,attributes:array<string,mixed>}> by code
     */
    private function normalizeAll(array $lines): array
    {
        $out = [];
        foreach (array_values($lines) as $i => $raw) {
            $row = $this->normalize($raw, $i);
            if (isset($out[$row['code']])) {
                throw new DomainException("The line code {$row['code']} is used twice.", 'REPORT_LINE_CODE_DUPLICATE', 422, ['code' => $row['code']]);
            }
            $out[$row['code']] = $row;
        }
        foreach ($out as $code => $row) {
            if ($row['parent_code'] !== null && ! isset($out[$row['parent_code']])) {
                throw new DomainException("The parent {$row['parent_code']} of line {$code} is not in the list.", 'REPORT_LINE_PARENT_UNKNOWN', 422, ['code' => $code, 'parent_code' => $row['parent_code']]);
            }
        }
        $this->parentFirst($out); // throws on a parent cycle

        return $out;
    }

    /** @return array{code:string,parent_code:?string,attributes:array<string,mixed>} */
    private function normalize(array $raw, int $position): array
    {
        $type = (string) ($raw['line_type'] ?? '');
        $code = (string) ($raw['code'] ?? '');
        $fail = fn (string $message) => new DomainException($message, 'REPORT_LINE_INVALID', 422, ['code' => $code ?: null]);
        if (! in_array($type, ReportLine::TYPES, true)) {
            throw $fail('Unknown line type.');
        }
        $mapped = in_array($type, ReportLine::MAPPED_TYPES, true);
        $side = $raw['balance_side'] ?? null;
        if ($mapped && ! in_array($side, ['DEBIT', 'CREDIT'], true)) {
            throw $fail('An ACCOUNT or ACCOUNT_GROUP line needs a balance_side (DEBIT or CREDIT).');
        }
        if (! $mapped && $side !== null) {
            throw $fail('Only an ACCOUNT or ACCOUNT_GROUP line has a balance_side.');
        }
        $key = $raw['system_key'] ?? null;
        if (($type === ReportLine::SYSTEM) !== ($key !== null)) {
            throw $fail($type === ReportLine::SYSTEM ? 'A SYSTEM line needs a system_key.' : 'Only a SYSTEM line has a system_key.');
        }
        if ($key !== null && ! array_key_exists($key, ReportLine::SYSTEM_KEYS)) {
            throw $fail('Unknown system_key.');
        }
        $category = $raw['cash_flow_category'] ?? null;
        if ($category !== null && ! in_array($category, ReportLine::CATEGORIES, true)) {
            throw $fail('Unknown cash_flow_category.');
        }
        $operands = $raw['operands'] ?? null;
        if (($type === ReportLine::CALCULATED_TOTAL) !== ($operands !== null)) {
            throw $fail($type === ReportLine::CALCULATED_TOTAL ? 'A CALCULATED_TOTAL needs its operands.' : 'Only a CALCULATED_TOTAL has operands.');
        }
        if ($operands !== null) {
            $operands = array_values(array_map(fn ($o) => is_array($o) ? ['line' => (string) ($o['line'] ?? ''), 'sign' => (string) ($o['sign'] ?? '')] : $o, (array) $operands));
        }

        return [
            'code' => $code, 'parent_code' => ($raw['parent_code'] ?? null) ?: null,
            'attributes' => [
                'label' => (string) ($raw['label'] ?? ''), 'line_type' => $type, 'display_order' => (int) ($raw['display_order'] ?? ($position + 1) * 10),
                'balance_side' => $side, 'cash_flow_category' => $category, 'system_key' => $key, 'operands' => $operands,
                'show_zero' => (bool) ($raw['show_zero'] ?? false), 'emphasis' => (bool) ($raw['emphasis'] ?? false), 'notes' => $raw['notes'] ?? null,
            ] + ['code' => $code],
        ];
    }

    /**
     * Codes in an order where every parent comes before its children.
     *
     * @param  array<string,array{parent_code:?string}>  $rows
     * @return list<string>
     */
    private function parentFirst(array $rows): array
    {
        $order = [];
        $state = [];
        $visit = function (string $code) use (&$visit, &$order, &$state, $rows) {
            if (($state[$code] ?? 0) === 2) {
                return;
            }
            if (($state[$code] ?? 0) === 1) {
                throw new DomainException("The parent chain of line {$code} loops back to itself.", 'LINE_PARENT_CYCLE', 422, ['code' => $code]);
            }
            $state[$code] = 1;
            if ($rows[$code]['parent_code'] !== null) {
                $visit($rows[$code]['parent_code']);
            }
            $state[$code] = 2;
            $order[] = $code;
        };
        foreach (array_keys($rows) as $code) {
            $visit($code);
        }

        return $order;
    }

    private function parentLine(Collection $lines, ?string $code): ?ReportLine
    {
        if ($code === null) {
            return null;
        }

        return $lines[$code] ?? throw new DomainException("The parent line {$code} does not exist.", 'REPORT_LINE_PARENT_UNKNOWN', 422, ['parent_code' => $code]);
    }

    /** @return list<string> "LINE_CODE <- ACCOUNT_CODE[+]" per stored mapping, for the audit trail */
    private function mappingSummary(ReportDefinition $definition, AccountTree $tree): array
    {
        return ReportLineMapping::query()->where('report_line_mappings.report_definition_id', $definition->id)->join('report_lines as l', 'l.id', '=', 'report_line_mappings.report_line_id')
            ->get(['l.code as line', 'report_line_mappings.account_id', 'report_line_mappings.include_descendants'])
            ->map(fn ($m) => $m->line.' <- '.($tree->accounts[$m->account_id]->code ?? $m->account_id).($m->include_descendants ? '+' : ''))->sort()->values()->all();
    }

    private function lockedDraft(ReportDefinition $definition): ReportDefinition
    {
        $locked = ReportDefinition::query()->lockForUpdate()->findOrFail($definition->id);
        if (! $locked->isDraft()) {
            throw new DomainException('A published report definition cannot change; create a new version.', 'REPORT_DEFINITION_NOT_DRAFT', 409, ['status' => $locked->status]);
        }

        return $locked;
    }

    /** One author at a time per tenant: version numbers and effective windows are computed from the definitions that exist. */
    private function lockAuthors(): void
    {
        DB::table('tenants')->where('id', $this->context->tenantId())->lockForUpdate()->first();
    }

    private function assertNoDraft(string $type): void
    {
        $draft = ReportDefinition::query()->where('statement_type', $type)->where('status', ReportDefinition::DRAFT)->first();
        if ($draft !== null) {
            throw new DomainException("There is already a draft {$type} definition; publish or delete it first.", 'REPORT_DRAFT_EXISTS', 409, ['definition_id' => $draft->id]);
        }
    }

    /** @return array<string,mixed> */
    private function summary(ReportDefinition $d): array
    {
        return ['code' => $d->code, 'statement_type' => $d->statement_type, 'version' => $d->version, 'name' => $d->name, 'status' => $d->status,
            'effective_from' => $d->effective_from?->toDateString(), 'effective_to' => $d->effective_to?->toDateString()];
    }

    private function today(): string
    {
        return Tenant::query()->findOrFail($this->context->tenantId())->businessDate();
    }

    private function overlap(ReportDefinition $definition): DomainException
    {
        return new DomainException('Another version of this statement already covers that date.', 'REPORT_WINDOW_OVERLAP', 409, ['statement_type' => $definition->statement_type]);
    }

    private function guarded(callable $work): mixed
    {
        try {
            return $work();
        } catch (QueryException $e) {
            $state = $e->errorInfo[0] ?? '';
            if ($state === '23P01') { // exclusion_violation: windows overlap
                throw new DomainException('Another version of this statement already covers that date.', 'REPORT_WINDOW_OVERLAP', 409);
            }
            if ($state === '23505') {
                throw new DomainException('A report definition with this version already exists; retry.', 'REPORT_DEFINITION_CONFLICT', 409);
            }
            throw $e;
        }
    }
}
