<?php

namespace App\Http\Controllers\Api\App\Closing;

use App\Domain\Closing\Models\ClosingChecklistTemplate;
use App\Domain\Closing\Services\ChecklistService;
use App\Domain\Closing\Services\ClosingPolicyService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The closing policy and the checklist templates (configuration; the rules live in ClosingPolicyService and ChecklistService). */
class ClosingPolicyController extends AppController
{
    public function __construct(TenantContext $context, private readonly ClosingPolicyService $policy, private readonly ChecklistService $checklist)
    {
        parent::__construct($context);
    }

    public function show(): JsonResponse
    {
        return response()->json($this->policy->policy());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'rules' => ['sometimes', 'array', 'max:20'],
            'rules.*.check_code' => ['required', 'string', 'max:40'],
            'rules.*.severity' => ['required', 'string', 'max:5'],
            'settings' => ['sometimes', 'array'],
            'settings.reconciliation_tolerance' => ['sometimes', 'string', 'max:25'],
            'settings.reopen_max_periods_back' => ['sometimes', 'nullable', 'integer', 'between:0,1000'],
            'settings.reopen_requires_later_open' => ['sometimes', 'boolean'],
            'settings.separate_closer_and_reopener' => ['sometimes', 'boolean'],
            'settings.require_soft_close_first' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->policy->update($data['rules'] ?? [], $data['settings'] ?? []));
    }

    public function templates(): JsonResponse
    {
        return response()->json(['data' => $this->checklist->templates()]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_]+$/'], 'title' => ['required', 'string', 'max:200'], 'description' => ['nullable', 'string', 'max:500'],
            'required' => ['sometimes', 'boolean'], 'sort_order' => ['sometimes', 'integer', 'between:0,65535'], 'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        return response()->json($this->checklist->createTemplate($data), 201);
    }

    public function updateTemplate(Request $request, ClosingChecklistTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'], 'description' => ['sometimes', 'nullable', 'string', 'max:500'], 'required' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,65535'], 'status' => ['sometimes', Rule::in(['ACTIVE', 'INACTIVE'])],
        ]);

        return response()->json($this->checklist->updateTemplate($template, $data));
    }

    public function destroyTemplate(ClosingChecklistTemplate $template): JsonResponse
    {
        $this->checklist->deleteTemplate($template);

        return response()->json(null, 204);
    }
}
