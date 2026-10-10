<?php

namespace App\Http\Controllers\Api\App\Payables;

use App\Domain\Accounting\Support\ListFilters;
use App\Domain\Payables\Models\PaymentTerm;
use App\Domain\Payables\Models\Vendor;
use App\Domain\Payables\Services\PaymentTermService;
use App\Domain\Payables\Services\VendorService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Vendors and payment terms. Thin: validation here, rules in VendorService / PaymentTermService. */
class VendorController extends AppController
{
    public function __construct(TenantContext $context, private readonly VendorService $vendors, private readonly PaymentTermService $terms)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(ListFilters::vendors());

        return response()->json($this->vendors->query($filter)->paginate($filter['per_page'] ?? 25));
    }

    public function show(Vendor $vendor): JsonResponse
    {
        return response()->json($vendor->load(['paymentTerm:id,code,name', 'payableAccount', 'defaultExpenseAccount']));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->vendors->create($this->payload($request, true)), 201);
    }

    public function update(Request $request, Vendor $vendor): JsonResponse
    {
        return response()->json($this->vendors->update($vendor, $this->payload($request, false)));
    }

    public function status(Request $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in([Vendor::ACTIVE, Vendor::INACTIVE])]]);

        return response()->json($this->vendors->setStatus($vendor, $data['status']));
    }

    public function destroy(Vendor $vendor): JsonResponse
    {
        $this->vendors->delete($vendor);

        return response()->json(null, 204);
    }

    // ---------------------------------------------------------------------------------------------- payment terms

    public function terms(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['nullable', Rule::in(['ACTIVE', 'INACTIVE'])]])['status'] ?? null;

        return response()->json(['data' => PaymentTerm::query()->when($status, fn ($q, $v) => $q->where('status', $v))->orderBy('due_days')->orderBy('code')->get()]);
    }

    public function storeTerm(Request $request): JsonResponse
    {
        return response()->json($this->terms->create($this->termPayload($request, true)), 201);
    }

    public function updateTerm(Request $request, PaymentTerm $term): JsonResponse
    {
        return response()->json($this->terms->update($term, $this->termPayload($request, false)));
    }

    public function termStatus(Request $request, PaymentTerm $term): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])]]);

        return response()->json($this->terms->setStatus($term, $data['status']));
    }

    public function destroyTerm(PaymentTerm $term): JsonResponse
    {
        $this->terms->delete($term);

        return response()->json(null, 204);
    }

    public function applyTermDefaults(): JsonResponse
    {
        return response()->json(['created' => collect($this->terms->applyDefaults())->pluck('code')->all()], 201);
    }

    private function payload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$req, 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\-\/]*$/'],
            'name' => [$req, 'string', 'max:255'], 'legal_name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:150'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:500'], 'tax_id' => ['nullable', 'string', 'max:50'], 'tax_registered' => ['sometimes', 'boolean'],
            'payment_term_id' => ['nullable', 'uuid'], 'default_currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'payable_account_id' => ['nullable', 'uuid'], 'default_expense_account_id' => ['nullable', 'uuid'],
            'external_source' => ['nullable', 'string', 'max:40'], 'external_id' => ['nullable', 'string', 'max:100'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);
    }

    private function termPayload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$req, 'string', 'max:30', 'regex:/^[A-Za-z0-9][A-Za-z0-9._\-]*$/'], 'name' => [$req, 'string', 'max:100'],
            'term_type' => [$req, Rule::in(PaymentTerm::TYPES)], 'due_days' => ['nullable', 'integer', 'between:0,3650'],
            'allows_due_date_override' => ['sometimes', 'boolean'], 'description' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
