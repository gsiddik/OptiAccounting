<?php

namespace App\Http\Controllers\Api\App\Expense;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Expense\Models\Expense;
use App\Domain\Expense\Services\ExpenseService;
use App\Domain\Payables\Models\VendorPayment;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Expenses. Thin: validation here, every rule in ExpenseService. An expense outside the user's data scope is a 404. */
class ExpenseController extends AppController
{
    private const STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'POSTED', 'CANCELLED', 'REVERSED'];

    public function __construct(TenantContext $context, private readonly ExpenseService $expenses, private readonly DocumentScope $scope)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)], 'settlement' => ['nullable', Rule::in([Expense::PAYABLE, Expense::DIRECT_PAID])],
            'vendor_id' => ['nullable', 'uuid'], 'expense_category_id' => ['nullable', 'uuid'], 'cash_bank_account_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'],
            'expense_from' => ['nullable', 'date_format:Y-m-d'], 'expense_to' => ['nullable', 'date_format:Y-m-d'],
            'posting_from' => ['nullable', 'date_format:Y-m-d'], 'posting_to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'], 'mine' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $filter['mine'] = $request->boolean('mine');

        return response()->json($this->expenses->query($filter)->paginate($filter['per_page'] ?? 25));
    }

    public function show(Request $request, Expense $expense): JsonResponse
    {
        return response()->json($this->present($this->visible($expense), $request));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->present($this->expenses->create($this->payload($request, true), $request->user()), $request), 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $this->visible($expense);

        return response()->json($this->present($this->expenses->update($expense, $this->payload($request, false), $request->user()), $request));
    }

    public function submit(Request $request, Expense $expense): JsonResponse
    {
        return response()->json($this->present($this->expenses->submit($this->visible($expense), $request->user()), $request));
    }

    public function approve(Request $request, Expense $expense): JsonResponse
    {
        return response()->json($this->present($this->expenses->approve($this->visible($expense), $request->user()), $request));
    }

    public function reject(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->expenses->reject($this->visible($expense), $request->user(), $data['reason']), $request));
    }

    public function reopen(Request $request, Expense $expense): JsonResponse
    {
        return response()->json($this->present($this->expenses->reopen($this->visible($expense), $request->user()), $request));
    }

    public function cancel(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->expenses->cancel($this->visible($expense), $request->user(), $data['reason']), $request));
    }

    public function post(Request $request, Expense $expense): JsonResponse
    {
        return response()->json($this->present($this->expenses->post($this->visible($expense), $request->user()), $request));
    }

    public function reverse(Request $request, Expense $expense): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'posting_date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->present($this->expenses->reverse($this->visible($expense), $request->user(), $data['reason'], $data['posting_date'] ?? null), $request));
    }

    private function visible(Expense $expense): Expense
    {
        $this->scope->authorize($expense);

        return $expense;
    }

    private function present(Expense $expense, Request $request): array
    {
        $expense = $this->expenses->load($expense);

        return $expense->toArray() + ['sod' => $this->expenses->sod($expense, $request->user()->id)];
    }

    private function payload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'settlement' => [$req, Rule::in([Expense::PAYABLE, Expense::DIRECT_PAID])],
            'expense_category_id' => [$req, 'uuid'], 'account_id' => ['nullable', 'uuid'], 'vendor_id' => ['nullable', 'uuid'], 'payee_name' => ['nullable', 'string', 'max:150'],
            'expense_date' => [$req, 'date_format:Y-m-d'], 'posting_date' => ['nullable', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d'],
            'payment_term_id' => ['nullable', 'uuid'], 'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'description' => [$req, 'string', 'max:500'], 'reference' => ['nullable', 'string', 'max:100'],
            'payment_method' => ['nullable', Rule::in(VendorPayment::METHODS)], 'supporting_document' => ['nullable', 'string', 'max:150'],
            'cash_bank_account_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'],
            'net_amount' => [$req], 'tax_amount' => ['nullable'],
        ]);
    }
}
