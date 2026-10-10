<?php

namespace App\Http\Controllers\Api\App\Budget;

use App\Domain\Budget\Models\BudgetLine;
use App\Domain\Budget\Models\BudgetVersion;
use App\Domain\Budget\Services\BudgetService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Budget versions and their lines. Thin: validation here, every rule in BudgetService. */
class BudgetVersionController extends AppController
{
    public function __construct(TenantContext $context, private readonly BudgetService $budgets)
    {
        parent::__construct($context);
    }

    public function show(Request $request, BudgetVersion $version): JsonResponse
    {
        return response()->json($this->present($version, $request));
    }

    public function update(Request $request, BudgetVersion $version): JsonResponse
    {
        $data = $request->validate(['label' => ['sometimes', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500']]);

        return response()->json($this->present($this->budgets->updateVersion($version, $data), $request));
    }

    public function replaceLines(Request $request, BudgetVersion $version): JsonResponse
    {
        $data = $request->validate(['lines' => ['present', 'array', 'max:'.BudgetService::MAX_LINES], 'lines.*' => ['array']] + $this->rules('lines.*.', true));

        return response()->json($this->present($this->budgets->replaceLines($version, $data['lines'], $request->user()), $request));
    }

    public function addLine(Request $request, BudgetVersion $version): JsonResponse
    {
        $data = $request->validate($this->rules('', true));

        return response()->json($this->present($this->budgets->addLine($version, $data, $request->user()), $request), 201);
    }

    public function updateLine(Request $request, BudgetVersion $version, BudgetLine $line): JsonResponse
    {
        $data = $request->validate($this->rules('', false));

        return response()->json($this->present($this->budgets->updateLine($version, $line, $data, $request->user()), $request));
    }

    public function destroyLine(Request $request, BudgetVersion $version, BudgetLine $line): JsonResponse
    {
        return response()->json($this->present($this->budgets->removeLine($version, $line), $request));
    }

    public function submit(Request $request, BudgetVersion $version): JsonResponse
    {
        return response()->json($this->present($this->budgets->submit($version, $request->user()), $request));
    }

    public function approve(Request $request, BudgetVersion $version): JsonResponse
    {
        return response()->json($this->present($this->budgets->approve($version, $request->user()), $request));
    }

    public function reject(Request $request, BudgetVersion $version): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->budgets->reject($version, $request->user(), $data['reason']), $request));
    }

    public function reopen(Request $request, BudgetVersion $version): JsonResponse
    {
        return response()->json($this->present($this->budgets->reopen($version, $request->user()), $request));
    }

    public function cancel(Request $request, BudgetVersion $version): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->budgets->cancelVersion($version, $request->user(), $data['reason']), $request));
    }

    public function activate(Request $request, BudgetVersion $version): JsonResponse
    {
        $data = $request->validate(['effective_from' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->present($this->budgets->activate($version, $request->user(), $data['effective_from'] ?? null), $request));
    }

    private function present(BudgetVersion $version, Request $request): array
    {
        $version = $this->budgets->loadVersion($version);

        return $version->toArray() + ['sod' => $this->budgets->sod($version, $request->user()->id)];
    }

    /** @return array<string,mixed> */
    private function rules(string $prefix, bool $required): array
    {
        $req = $required ? 'required' : 'sometimes';

        return [
            "{$prefix}account_id" => [$req, 'uuid'], "{$prefix}accounting_period_id" => [$req, 'uuid'], "{$prefix}amount" => [$req],
            "{$prefix}branch_id" => ['nullable', 'uuid'], "{$prefix}business_unit_id" => ['nullable', 'uuid'], "{$prefix}cost_center_id" => ['nullable', 'uuid'],
            "{$prefix}description" => ['nullable', 'string', 'max:255'],
        ];
    }
}
