<?php

namespace App\Http\Controllers\Api\App\Integration;

use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Models\ExternalEventConflict;
use App\Domain\Integration\Services\ConflictService;
use App\Domain\Integration\Services\EventReader;
use App\Domain\Integration\Services\RetryService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The event inbox: filtered list, detail with the stored envelope, the authorized retry and the two review decisions (dismiss, conflict resolution). Thin over EventReader / RetryService / ConflictService. */
class EventController extends AppController
{
    public function __construct(TenantContext $context, private readonly EventReader $events, private readonly RetryService $retries, private readonly ConflictService $conflicts)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in(ExternalEvent::STATUSES)], 'event_type' => ['nullable', 'string', 'max:120'], 'connection_id' => ['nullable', 'uuid'],
            'conflict' => ['nullable', 'boolean'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);

        return response()->json($this->events->presentPage($this->events->query($filter)->paginate($filter['per_page'] ?? 50)));
    }

    public function show(string $event): JsonResponse
    {
        return response()->json($this->events->present($this->events->find($event), detail: true));
    }

    public function retry(Request $request, string $event): JsonResponse
    {
        $this->events->find($event); // an event of another tenant is 404 before anything else is said about the request
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json($this->events->present($this->retries->retry($event, trim($data['reason'])), detail: true));
    }

    /** The reviewer decides the event must never be booked (permission accounting.integration.retry). */
    public function dismiss(Request $request, string $event): JsonResponse
    {
        $this->events->find($event);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json($this->events->present($this->retries->dismiss($event, trim($data['reason'])), detail: true));
    }

    /** Decide about an event the source delivered twice with different content (permission accounting.integration.manage). */
    public function resolveConflict(Request $request, string $event): JsonResponse
    {
        $this->events->find($event);
        $data = $request->validate(['decision' => ['required', Rule::in(ExternalEventConflict::RESOLUTIONS)], 'reason' => ['required', 'string', 'min:3', 'max:500']]);

        return response()->json($this->events->present($this->conflicts->resolve($event, $data['decision'], trim($data['reason'])), detail: true));
    }
}
