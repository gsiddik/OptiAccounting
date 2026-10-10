<?php

namespace App\Http\Controllers\Api\App\Budget;

use App\Domain\Budget\Models\Budget;
use App\Domain\Budget\Services\BudgetService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Budgets (the planning container of a fiscal year). Thin: validation here, every rule in BudgetService. */
class BudgetController extends AppController
{
    public function __construct(TenantContext $context, private readonly BudgetService $budgets)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in(['DRAFT', 'ACTIVE', 'CLOSED', 'CANCELLED'])], 'fiscal_year_id' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return response()->json($this->budgets->query($filter)->paginate($filter['per_page'] ?? 25));
    }

    public function show(Budget $budget): JsonResponse
    {
        return response()->json($this->budgets->load($budget));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:500'],
            'fiscal_year_id' => ['required', 'uuid'], 'responsible_user_id' => ['nullable', 'uuid'],
        ]);

        return response()->json($this->budgets->create($data, $request->user()), 201);
    }

    public function update(Request $request, Budget $budget): JsonResponse
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:500'], 'responsible_user_id' => ['nullable', 'uuid']]);

        return response()->json($this->budgets->update($budget, $data));
    }

    public function open(Request $request, Budget $budget): JsonResponse
    {
        return response()->json($this->budgets->open($budget, $request->user()));
    }

    public function close(Request $request, Budget $budget): JsonResponse
    {
        return response()->json($this->budgets->close($budget, $request->user()));
    }

    public function cancel(Request $request, Budget $budget): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->budgets->cancel($budget, $request->user(), $data['reason']));
    }

    public function storeVersion(Request $request, Budget $budget): JsonResponse
    {
        $data = $request->validate(['label' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500'], 'copy_from_version_id' => ['nullable', 'uuid']]);

        return response()->json($this->budgets->createVersion($budget, $data, $request->user()), 201);
    }
}
