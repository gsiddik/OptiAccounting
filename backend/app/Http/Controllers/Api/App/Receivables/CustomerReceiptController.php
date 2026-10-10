<?php

namespace App\Http\Controllers\Api\App\Receivables;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Support\ListFilters;
use App\Domain\Accounting\Support\Money;
use App\Domain\Receivables\Models\Customer;
use App\Domain\Receivables\Models\CustomerReceipt;
use App\Domain\Receivables\Services\ArSubledgerService;
use App\Domain\Receivables\Services\CustomerReceiptService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Customer receipts and their allocations. Thin: validation here, every rule in CustomerReceiptService. A receipt outside the user's data scope is a 404. */
class CustomerReceiptController extends AppController
{
    public function __construct(TenantContext $context, private readonly CustomerReceiptService $receipts, private readonly ArSubledgerService $subledger, private readonly DocumentScope $scope)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(ListFilters::receipts());
        $filter['mine'] = $request->boolean('mine');

        return response()->json($this->receipts->query($filter)->paginate($filter['per_page'] ?? 25));
    }

    public function show(Request $request, CustomerReceipt $receipt): JsonResponse
    {
        return response()->json($this->present($this->visible($receipt), $request));
    }

    /** Posted invoices of a customer with what is still outstanding, oldest due date first: the choices a receipt allocates to. */
    public function openInvoices(Customer $customer): JsonResponse
    {
        return response()->json(['data' => $this->subledger->openInvoices($customer->id)->map->only([
            'id', 'document_number', 'customer_reference', 'posting_date', 'due_date', 'total_amount', 'received_amount', 'credited_amount', 'outstanding_amount', 'receipt_status', 'branch',
        ])->values()]);
    }

    /** The backend's own proposal for applying an amount to the open invoices (oldest due first); the client never computes allocations. */
    public function suggest(Request $request, Customer $customer): JsonResponse
    {
        $data = $request->validate(['amount' => ['required'], 'posting_date' => ['nullable', 'date_format:Y-m-d']]);
        $amount = Money::parse($data['amount'], 4, 'amount');

        return response()->json(['data' => $this->receipts->suggest($customer, $amount, $data['posting_date'] ?? null)]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->present($this->receipts->create($this->payload($request, true), $request->user()), $request), 201);
    }

    public function update(Request $request, CustomerReceipt $receipt): JsonResponse
    {
        $this->visible($receipt);

        return response()->json($this->present($this->receipts->update($receipt, $this->payload($request, false), $request->user()), $request));
    }

    public function submit(Request $request, CustomerReceipt $receipt): JsonResponse
    {
        return response()->json($this->present($this->receipts->submit($this->visible($receipt), $request->user()), $request));
    }

    public function approve(Request $request, CustomerReceipt $receipt): JsonResponse
    {
        return response()->json($this->present($this->receipts->approve($this->visible($receipt), $request->user()), $request));
    }

    public function reject(Request $request, CustomerReceipt $receipt): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->receipts->reject($this->visible($receipt), $request->user(), $data['reason']), $request));
    }

    public function reopen(Request $request, CustomerReceipt $receipt): JsonResponse
    {
        return response()->json($this->present($this->receipts->reopen($this->visible($receipt), $request->user()), $request));
    }

    public function cancel(Request $request, CustomerReceipt $receipt): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->receipts->cancel($this->visible($receipt), $request->user(), $data['reason']), $request));
    }

    public function post(Request $request, CustomerReceipt $receipt): JsonResponse
    {
        return response()->json($this->present($this->receipts->post($this->visible($receipt), $request->user()), $request));
    }

    public function reverse(Request $request, CustomerReceipt $receipt): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'posting_date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->present($this->receipts->reverse($this->visible($receipt), $request->user(), $data['reason'], $data['posting_date'] ?? null), $request));
    }

    private function visible(CustomerReceipt $receipt): CustomerReceipt
    {
        $this->scope->authorize($receipt);

        return $receipt;
    }

    private function present(CustomerReceipt $receipt, Request $request): array
    {
        $receipt = $this->receipts->load($receipt);

        return $receipt->toArray() + ['sod' => $this->receipts->sod($receipt, $request->user()->id)];
    }

    private function payload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'customer_id' => [$req, 'uuid'], 'cash_bank_account_id' => [$req, 'uuid'], 'amount' => [$req],
            'receipt_date' => [$req, 'date_format:Y-m-d'], 'posting_date' => ['nullable', 'date_format:Y-m-d'], 'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'receipt_method' => ['nullable', Rule::in(CustomerReceipt::METHODS)], 'reference' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'],
            'auto_allocate' => ['sometimes', 'boolean'], 'allocations' => ['sometimes', 'array', 'max:200'],
            'allocations.*.ar_invoice_id' => ['required', 'uuid'], 'allocations.*.amount' => ['required'],
        ]);
    }
}
