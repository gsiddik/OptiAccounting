<?php

namespace App\Http\Controllers\Api\App\Payables;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Support\Money;
use App\Domain\Payables\Models\Vendor;
use App\Domain\Payables\Models\VendorPayment;
use App\Domain\Payables\Services\ApSubledgerService;
use App\Domain\Payables\Services\VendorPaymentService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Vendor payments and their allocations. Thin: validation here, every rule in VendorPaymentService. A payment outside the user's data scope is a 404. */
class VendorPaymentController extends AppController
{
    private const STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'POSTED', 'CANCELLED', 'REVERSED'];

    public function __construct(TenantContext $context, private readonly VendorPaymentService $payments, private readonly ApSubledgerService $subledger, private readonly DocumentScope $scope)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in(self::STATUSES)], 'vendor_id' => ['nullable', 'uuid'], 'cash_bank_account_id' => ['nullable', 'uuid'],
            'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'],
            'payment_from' => ['nullable', 'date_format:Y-m-d'], 'payment_to' => ['nullable', 'date_format:Y-m-d'],
            'posting_from' => ['nullable', 'date_format:Y-m-d'], 'posting_to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'], 'mine' => ['nullable', 'boolean'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $filter['mine'] = $request->boolean('mine');

        return response()->json($this->payments->query($filter)->paginate($filter['per_page'] ?? 25));
    }

    public function show(Request $request, VendorPayment $payment): JsonResponse
    {
        return response()->json($this->present($this->visible($payment), $request));
    }

    /** Posted invoices of a vendor with what is still outstanding, oldest due date first: the choices a payment allocates to. */
    public function openInvoices(Vendor $vendor): JsonResponse
    {
        return response()->json(['data' => $this->subledger->openInvoices($vendor->id)->map->only([
            'id', 'document_number', 'vendor_invoice_number', 'posting_date', 'due_date', 'total_amount', 'paid_amount', 'outstanding_amount', 'payment_status', 'branch',
        ])->values()]);
    }

    /** The backend's own proposal for applying an amount to the open invoices (oldest due first); the client never computes allocations. */
    public function suggest(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate(['amount' => ['required'], 'posting_date' => ['nullable', 'date_format:Y-m-d']]);
        $amount = Money::parse($data['amount'], 4, 'amount');

        return response()->json(['data' => $this->payments->suggest($vendor, $amount, $data['posting_date'] ?? null)]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->present($this->payments->create($this->payload($request, true), $request->user()), $request), 201);
    }

    public function update(Request $request, VendorPayment $payment): JsonResponse
    {
        $this->visible($payment);

        return response()->json($this->present($this->payments->update($payment, $this->payload($request, false), $request->user()), $request));
    }

    public function submit(Request $request, VendorPayment $payment): JsonResponse
    {
        return response()->json($this->present($this->payments->submit($this->visible($payment), $request->user()), $request));
    }

    public function approve(Request $request, VendorPayment $payment): JsonResponse
    {
        return response()->json($this->present($this->payments->approve($this->visible($payment), $request->user()), $request));
    }

    public function reject(Request $request, VendorPayment $payment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->payments->reject($this->visible($payment), $request->user(), $data['reason']), $request));
    }

    public function reopen(Request $request, VendorPayment $payment): JsonResponse
    {
        return response()->json($this->present($this->payments->reopen($this->visible($payment), $request->user()), $request));
    }

    public function cancel(Request $request, VendorPayment $payment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->payments->cancel($this->visible($payment), $request->user(), $data['reason']), $request));
    }

    public function post(Request $request, VendorPayment $payment): JsonResponse
    {
        return response()->json($this->present($this->payments->post($this->visible($payment), $request->user()), $request));
    }

    public function reverse(Request $request, VendorPayment $payment): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'posting_date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->present($this->payments->reverse($this->visible($payment), $request->user(), $data['reason'], $data['posting_date'] ?? null), $request));
    }

    private function visible(VendorPayment $payment): VendorPayment
    {
        $this->scope->authorize($payment);

        return $payment;
    }

    private function present(VendorPayment $payment, Request $request): array
    {
        $payment = $this->payments->load($payment);

        return $payment->toArray() + ['sod' => $this->payments->sod($payment, $request->user()->id)];
    }

    private function payload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'vendor_id' => [$req, 'uuid'], 'cash_bank_account_id' => [$req, 'uuid'], 'amount' => [$req],
            'payment_date' => [$req, 'date_format:Y-m-d'], 'posting_date' => ['nullable', 'date_format:Y-m-d'], 'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'payment_method' => ['nullable', Rule::in(VendorPayment::METHODS)], 'reference' => ['nullable', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'],
            'auto_allocate' => ['sometimes', 'boolean'], 'allocations' => ['sometimes', 'array', 'max:200'],
            'allocations.*.ap_invoice_id' => ['required', 'uuid'], 'allocations.*.amount' => ['required'],
        ]);
    }
}
