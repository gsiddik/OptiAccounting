<?php

namespace App\Http\Controllers\Api\App\Payables;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Support\ListFilters;
use App\Domain\Payables\Models\ApInvoice;
use App\Domain\Payables\Services\ApInvoiceService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Vendor invoices. Thin: validation here, every rule in ApInvoiceService. An invoice outside the user's data scope is a 404. */
class ApInvoiceController extends AppController
{
    public function __construct(TenantContext $context, private readonly ApInvoiceService $invoices, private readonly DocumentScope $scope)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(ListFilters::invoices());
        $filter['mine'] = $request->boolean('mine');
        $filter['open'] = $request->boolean('open');
        $filter['overdue'] = $request->boolean('overdue');

        return response()->json($this->invoices->query($filter)->paginate($filter['per_page'] ?? 25));
    }

    public function show(Request $request, ApInvoice $invoice): JsonResponse
    {
        return response()->json($this->present($this->visible($invoice), $request));
    }

    public function checkDuplicate(Request $request): JsonResponse
    {
        $data = $request->validate(['vendor_id' => ['required', 'uuid'], 'vendor_invoice_number' => ['required', 'string', 'max:100'], 'except_id' => ['nullable', 'uuid']]);
        $duplicate = $this->invoices->findDuplicate($data['vendor_id'], $data['vendor_invoice_number'], $data['except_id'] ?? null);

        return response()->json(['duplicate' => $duplicate !== null, 'status' => $duplicate?->status, 'document_number' => $duplicate?->document_number]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->present($this->invoices->create($this->payload($request, true), $request->user()), $request), 201);
    }

    public function update(Request $request, ApInvoice $invoice): JsonResponse
    {
        $this->visible($invoice);

        return response()->json($this->present($this->invoices->update($invoice, $this->payload($request, false), $request->user()), $request));
    }

    public function submit(Request $request, ApInvoice $invoice): JsonResponse
    {
        return response()->json($this->present($this->invoices->submit($this->visible($invoice), $request->user()), $request));
    }

    public function approve(Request $request, ApInvoice $invoice): JsonResponse
    {
        return response()->json($this->present($this->invoices->approve($this->visible($invoice), $request->user()), $request));
    }

    public function reject(Request $request, ApInvoice $invoice): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->invoices->reject($this->visible($invoice), $request->user(), $data['reason']), $request));
    }

    public function reopen(Request $request, ApInvoice $invoice): JsonResponse
    {
        return response()->json($this->present($this->invoices->reopen($this->visible($invoice), $request->user()), $request));
    }

    public function cancel(Request $request, ApInvoice $invoice): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->invoices->cancel($this->visible($invoice), $request->user(), $data['reason']), $request));
    }

    public function post(Request $request, ApInvoice $invoice): JsonResponse
    {
        return response()->json($this->present($this->invoices->post($this->visible($invoice), $request->user()), $request));
    }

    public function reverse(Request $request, ApInvoice $invoice): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'posting_date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->present($this->invoices->reverse($this->visible($invoice), $request->user(), $data['reason'], $data['posting_date'] ?? null), $request));
    }

    private function visible(ApInvoice $invoice): ApInvoice
    {
        $this->scope->authorize($invoice);

        return $invoice;
    }

    private function present(ApInvoice $invoice, Request $request): array
    {
        $invoice = $this->invoices->load($invoice);

        return $invoice->toArray() + ['sod' => $this->invoices->sod($invoice, $request->user()->id), 'possible_duplicates' => $this->invoices->possibleDuplicates($invoice)];
    }

    private function payload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'vendor_id' => [$req, 'uuid'], 'vendor_invoice_number' => [$req, 'string', 'max:100'],
            'document_date' => [$req, 'date_format:Y-m-d'], 'posting_date' => ['nullable', 'date_format:Y-m-d'], 'due_date' => ['nullable', 'date_format:Y-m-d'],
            'payment_term_id' => ['nullable', 'uuid'], 'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'description' => [$req, 'string', 'max:500'], 'reference' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'],
            'discount_amount' => ['nullable'], 'tax_amount' => ['nullable'], 'other_charges_amount' => ['nullable'],
            'duplicate_override' => ['sometimes', 'boolean'], 'duplicate_override_reason' => ['nullable', 'string', 'max:255'],
            'lines' => [$req, 'array', 'min:1', 'max:300'],
            'lines.*.description' => ['required', 'string', 'max:255'], 'lines.*.quantity' => ['nullable'], 'lines.*.unit_price' => ['nullable'], 'lines.*.amount' => ['nullable'],
            'lines.*.expense_category_id' => ['nullable', 'uuid'], 'lines.*.account_role' => ['nullable', 'string', 'max:40'], 'lines.*.account_id' => ['nullable', 'uuid'],
            'lines.*.cost_center_id' => ['nullable', 'uuid'], 'lines.*.metadata' => ['nullable', 'array', 'max:20'],
        ]);
    }
}
