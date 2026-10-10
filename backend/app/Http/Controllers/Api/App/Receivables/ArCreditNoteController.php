<?php

namespace App\Http\Controllers\Api\App\Receivables;

use App\Domain\Accounting\Services\DocumentScope;
use App\Domain\Accounting\Support\ListFilters;
use App\Domain\Receivables\Models\ArCreditNote;
use App\Domain\Receivables\Services\ArCreditNoteService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Credit notes. Thin: validation here, every rule in ArCreditNoteService. A credit note outside the user's data scope is a 404. */
class ArCreditNoteController extends AppController
{
    public function __construct(TenantContext $context, private readonly ArCreditNoteService $notes, private readonly DocumentScope $scope)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(ListFilters::creditNotes());
        $filter['mine'] = $request->boolean('mine');

        return response()->json($this->notes->query($filter)->paginate($filter['per_page'] ?? 25));
    }

    public function show(Request $request, ArCreditNote $note): JsonResponse
    {
        return response()->json($this->present($this->visible($note), $request));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->present($this->notes->create($this->payload($request, true), $request->user()), $request), 201);
    }

    public function update(Request $request, ArCreditNote $note): JsonResponse
    {
        $this->visible($note);

        return response()->json($this->present($this->notes->update($note, $this->payload($request, false), $request->user()), $request));
    }

    public function submit(Request $request, ArCreditNote $note): JsonResponse
    {
        return response()->json($this->present($this->notes->submit($this->visible($note), $request->user()), $request));
    }

    public function approve(Request $request, ArCreditNote $note): JsonResponse
    {
        return response()->json($this->present($this->notes->approve($this->visible($note), $request->user()), $request));
    }

    public function reject(Request $request, ArCreditNote $note): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->notes->reject($this->visible($note), $request->user(), $data['reason']), $request));
    }

    public function reopen(Request $request, ArCreditNote $note): JsonResponse
    {
        return response()->json($this->present($this->notes->reopen($this->visible($note), $request->user()), $request));
    }

    public function cancel(Request $request, ArCreditNote $note): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return response()->json($this->present($this->notes->cancel($this->visible($note), $request->user(), $data['reason']), $request));
    }

    public function post(Request $request, ArCreditNote $note): JsonResponse
    {
        return response()->json($this->present($this->notes->post($this->visible($note), $request->user()), $request));
    }

    public function reverse(Request $request, ArCreditNote $note): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500'], 'posting_date' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->present($this->notes->reverse($this->visible($note), $request->user(), $data['reason'], $data['posting_date'] ?? null), $request));
    }

    private function visible(ArCreditNote $note): ArCreditNote
    {
        $this->scope->authorize($note);

        return $note;
    }

    private function present(ArCreditNote $note, Request $request): array
    {
        $note = $this->notes->load($note);

        return $note->toArray() + ['sod' => $this->notes->sod($note, $request->user()->id)];
    }

    private function payload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'ar_invoice_id' => [$req, 'uuid'], 'reason' => [$req, 'string', 'max:500'], 'reference' => ['nullable', 'string', 'max:100'],
            'document_date' => [$req, 'date_format:Y-m-d'], 'posting_date' => ['nullable', 'date_format:Y-m-d'], 'currency' => ['nullable', 'regex:/^[A-Z]{3}$/'],
            'branch_id' => ['nullable', 'uuid'], 'business_unit_id' => ['nullable', 'uuid'], 'cost_center_id' => ['nullable', 'uuid'],
            'tax_amount' => ['nullable'],
            'lines' => [$req, 'array', 'min:1', 'max:300'],
            'lines.*.description' => ['required', 'string', 'max:255'], 'lines.*.quantity' => ['nullable'], 'lines.*.unit_price' => ['nullable'], 'lines.*.amount' => ['nullable'],
            'lines.*.account_role' => ['nullable', 'string', 'max:40'], 'lines.*.account_id' => ['nullable', 'uuid'],
            'lines.*.cost_center_id' => ['nullable', 'uuid'], 'lines.*.metadata' => ['nullable', 'array', 'max:20'],
        ]);
    }
}
