<?php

namespace App\Http\Controllers\Api\App\Accounting;

use App\Domain\Accounting\Models\Account;
use App\Domain\Accounting\Services\ChartOfAccountsService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChartOfAccountsController extends AppController
{
    public function __construct(TenantContext $context, private readonly ChartOfAccountsService $accounts)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'type' => ['nullable', Rule::in(Account::TYPES)], 'status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])]]);

        return response()->json(['data' => $this->accounts->list($filters['q'] ?? null, $filters['type'] ?? null, $filters['status'] ?? null)]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->accounts->create($this->rules($request, true)), 201);
    }

    public function update(Request $request, Account $account): JsonResponse
    {
        return response()->json($this->accounts->update($account, $this->rules($request, false)));
    }

    public function status(Request $request, Account $account): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])]]);

        return response()->json($this->accounts->setStatus($account, $data['status']));
    }

    public function destroy(Account $account): JsonResponse
    {
        $this->accounts->delete($account);

        return response()->json(null, 204);
    }

    public function templates(): JsonResponse
    {
        return response()->json(['data' => DB::table('coa_templates')->where('status', 'ACTIVE')->orderBy('name')->get(['code', 'name', 'description'])]);
    }

    public function applyTemplate(Request $request): JsonResponse
    {
        $data = $request->validate(['template' => ['required', 'string', 'max:40']]);

        return response()->json($this->accounts->applyTemplate($data['template']), 201);
    }

    private function rules(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$req, 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => [$req, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'parent_id' => ['nullable', 'uuid'],
            'account_type' => [$req, Rule::in(Account::TYPES)],
            'normal_balance' => ['sometimes', Rule::in(['DEBIT', 'CREDIT'])],
            'is_postable' => ['sometimes', 'boolean'],
            'is_control' => ['sometimes', 'boolean'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
        ]);
    }
}
