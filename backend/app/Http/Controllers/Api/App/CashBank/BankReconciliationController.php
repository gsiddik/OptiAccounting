<?php

namespace App\Http\Controllers\Api\App\CashBank;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Support\ListFilters;
use App\Domain\CashBank\Models\BankStatement;
use App\Domain\CashBank\Models\BankStatementItem;
use App\Domain\CashBank\Models\CashBankAccount;
use App\Domain\CashBank\Services\BankStatementService;
use App\Domain\CashBank\Services\CashBankLedgerService;
use App\Domain\CashBank\Services\CashBankReconciliationService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cash and bank history and manual bank reconciliation. Thin: validation here, rules in the services. A statement or account outside the
 * user's data scope is a 404. Nothing here writes to the ledger.
 */
class BankReconciliationController extends AppController
{
    public function __construct(
        TenantContext $context,
        private readonly BankStatementService $statements,
        private readonly CashBankLedgerService $ledger,
        private readonly CashBankReconciliationService $reconciliation,
        private readonly DocumentScope $scope,
    ) {
        parent::__construct($context);
    }

    // ---------------------------------------------------------------------------------------------- history and report

    /** The book side: posted ledger lines of the account's GL account with running balance and match state. */
    public function transactions(Request $request, CashBankAccount $cashBankAccount): JsonResponse
    {
        $this->scope->authorize($cashBankAccount);
        $filter = $request->validate(ListFilters::accountMovements());
        $filter['matched'] = $request->has('matched') ? $request->boolean('matched') : null;

        return response()->json($this->ledger->lines($cashBankAccount, $filter, (int) ($filter['per_page'] ?? 50), (int) ($filter['page'] ?? 1)));
    }

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d'], 'cash_bank_account_id' => ['nullable', 'uuid']]);

        return response()->json($this->reconciliation->report($data['as_of'] ?? now()->toDateString(), $data['cash_bank_account_id'] ?? null));
    }

    // ---------------------------------------------------------------------------------------------- statements

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'cash_bank_account_id' => ['nullable', 'uuid'], 'status' => ['nullable', Rule::in([BankStatement::OPEN, BankStatement::COMPLETED])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        return response()->json($this->statements->query($filter)->paginate($filter['per_page'] ?? 25));
    }

    public function show(BankStatement $statement): JsonResponse
    {
        return response()->json($this->statements->load($this->visible($statement)));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->statements->create($this->statementPayload($request, true) + ['items' => $this->items($request)], $request->user()), 201);
    }

    public function update(Request $request, BankStatement $statement): JsonResponse
    {
        $this->visible($statement);

        return response()->json($this->statements->update($statement, $this->statementPayload($request, false)));
    }

    public function destroy(BankStatement $statement): JsonResponse
    {
        $this->statements->delete($this->visible($statement));

        return response()->json(null, 204);
    }

    public function complete(Request $request, BankStatement $statement): JsonResponse
    {
        return response()->json($this->statements->complete($this->visible($statement), $request->user()));
    }

    // ---------------------------------------------------------------------------------------------- items

    public function addItems(Request $request, BankStatement $statement): JsonResponse
    {
        $this->visible($statement);
        $request->validate(['items' => ['required', 'array', 'min:1', 'max:1000']]);

        return response()->json($this->statements->addItems($statement, $this->items($request)), 201);
    }

    public function updateItem(Request $request, BankStatement $statement, BankStatementItem $item): JsonResponse
    {
        $data = $request->validate([
            'item_date' => ['sometimes', 'date_format:Y-m-d'], 'description' => ['sometimes', 'string', 'max:255'], 'reference' => ['nullable', 'string', 'max:100'], 'amount' => ['sometimes'],
        ]);

        return response()->json($this->statements->updateItem($this->itemOf($statement, $item), $data));
    }

    public function destroyItem(BankStatement $statement, BankStatementItem $item): JsonResponse
    {
        return response()->json($this->statements->deleteItem($this->itemOf($statement, $item)));
    }

    public function match(Request $request, BankStatement $statement, BankStatementItem $item): JsonResponse
    {
        $data = $request->validate(['journal_line_id' => ['required', 'uuid']]);

        return response()->json($this->statements->match($this->itemOf($statement, $item), $data['journal_line_id'], $request->user()));
    }

    public function unmatch(Request $request, BankStatement $statement, BankStatementItem $item): JsonResponse
    {
        return response()->json($this->statements->unmatch($this->itemOf($statement, $item), $request->user()));
    }

    public function exception(Request $request, BankStatement $statement, BankStatementItem $item): JsonResponse
    {
        $data = $request->validate(['notes' => ['required', 'string', 'max:500']]);

        return response()->json($this->statements->markException($this->itemOf($statement, $item), $data['notes'], $request->user()));
    }

    public function candidates(BankStatement $statement, BankStatementItem $item): JsonResponse
    {
        return response()->json(['data' => $this->statements->candidates($this->itemOf($statement, $item))]);
    }

    // ---------------------------------------------------------------------------------------------- helpers

    private function visible(BankStatement $statement): BankStatement
    {
        $this->scope->authorize($statement);

        return $statement;
    }

    /** The item must belong to the statement in the URL, and the statement to the user's scope. */
    private function itemOf(BankStatement $statement, BankStatementItem $item): BankStatementItem
    {
        $this->visible($statement);
        abort_unless($item->bank_statement_id === $statement->id, 404);

        return $item;
    }

    /** @return list<array<string,mixed>> */
    private function items(Request $request): array
    {
        $request->validate([
            'items' => ['sometimes', 'array', 'max:1000'], 'items.*.item_date' => ['required', 'date_format:Y-m-d'], 'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.reference' => ['nullable', 'string', 'max:100'], 'items.*.amount' => ['required'],
        ]);

        return (array) $request->input('items', []);
    }

    private function statementPayload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'cash_bank_account_id' => $creating ? ['required', 'uuid'] : ['prohibited'], 'reference' => [$req, 'string', 'max:100'], 'statement_date' => [$req, 'date_format:Y-m-d'],
            'period_start' => ['nullable', 'date_format:Y-m-d'], 'opening_balance' => ['nullable'], 'closing_balance' => [$req], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
