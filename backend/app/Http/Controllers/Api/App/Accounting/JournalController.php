<?php

namespace App\Http\Controllers\Api\App\Accounting;

use App\Domain\Accounting\Models\JournalEntry;
use App\Domain\Accounting\Services\AccountingScope;
use App\Domain\Accounting\Services\JournalService;
use App\Domain\Accounting\Services\JournalWorkflow;
use App\Domain\Accounting\Services\ReversalService;
use App\Domain\Accounting\Services\SegregationOfDuties;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Manual journals. Thin: validation here, every rule in the domain services. A journal outside the user's data scope is a 404. */
class JournalController extends AppController
{
    public function __construct(
        TenantContext $context,
        private readonly JournalService $journals,
        private readonly JournalWorkflow $workflow,
        private readonly ReversalService $reversals,
        private readonly AccountingScope $scope,
        private readonly SegregationOfDuties $sod,
    ) {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in(array_keys(JournalEntry::TRANSITIONS))],
            'type' => ['nullable', Rule::in([JournalEntry::MANUAL, JournalEntry::OPENING, JournalEntry::REVERSAL, JournalEntry::SYSTEM])],
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'],
            'q' => ['nullable', 'string', 'max:100'], 'mine' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $query = JournalEntry::query()->with('creator');
        $this->scope->restrictJournals($query);
        $query->when($filter['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filter['type'] ?? null, fn ($q, $v) => $q->where('journal_type', $v))
            ->when($filter['from'] ?? null, fn ($q, $v) => $q->whereDate('posting_date', '>=', $v))
            ->when($filter['to'] ?? null, fn ($q, $v) => $q->whereDate('posting_date', '<=', $v))
            ->when($request->boolean('mine'), fn ($q) => $q->where('created_by', $request->user()->id))
            ->when($filter['q'] ?? null, function ($q, $v) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($v)).'%';
                $q->where(fn ($w) => $w->whereRaw('lower(journal_number) like ?', [$like])->orWhereRaw('lower(description) like ?', [$like])->orWhereRaw('lower(reference) like ?', [$like]));
            })
            ->orderByDesc('posting_date')->orderByDesc('created_at');

        return response()->json($query->paginate($filter['per_page'] ?? 25));
    }

    public function show(JournalEntry $journal): JsonResponse
    {
        return response()->json($this->present($this->visible($journal)));
    }

    public function store(Request $request): JsonResponse
    {
        $journal = $this->journals->createManual($this->payload($request, true), $request->user());

        return response()->json($this->present($journal), 201);
    }

    public function update(Request $request, JournalEntry $journal): JsonResponse
    {
        $journal = $this->visible($journal);

        return response()->json($this->present($this->journals->update($journal, $this->payload($request, false), $request->user())));
    }

    public function submit(Request $request, JournalEntry $journal): JsonResponse
    {
        return $this->act(fn () => $this->workflow->submit($this->visible($journal), $request->user()));
    }

    public function approve(Request $request, JournalEntry $journal): JsonResponse
    {
        return $this->act(fn () => $this->workflow->approve($this->visible($journal), $request->user()));
    }

    public function reject(Request $request, JournalEntry $journal): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->act(fn () => $this->workflow->reject($this->visible($journal), $request->user(), $data['reason']));
    }

    public function reopen(Request $request, JournalEntry $journal): JsonResponse
    {
        return $this->act(fn () => $this->workflow->reopen($this->visible($journal), $request->user()));
    }

    public function cancel(Request $request, JournalEntry $journal): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->act(fn () => $this->workflow->cancel($this->visible($journal), $request->user(), $data['reason']));
    }

    public function post(Request $request, JournalEntry $journal): JsonResponse
    {
        return $this->act(fn () => $this->workflow->post($this->visible($journal), $request->user()));
    }

    public function reverse(Request $request, JournalEntry $journal): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            'posting_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        return response()->json($this->present($this->reversals->reverse($this->visible($journal), $request->user(), $data['reason'], $data['posting_date'] ?? null, $data['reference'] ?? null)), 201);
    }

    private function act(callable $work): JsonResponse
    {
        return response()->json($this->present($work()));
    }

    private function visible(JournalEntry $journal): JournalEntry
    {
        abort_unless($this->scope->journalVisible($journal), 404);

        return $journal;
    }

    private function payload(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'document_date' => [$req, 'date_format:Y-m-d'],
            'posting_date' => [$req, 'date_format:Y-m-d'],
            'transaction_date' => ['nullable', 'date_format:Y-m-d'],
            'description' => [$req, 'string', 'max:500'],
            'reference' => ['nullable', 'string', 'max:100'],
            'lines' => [$req, 'array', 'max:500'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.debit' => ['nullable'],
            'lines.*.credit' => ['nullable'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.reference' => ['nullable', 'string', 'max:100'],
            'lines.*.branch_id' => ['nullable', 'uuid'],
            'lines.*.business_unit_id' => ['nullable', 'uuid'],
            'lines.*.cost_center_id' => ['nullable', 'uuid'],
            'lines.*.dimensions' => ['nullable', 'array', 'max:10'],
            'lines.*.dimensions.*.type' => ['required', 'string', 'max:40'],
            'lines.*.dimensions.*.reference_id' => ['required', 'string', 'max:64'],
            'lines.*.dimensions.*.reference_label' => ['nullable', 'string', 'max:255'],
        ]);
    }

    /** Journal with its lines, history and what the signed-in user may still do under the segregation-of-duties policy. */
    private function present(JournalEntry $journal): array
    {
        $journal->load(['creator', 'lines.account:id,code,name,normal_balance', 'lines.branch', 'lines.businessUnit', 'lines.costCenter', 'lines.dimensions', 'transitions']);
        $profile = $this->journals->profile();

        return $journal->toArray() + ['sod' => $this->sod->allowed($journal, $this->scope->userId() ?? '', $profile), 'approval_required' => $profile->approval_required];
    }
}
