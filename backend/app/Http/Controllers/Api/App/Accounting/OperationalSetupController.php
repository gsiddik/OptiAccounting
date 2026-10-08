<?php

namespace App\Http\Controllers\Api\App\Accounting;

use App\Domain\Accounting\Services\OperationalSetupService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Which OA2 posting rules exist, and one action to create the missing standard ones. */
class OperationalSetupController extends AppController
{
    public function __construct(TenantContext $context, private readonly OperationalSetupService $setup)
    {
        parent::__construct($context);
    }

    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->setup->status()]);
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate(['effective_from' => ['nullable', 'date_format:Y-m-d']]);

        return response()->json($this->setup->applyDefaults($data['effective_from'] ?? null), 201);
    }
}
