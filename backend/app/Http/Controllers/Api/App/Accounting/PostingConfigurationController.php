<?php

namespace App\Http\Controllers\Api\App\Accounting;

use App\Domain\Accounting\Models\AccountingEvent;
use App\Domain\Accounting\Models\AccountMapping;
use App\Domain\Accounting\Models\PostingRule;
use App\Domain\Accounting\Services\AccountingProfileService;
use App\Domain\Accounting\Services\AccountMappingService;
use App\Domain\Accounting\Services\PostingRuleService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Posting rules, account roles / mappings and the accounting event log (OA1 batches G-H). */
class PostingConfigurationController extends AppController
{
    public function __construct(
        TenantContext $context,
        private readonly PostingRuleService $rules,
        private readonly AccountMappingService $mappings,
        private readonly AccountingProfileService $profiles,
    ) {
        parent::__construct($context);
    }

    // ---------------------------------------------------------------------------------------------- rules

    public function eventTypes(): JsonResponse
    {
        return response()->json(['data' => $this->rules->eventTypes()]);
    }

    public function rules(Request $request): JsonResponse
    {
        $filters = $request->validate(['event_type' => ['nullable', 'string', 'max:40']]);

        return response()->json(['data' => $this->rules->list($filters['event_type'] ?? null)]);
    }

    public function showRule(PostingRule $rule): JsonResponse
    {
        return response()->json($rule->load('lines'));
    }

    public function storeRule(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
            'event_type' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ] + $this->lineRules(true));

        return response()->json($this->rules->create($data), 201);
    }

    public function updateRule(Request $request, PostingRule $rule): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ] + $this->lineRules(false));

        return response()->json($this->rules->update($rule, $data));
    }

    public function destroyRule(PostingRule $rule): JsonResponse
    {
        $this->rules->delete($rule);

        return response()->json(null, 204);
    }

    public function newVersion(PostingRule $rule): JsonResponse
    {
        return response()->json($this->rules->newVersion($rule), 201);
    }

    public function publishRule(Request $request, PostingRule $rule): JsonResponse
    {
        $data = $request->validate(['effective_from' => ['required', 'date_format:Y-m-d']]);

        return response()->json($this->rules->publish($rule, $data['effective_from']));
    }

    public function archiveRule(Request $request, PostingRule $rule): JsonResponse
    {
        $data = $request->validate(['effective_to' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->rules->archive($rule, $data['effective_to'] ?? null));
    }

    /** Dry run of a rule against a sample payload; nothing is stored. */
    public function simulate(Request $request, PostingRule $rule): JsonResponse
    {
        $data = $request->validate([
            'payload' => ['required', 'array', 'max:20'],
            'payload.*' => ['required'],
            'branch_id' => ['nullable', 'uuid'],
            'business_unit_id' => ['nullable', 'uuid'],
        ]);

        return response()->json($this->rules->simulate($rule, $data['payload'], [
            'branch_id' => $data['branch_id'] ?? null, 'business_unit_id' => $data['business_unit_id'] ?? null,
        ], (int) ($this->profiles->current()?->currency_scale ?? 2)));
    }

    // ---------------------------------------------------------------------------------------------- mappings

    public function mappings(): JsonResponse
    {
        return response()->json($this->mappings->overview());
    }

    public function saveMapping(Request $request): JsonResponse
    {
        $data = $request->validate([
            'account_role' => ['required', 'string', 'max:40'],
            'account_id' => ['required', 'uuid'],
            'branch_id' => ['nullable', 'uuid'],
            'business_unit_id' => ['nullable', 'uuid'],
        ]);

        return response()->json($this->mappings->save($data));
    }

    public function deactivateMapping(AccountMapping $mapping): JsonResponse
    {
        return response()->json($this->mappings->deactivate($mapping));
    }

    // ---------------------------------------------------------------------------------------------- event log

    /** The accounting event log: what each business fact produced, or why it failed. */
    public function events(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(['PENDING', 'POSTED', 'FAILED'])], 'event_type' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = AccountingEvent::query()->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['event_type'] ?? null, fn ($q, $v) => $q->where('event_type', $v))
            ->latest('created_at')->paginate($filters['per_page'] ?? 25);

        return response()->json($page->through(fn (AccountingEvent $e) => $e->only([
            'id', 'event_type', 'source_type', 'source_id', 'posting_purpose', 'status', 'posting_date', 'journal_entry_id', 'posting_rule_id', 'failure_code', 'failure_message', 'attempts', 'processed_at', 'created_at',
        ])));
    }

    private function lineRules(bool $required): array
    {
        return [
            'lines' => [$required ? 'required' : 'sometimes', 'array', 'min:2', 'max:20'],
            'lines.*.side' => ['required', Rule::in(['DEBIT', 'CREDIT'])],
            'lines.*.account_role' => ['required', 'string', 'max:40'],
            'lines.*.amount_key' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            'lines.*.skip_if_zero' => ['sometimes', 'boolean'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
