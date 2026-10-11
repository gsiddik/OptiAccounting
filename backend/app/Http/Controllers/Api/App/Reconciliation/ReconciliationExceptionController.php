<?php

namespace App\Http\Controllers\Api\App\Reconciliation;

use App\Domain\Reconciliation\Models\ReconciliationException;
use App\Domain\Reconciliation\Services\ReconciliationEngine;
use App\Domain\Reconciliation\Services\ReconciliationExceptionService;
use App\Domain\Reconciliation\Services\ReconciliationResult;
use App\Domain\Shared\DomainException;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The work items of reconciliation differences: list, assign an owner, explain, resolve. Thin over ReconciliationExceptionService (see there for the rules). */
class ReconciliationExceptionController extends AppController
{
    public function __construct(TenantContext $context, private readonly ReconciliationExceptionService $exceptions)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in([...ReconciliationException::STATUSES, 'ACTIVE'])],
            'calculated_status' => ['nullable', Rule::in([ReconciliationResult::MATCHED, ReconciliationResult::MISMATCH, ReconciliationResult::INCOMPLETE, ReconciliationResult::NOT_APPLICABLE])],
            'type' => ['nullable', 'string', 'max:20'], 'as_of_from' => ['nullable', 'date_format:Y-m-d'], 'as_of_to' => ['nullable', 'date_format:Y-m-d'],
            'owner_id' => ['nullable', 'uuid'], 'mine' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);
        if (isset($filter['type'])) {
            $filter['type'] = ReconciliationEngine::typeOfSlug($filter['type']) ?? throw new DomainException('Unknown reconciliation type.', 'RECONCILIATION_TYPE_UNKNOWN', 422, ['field' => 'type']);
        }

        return response()->json($this->exceptions->query($filter)->paginate($filter['per_page'] ?? 50)->through(fn (ReconciliationException $e) => $this->exceptions->present($e)));
    }

    public function assign(Request $request, string $exception): JsonResponse
    {
        $this->exceptions->find($exception); // an exception of another tenant is 404 before anything else is said about the request
        $data = $request->validate(['owner_id' => ['required', 'uuid']]);

        return response()->json($this->exceptions->present($this->exceptions->assign($exception, $data['owner_id'])));
    }

    public function explain(Request $request, string $exception): JsonResponse
    {
        $this->exceptions->find($exception); // an exception of another tenant is 404 before anything else is said about the request
        $data = $request->validate(['notes' => ['required', 'string', 'min:3', 'max:1000']]);

        return response()->json($this->exceptions->present($this->exceptions->explain($exception, trim($data['notes']))));
    }

    public function resolve(Request $request, string $exception): JsonResponse
    {
        $this->exceptions->find($exception); // an exception of another tenant is 404 before anything else is said about the request
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']]);

        return response()->json($this->exceptions->present($this->exceptions->resolve($exception, trim($data['note']))));
    }
}
