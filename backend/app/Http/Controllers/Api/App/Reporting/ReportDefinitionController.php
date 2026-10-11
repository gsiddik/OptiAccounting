<?php

namespace App\Http\Controllers\Api\App\Reporting;

use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Models\ReportLine;
use App\Domain\Reporting\Services\ReportDefaultsService;
use App\Domain\Reporting\Services\ReportDefinitionService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Report definitions: layout versions, lines and the account mapping of the financial statements. Thin: input shape here, every rule in the services. */
class ReportDefinitionController extends AppController
{
    public function __construct(TenantContext $context, private readonly ReportDefinitionService $definitions, private readonly ReportDefaultsService $defaults)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(['statement_type' => ['nullable', Rule::in(ReportDefinition::TYPES)], 'status' => ['nullable', Rule::in([ReportDefinition::DRAFT, ReportDefinition::ACTIVE, ReportDefinition::ARCHIVED])]]);

        return response()->json(['data' => $this->definitions->list($filter['statement_type'] ?? null, $filter['status'] ?? null)->map(fn ($d) => $this->definitions->header($d))->all()]);
    }

    public function show(ReportDefinition $definition): JsonResponse
    {
        return response()->json($this->definitions->detail($definition));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'statement_type' => ['required', Rule::in(ReportDefinition::TYPES)], 'name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:500'],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_.-]+$/'],
        ]);

        return response()->json($this->definitions->detail($this->definitions->create($data)), 201);
    }

    public function update(Request $request, ReportDefinition $definition): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:500']]);

        return response()->json($this->definitions->detail($this->definitions->update($definition, $data)));
    }

    public function destroy(ReportDefinition $definition): JsonResponse
    {
        $this->definitions->delete($definition);

        return response()->json(null, 204);
    }

    public function replaceLines(Request $request, ReportDefinition $definition): JsonResponse
    {
        $data = $request->validate(['lines' => ['present', 'array', 'max:1000']] + $this->lineRules('lines.*.', true));

        return response()->json($this->definitions->detail($this->definitions->replaceLines($definition, $data['lines']), true));
    }

    public function storeLine(Request $request, ReportDefinition $definition): JsonResponse
    {
        $data = $request->validate($this->lineRules('', true));

        return response()->json($this->definitions->detail($this->definitions->addLine($definition, $data), true), 201);
    }

    public function updateLine(Request $request, ReportDefinition $definition, ReportLine $line): JsonResponse
    {
        $data = $request->validate($this->lineRules('', false));

        return response()->json($this->definitions->detail($this->definitions->updateLine($definition, $line, $data), true));
    }

    public function destroyLine(ReportDefinition $definition, ReportLine $line): JsonResponse
    {
        return response()->json($this->definitions->detail($this->definitions->deleteLine($definition, $line), true));
    }

    public function replaceMappings(Request $request, ReportDefinition $definition): JsonResponse
    {
        $data = $request->validate([
            'mappings' => ['present', 'array', 'max:5000'],
            'mappings.*.line_code' => ['nullable', 'required_without:mappings.*.report_line_id', 'string', 'max:40'],
            'mappings.*.report_line_id' => ['nullable', 'required_without:mappings.*.line_code', 'uuid'],
            'mappings.*.account_id' => ['required', 'uuid'], 'mappings.*.include_descendants' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->definitions->detail($this->definitions->replaceMappings($definition, $data['mappings']), true));
    }

    public function unmapped(Request $request, ReportDefinition $definition): JsonResponse
    {
        $filter = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d'], 'only_with_balance' => ['nullable', 'boolean']]);

        return response()->json(['data' => $this->definitions->unmapped($definition, $filter['as_of'] ?? null, $request->boolean('only_with_balance', true))]);
    }

    public function validateDefinition(ReportDefinition $definition): JsonResponse
    {
        return response()->json($this->definitions->validate($definition));
    }

    public function newVersion(ReportDefinition $definition): JsonResponse
    {
        return response()->json($this->definitions->detail($this->definitions->newVersion($definition), true), 201);
    }

    public function publish(Request $request, ReportDefinition $definition): JsonResponse
    {
        $data = $request->validate(['effective_from' => ['nullable', 'date_format:Y-m-d']]);
        $result = $this->definitions->publish($definition, $data['effective_from'] ?? null);

        return response()->json($this->definitions->detail($result['definition']) + ['warnings' => $result['warnings']]);
    }

    public function archive(Request $request, ReportDefinition $definition): JsonResponse
    {
        $data = $request->validate(['effective_to' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->definitions->detail($this->definitions->archive($definition, $data['effective_to'] ?? null)));
    }

    public function applyDefaults(Request $request): JsonResponse
    {
        $data = $request->validate(['statement_types' => ['nullable', 'array'], 'statement_types.*' => [Rule::in(ReportDefinition::TYPES)]]);
        $result = $this->defaults->apply($data['statement_types'] ?? null);

        return response()->json($result, $result['created'] === [] ? 200 : 201);
    }

    /** @return array<string,mixed> */
    private function lineRules(string $prefix, bool $required): array
    {
        $presence = $required ? ['required'] : ['sometimes'];

        return [
            "{$prefix}code" => [...$required ? ['required'] : ['prohibited'], 'string', 'max:40', 'regex:/^[A-Za-z0-9_.-]+$/'],
            "{$prefix}label" => [...$presence, 'string', 'max:200'],
            "{$prefix}line_type" => [...$presence, Rule::in(ReportLine::TYPES)],
            "{$prefix}parent_code" => ['nullable', 'string', 'max:40'],
            "{$prefix}display_order" => ['nullable', 'integer', 'between:0,1000000'],
            "{$prefix}balance_side" => ['nullable', Rule::in(['DEBIT', 'CREDIT'])],
            "{$prefix}cash_flow_category" => ['nullable', Rule::in(ReportLine::CATEGORIES)],
            "{$prefix}system_key" => ['nullable', Rule::in(array_keys(ReportLine::SYSTEM_KEYS))],
            "{$prefix}operands" => ['nullable', 'array', 'max:100'],
            "{$prefix}operands.*.line" => ['required', 'string', 'max:40'],
            "{$prefix}operands.*.sign" => ['required', Rule::in(['+', '-'])],
            "{$prefix}show_zero" => ['nullable', 'boolean'],
            "{$prefix}emphasis" => ['nullable', 'boolean'],
            "{$prefix}notes" => ['nullable', 'string', 'max:500'],
        ];
    }
}
