<?php

namespace App\Http\Controllers\Api\App\Tax;

use App\Domain\Tax\Models\TaxCode;
use App\Domain\Tax\Services\TaxCodeService;
use App\Domain\Tax\Services\TaxDocumentService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tax codes and their effective-dated rates. Thin: validation here, every rule in TaxCodeService. */
class TaxCodeController extends AppController
{
    public function __construct(TenantContext $context, private readonly TaxCodeService $codes, private readonly TaxDocumentService $documents)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in([TaxCode::ACTIVE, TaxCode::INACTIVE])], 'tax_type' => ['nullable', Rule::in(TaxCode::TYPES)], 'direction' => ['nullable', Rule::in(['INPUT', 'OUTPUT'])],
            'q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);

        return response()->json($this->codes->query($filter)->paginate($filter['per_page'] ?? 50));
    }

    public function show(TaxCode $code): JsonResponse
    {
        return response()->json($this->codes->load($code));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->codes->create($this->payload($request, true), $request->user()), 201);
    }

    public function update(Request $request, TaxCode $code): JsonResponse
    {
        return response()->json($this->codes->update($code, $this->payload($request, false), $request->user()));
    }

    public function activate(TaxCode $code): JsonResponse
    {
        return response()->json($this->codes->setStatus($code, TaxCode::ACTIVE));
    }

    public function deactivate(TaxCode $code): JsonResponse
    {
        return response()->json($this->codes->setStatus($code, TaxCode::INACTIVE));
    }

    public function destroy(TaxCode $code): JsonResponse
    {
        $this->codes->delete($code);

        return response()->json(null, 204);
    }

    /** A new rate that takes over from a date (the timeline only moves forward). */
    public function addRate(Request $request, TaxCode $code): JsonResponse
    {
        $data = $request->validate(['rate' => ['required'], 'effective_from' => ['required', 'date_format:Y-m-d']]);

        return response()->json($this->codes->addRate($code, $data, $request->user()), 201);
    }

    /** The calculation a document would make, without saving anything. */
    public function preview(Request $request, TaxCode $code): JsonResponse
    {
        $data = $request->validate(['amount' => ['required'], 'date' => ['required', 'date_format:Y-m-d']]);

        return response()->json($this->documents->preview($code, $data['amount'], $data['date']));
    }

    /** @return array<string,mixed> */
    private function payload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$creating ? 'required' : 'prohibited', 'string', 'max:30'], 'name' => [$req, 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:500'],
            'tax_type' => [$req, Rule::in(TaxCode::TYPES)], 'calculation_method' => ['sometimes', Rule::in([TaxCode::EXCLUSIVE, TaxCode::INCLUSIVE])],
            'treatment' => ['sometimes', Rule::in([TaxCode::STANDARD, TaxCode::ZERO_RATED, TaxCode::EXEMPT])], 'is_recoverable' => ['sometimes', 'boolean'],
            'account_role' => ['nullable', 'string', 'max:60'], 'account_id' => ['nullable', 'uuid'], 'metadata' => ['nullable', 'array'],
            'rate' => [$creating ? 'required' : 'prohibited'], 'effective_from' => [$creating ? 'required' : 'prohibited', 'date_format:Y-m-d'],
        ]);
    }
}
