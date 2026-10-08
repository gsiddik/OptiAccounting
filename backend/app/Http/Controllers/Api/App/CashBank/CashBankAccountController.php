<?php

namespace App\Http\Controllers\Api\App\CashBank;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\CashBank\Models\CashBankAccount;
use App\Domain\CashBank\Services\CashBankAccountService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Cash and bank accounts. Thin: validation here, rules in CashBankAccountService. Out-of-scope accounts are 404; the full bank number is never returned. */
class CashBankAccountController extends AppController
{
    public function __construct(TenantContext $context, private readonly CashBankAccountService $accounts, private readonly DocumentScope $scope)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in([CashBankAccount::ACTIVE, CashBankAccount::INACTIVE])], 'kind' => ['nullable', Rule::in([CashBankAccount::CASH, CashBankAccount::BANK])],
            'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'q' => ['nullable', 'string', 'max:100'],
            'as_of' => ['nullable', 'date_format:Y-m-d'], 'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);
        $page = $this->accounts->query($filter)->paginate($filter['per_page'] ?? 50);
        $balances = $this->accounts->bookBalances($page->getCollection()->pluck('account_id')->all(), $filter['as_of'] ?? null);
        $page->getCollection()->transform(fn (CashBankAccount $a) => $this->withBalance($a, $balances));

        return response()->json($page);
    }

    public function show(Request $request, CashBankAccount $cashBankAccount): JsonResponse
    {
        $account = $this->accounts->load($this->visible($cashBankAccount));
        $asOf = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']])['as_of'] ?? null;

        return response()->json($this->withBalance($account, $this->accounts->bookBalances([$account->account_id], $asOf)) + ['in_use' => $this->accounts->inUse($account)]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->accounts->create($this->payload($request, true)), 201);
    }

    public function update(Request $request, CashBankAccount $cashBankAccount): JsonResponse
    {
        $this->visible($cashBankAccount);

        return response()->json($this->accounts->update($cashBankAccount, $this->payload($request, false)));
    }

    public function status(Request $request, CashBankAccount $cashBankAccount): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([CashBankAccount::ACTIVE, CashBankAccount::INACTIVE])]]);

        return response()->json($this->accounts->setStatus($this->visible($cashBankAccount), $data['status']));
    }

    public function destroy(CashBankAccount $cashBankAccount): JsonResponse
    {
        $this->accounts->delete($this->visible($cashBankAccount));

        return response()->json(null, 204);
    }

    private function visible(CashBankAccount $account): CashBankAccount
    {
        $this->scope->authorize($account);

        return $account;
    }

    /** @param array<string,string> $balances GL account id => balance */
    private function withBalance(CashBankAccount $account, array $balances): array
    {
        return $account->toArray() + ['book_balance' => $balances[$account->account_id] ?? '0.0000'];
    }

    private function payload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'code' => $creating ? ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'] : ['prohibited'],
            'kind' => $creating ? ['required', Rule::in([CashBankAccount::CASH, CashBankAccount::BANK])] : ['prohibited'],
            'name' => [$req, 'string', 'max:150'], 'account_id' => [$req, 'uuid'], 'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'bank_name' => ['nullable', 'string', 'max:100'], 'account_holder' => ['nullable', 'string', 'max:150'], 'account_number' => ['nullable', 'string', 'max:40'],
            'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
