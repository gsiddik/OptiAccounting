<?php

namespace App\Http\Controllers\Api\App\CashBank;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\CashBank\Models\CashTransaction;
use App\Domain\CashBank\Services\CashTransactionService;
use App\Domain\Payables\Models\ApInvoice;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cash and bank payments and receipts. One controller serves two route families (`cash-payments`, `cash-receipts`); the kind comes from the
 * route, never from the body, and a transaction of the other kind is a 404. Thin: validation here, every rule in CashTransactionService.
 */
class CashTransactionController extends AppController
{
    public function __construct(TenantContext $context, private readonly CashTransactionService $transactions, private readonly DocumentScope $scope)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in([ApInvoice::DRAFT, ApInvoice::POSTED, ApInvoice::CANCELLED, ApInvoice::REVERSED])],
            'cash_bank_account_id' => ['nullable', 'uuid'], 'counter_account_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'],
            'transaction_from' => ['nullable', 'date_format:Y-m-d'], 'transaction_to' => ['nullable', 'date_format:Y-m-d'],
            'posting_from' => ['nullable', 'date_format:Y-m-d'], 'posting_to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'], 'mine' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $filter['mine'] = $request->boolean('mine');

        return response()->json($this->transactions->query($this->kind($request), $filter)->paginate($filter['per_page'] ?? 25));
    }

    public function show(Request $request, CashTransaction $cashTransaction): JsonResponse
    {
        return response()->json($this->present($this->visible($request, $cashTransaction), $request));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->present($this->transactions->create($this->kind($request), $this->payload($request, true), $request->user()), $request), 201);
    }

    public function update(Request $request, CashTransaction $cashTransaction): JsonResponse
    {
        $this->visible($request, $cashTransaction);

        return response()->json($this->present($this->transactions->update($cashTransaction, $this->payload($request, false), $request->user()), $request));
    }

    public function cancel(Request $request, CashTransaction $cashTransaction): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->transactions->cancel($this->visible($request, $cashTransaction), $request->user(), $data['reason']), $request));
    }

    public function post(Request $request, CashTransaction $cashTransaction): JsonResponse
    {
        return response()->json($this->present($this->transactions->post($this->visible($request, $cashTransaction), $request->user()), $request));
    }

    public function reverse(Request $request, CashTransaction $cashTransaction): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'posting_date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->present($this->transactions->reverse($this->visible($request, $cashTransaction), $request->user(), $data['reason'], $data['posting_date'] ?? null), $request));
    }

    private function kind(Request $request): string
    {
        return $request->route()->defaults['kind'] ?? abort(404);
    }

    /** Out of the user's data scope, or of the other kind, a transaction does not exist. */
    private function visible(Request $request, CashTransaction $transaction): CashTransaction
    {
        abort_unless($transaction->kind === $this->kind($request), 404);
        $this->scope->authorize($transaction);

        return $transaction;
    }

    private function present(CashTransaction $transaction, Request $request): array
    {
        $transaction = $this->transactions->load($transaction);

        return $transaction->toArray() + ['sod' => $this->transactions->sod($transaction, $request->user()->id)];
    }

    private function payload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'cash_bank_account_id' => [$req, 'uuid'], 'counter_account_id' => [$req, 'uuid'], 'amount' => [$req],
            'transaction_date' => [$req, 'date_format:Y-m-d'], 'posting_date' => ['nullable', 'date_format:Y-m-d'], 'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'purpose' => [$req, 'string', 'max:150'], 'description' => [$req, 'string', 'max:500'], 'reference' => ['nullable', 'string', 'max:100'], 'counterparty_name' => ['nullable', 'string', 'max:150'],
            'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'],
        ]);
    }
}
