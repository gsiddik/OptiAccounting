<?php

namespace App\Http\Controllers\Api\App\Integration;

use App\Domain\Integration\Models\IntegrationGap;
use App\Domain\Integration\Services\GapService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Gaps (a connection was paused or disconnected while its source went on): list, detail and the review decision for the events held meanwhile. Thin over GapService. */
class GapController extends AppController
{
    public function __construct(TenantContext $context, private readonly GapService $gaps)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate(['connection_id' => ['nullable', 'uuid'], 'per_page' => ['nullable', 'integer', 'between:1,100']]);

        return response()->json($this->gaps->query($filter['connection_id'] ?? null)->paginate($filter['per_page'] ?? 25)->through(fn (IntegrationGap $g) => $this->gaps->present($g)));
    }

    public function show(string $gap): JsonResponse
    {
        return response()->json($this->gaps->present($this->gaps->find($gap)));
    }

    /** PROCESS books the held events through the normal path, DISMISS refuses them all (permission accounting.integration.manage); at most 100 per call. */
    public function resolve(Request $request, string $gap): JsonResponse
    {
        $this->gaps->find($gap);
        $data = $request->validate(['decision' => ['required', Rule::in(['PROCESS', 'DISMISS'])], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json($this->gaps->resolve($gap, $data['decision'], trim($data['reason'])));
    }
}
