<?php

namespace App\Http\Controllers\Api\App\Accounting;

use App\Domain\Accounting\Models\AccountingProfile;
use App\Domain\Accounting\Services\AccountingProfileService;
use App\Domain\Accounting\Services\ReadinessService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Accounting profile, readiness checklist and activation. */
class SetupController extends AppController
{
    public function __construct(TenantContext $context, private readonly AccountingProfileService $profiles, private readonly ReadinessService $readiness)
    {
        parent::__construct($context);
    }

    public function profile(): JsonResponse
    {
        return response()->json(['data' => $this->profiles->current(), 'frameworks' => AccountingProfile::FRAMEWORKS]);
    }

    public function saveProfile(Request $request): JsonResponse
    {
        $exists = $this->profiles->current() !== null;
        $data = $request->validate([
            'framework' => [$exists ? 'sometimes' : 'required', Rule::in(AccountingProfile::FRAMEWORKS)],
            'functional_currency' => [$exists ? 'sometimes' : 'required', 'string', 'regex:/^[A-Z]{3}$/'],
            'currency_scale' => ['sometimes', 'integer', 'between:0,4'],
            'approval_required' => ['sometimes', 'boolean'],
            'sod_creator_not_approver' => ['sometimes', 'boolean'],
            'sod_creator_not_poster' => ['sometimes', 'boolean'],
            'sod_approver_not_poster' => ['sometimes', 'boolean'],
        ]);

        return response()->json($this->profiles->save($data), $exists ? 200 : 201);
    }

    public function activate(): JsonResponse
    {
        return response()->json($this->profiles->activate());
    }

    public function readiness(): JsonResponse
    {
        return response()->json($this->readiness->report());
    }
}
