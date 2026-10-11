<?php

namespace App\Http\Controllers\Api\App\Integration;

use App\Domain\Integration\Models\ExternalEvent;
use App\Domain\Integration\Services\EventReader;
use App\Domain\Integration\Services\RetryService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** The event inbox: filtered list, detail with the stored envelope, and the authorized retry. Thin over EventReader / RetryService. */
class EventController extends AppController
{
    public function __construct(TenantContext $context, private readonly EventReader $events, private readonly RetryService $retries)
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

        return response()->json($this->events->query($filter)->paginate($filter['per_page'] ?? 50)->through(fn (ExternalEvent $e) => $this->events->present($e)));
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
}
