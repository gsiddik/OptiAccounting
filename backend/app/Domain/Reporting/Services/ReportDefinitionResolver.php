<?php

namespace App\Domain\Reporting\Services;

use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Shared\DomainException;

/**
 * Which report definition a statement uses. An explicit `definition_id` wins (any status, so a draft can be previewed; another tenant's id or
 * another statement's id is simply "not found"). Otherwise the published version that was effective on the date applies: a balance sheet uses the
 * version of its as-of date, a period report the version of its end date, so a historical statement is produced with the layout that was in force.
 * A tenant with no applicable definition gets a clear REPORT_DEFINITION_NOT_FOUND instead of an empty statement.
 */
class ReportDefinitionResolver
{
    public function resolve(string $statementType, string $date, ?string $definitionId = null): ReportDefinition
    {
        if ($definitionId !== null) {
            $definition = ReportDefinition::query()->where('statement_type', $statementType)->find($definitionId);

            return $definition ?? throw new DomainException('The report definition does not exist.', 'REPORT_DEFINITION_NOT_FOUND', 404, ['statement_type' => $statementType]);
        }

        $definition = ReportDefinition::query()->where('statement_type', $statementType)->whereIn('status', [ReportDefinition::ACTIVE, ReportDefinition::ARCHIVED])
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))
            ->first();

        return $definition ?? throw new DomainException(
            "No published {$statementType} report definition applies on {$date}. Create one (report-definitions/defaults builds a draft from the chart of accounts) and publish it.",
            'REPORT_DEFINITION_NOT_FOUND', 422, ['statement_type' => $statementType, 'date' => $date],
        );
    }

    /**
     * The definition for a period [from, to] (the version effective on `to`) and the warnings that go with it: a different version was in force
     * at the start of the period, or the definition is still a draft.
     *
     * @return array{definition:ReportDefinition,warnings:list<array<string,mixed>>}
     */
    public function resolveForRange(string $statementType, string $from, string $to, ?string $definitionId = null): array
    {
        $definition = $this->resolve($statementType, $to, $definitionId);
        $warnings = $this->draftWarning($definition);

        if ($definitionId === null && $from !== $to) {
            $atStart = ReportDefinition::query()->where('statement_type', $statementType)->whereIn('status', [ReportDefinition::ACTIVE, ReportDefinition::ARCHIVED])
                ->where(fn ($q) => $q->whereNull('effective_from')->orWhereDate('effective_from', '<=', $from))
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))->value('id');
            if ($atStart !== null && $atStart !== $definition->id) {
                $warnings[] = ['code' => 'DEFINITION_CHANGED_IN_RANGE', 'message' => 'A different version of the report definition was in force at the start of the period; the version effective on the end date is used for the whole period.', 'details' => ['version_at_start' => $atStart]];
            }
        }

        return ['definition' => $definition, 'warnings' => $warnings];
    }

    /** @return list<array<string,mixed>> */
    public function draftWarning(ReportDefinition $definition): array
    {
        return $definition->isDraft()
            ? [['code' => 'DEFINITION_IS_DRAFT', 'message' => 'This statement uses a draft report definition that has not been published.', 'details' => ['definition_id' => $definition->id]]]
            : [];
    }

    /** The statement header block: which version produced the figures. @return array<string,mixed> */
    public function describe(ReportDefinition $definition): array
    {
        return [
            'id' => $definition->id, 'code' => $definition->code, 'name' => $definition->name, 'version' => $definition->version, 'status' => $definition->status,
            'effective_from' => $definition->effective_from?->toDateString(), 'effective_to' => $definition->effective_to?->toDateString(),
        ];
    }
}
