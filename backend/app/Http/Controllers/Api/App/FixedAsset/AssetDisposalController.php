<?php

namespace App\Http\Controllers\Api\App\FixedAsset;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\FixedAsset\Models\AssetDisposal;
use App\Domain\FixedAsset\Services\AssetDisposalService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Asset disposals. Thin: validation here, every rule in AssetDisposalService. A disposal outside the user's data scope is a 404. */
class AssetDisposalController extends AppController
{
    public function __construct(TenantContext $context, private readonly AssetDisposalService $disposals, private readonly DocumentScope $scope)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in(['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'POSTED', 'CANCELLED', 'REVERSED'])], 'fixed_asset_id' => ['nullable', 'uuid'],
            'disposal_type' => ['nullable', Rule::in(['SALE', 'SCRAP'])], 'branch_id' => ['nullable', 'uuid'],
            'posting_from' => ['nullable', 'date_format:Y-m-d'], 'posting_to' => ['nullable', 'date_format:Y-m-d'], 'q' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $filter['mine'] = $request->boolean('mine');

        return response()->json($this->disposals->query($filter)->paginate($filter['per_page'] ?? 25));
    }

    public function show(Request $request, AssetDisposal $disposal): JsonResponse
    {
        return response()->json($this->present($this->visible($disposal), $request));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->present($this->disposals->create($this->payload($request, true), $request->user()), $request), 201);
    }

    public function update(Request $request, AssetDisposal $disposal): JsonResponse
    {
        $this->visible($disposal);

        return response()->json($this->present($this->disposals->update($disposal, $this->payload($request, false), $request->user()), $request));
    }

    public function submit(Request $request, AssetDisposal $disposal): JsonResponse
    {
        return response()->json($this->present($this->disposals->submit($this->visible($disposal), $request->user()), $request));
    }

    public function approve(Request $request, AssetDisposal $disposal): JsonResponse
    {
        return response()->json($this->present($this->disposals->approve($this->visible($disposal), $request->user()), $request));
    }

    public function reject(Request $request, AssetDisposal $disposal): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->disposals->reject($this->visible($disposal), $request->user(), $data['reason']), $request));
    }

    public function reopen(Request $request, AssetDisposal $disposal): JsonResponse
    {
        return response()->json($this->present($this->disposals->reopen($this->visible($disposal), $request->user()), $request));
    }

    public function cancel(Request $request, AssetDisposal $disposal): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->disposals->cancel($this->visible($disposal), $request->user(), $data['reason']), $request));
    }

    public function post(Request $request, AssetDisposal $disposal): JsonResponse
    {
        return response()->json($this->present($this->disposals->post($this->visible($disposal), $request->user()), $request));
    }

    public function reverse(Request $request, AssetDisposal $disposal): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'posting_date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->present($this->disposals->reverse($this->visible($disposal), $request->user(), $data['reason'], $data['posting_date'] ?? null), $request));
    }

    private function visible(AssetDisposal $disposal): AssetDisposal
    {
        $this->scope->authorize($disposal);

        return $disposal;
    }

    private function present(AssetDisposal $disposal, Request $request): array
    {
        $disposal = $this->disposals->load($disposal);

        return $disposal->toArray() + ['sod' => $this->disposals->sod($disposal, $request->user()->id)];
    }

    /** @return array<string,mixed> */
    private function payload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'fixed_asset_id' => [$req, 'uuid'], 'disposal_type' => [$req, Rule::in(['SALE', 'SCRAP'])], 'disposal_date' => [$req, 'date_format:Y-m-d'],
            'document_date' => ['nullable', 'date_format:Y-m-d'], 'posting_date' => ['nullable', 'date_format:Y-m-d'],
            'proceeds_amount' => ['nullable'], 'proceeds_account_id' => ['nullable', 'uuid'], 'reason' => [$req, 'string', 'max:500'], 'reference' => ['nullable', 'string', 'max:100'],
        ]);
    }
}
