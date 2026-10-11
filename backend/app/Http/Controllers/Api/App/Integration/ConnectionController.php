<?php

namespace App\Http\Controllers\Api\App\Integration;

use App\Domain\Integration\Models\IntegrationConnection;
use App\Domain\Integration\Services\ConnectionService;
use App\Domain\Integration\Services\PullService;
use App\Http\Controllers\Api\App\AppController;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Integration connections: configuration, lifecycle, "sync now" and the received-vs-processed summary. Thin over ConnectionService / PullService (see there for the rules). */
class ConnectionController extends AppController
{
    public function __construct(TenantContext $context, private readonly ConnectionService $connections, private readonly PullService $pulls)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $filter = $request->validate([
            'status' => ['nullable', Rule::in(IntegrationConnection::STATUSES)], 'source_system' => ['nullable', 'string', 'max:40'], 'per_page' => ['nullable', 'integer', 'between:1,200'],
        ]);

        return response()->json($this->connections->query($filter)->paginate($filter['per_page'] ?? 50)->through(fn (IntegrationConnection $c) => $this->connections->present($c)));
    }

    public function show(string $connection): JsonResponse
    {
        return response()->json($this->connections->present($this->connections->find($connection)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'source_system' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,39}$/'],
        ] + $this->settingRules(false));

        return response()->json($this->connections->present($this->connections->create($data)), 201);
    }

    public function update(Request $request, string $connection): JsonResponse
    {
        $this->connections->find($connection); // a connection of another tenant is 404 before anything else is said about the request
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'min:2', 'max:120']] + $this->settingRules(true));

        return response()->json($this->connections->present($this->connections->update($connection, $data)));
    }

    public function activate(string $connection): JsonResponse
    {
        return response()->json($this->connections->present($this->connections->activate($connection)));
    }

    public function pause(string $connection): JsonResponse
    {
        return response()->json($this->connections->present($this->connections->pause($connection)));
    }

    public function resume(string $connection): JsonResponse
    {
        return response()->json($this->connections->present($this->connections->resume($connection)));
    }

    public function disconnect(string $connection): JsonResponse
    {
        return response()->json($this->connections->present($this->connections->disconnect($connection)));
    }

    public function syncNow(string $connection): JsonResponse
    {
        return response()->json($this->pulls->syncNow($connection));
    }

    public function summary(string $connection): JsonResponse
    {
        return response()->json($this->connections->summary($connection));
    }

    /** @return array<string,array<int,mixed>> */
    private function settingRules(bool $partial): array
    {
        $sometimes = $partial ? ['sometimes'] : [];

        return [
            'source_tenant_reference' => [...$sometimes, 'nullable', 'string', 'max:120'],
            // The credential travels as a bearer token, so a production installation talks to the source over HTTPS only.
            'base_url' => [...$sometimes, 'nullable', 'string', 'max:255', app()->environment(['local', 'testing']) ? 'url:http,https' : 'url:https'],
            'credential' => [...$sometimes, 'nullable', 'string', 'min:8', 'max:500'],
            'cutover_policy' => [...$sometimes, Rule::in(IntegrationConnection::CUTOVER_POLICIES)],
            'cutover_date' => [...$sometimes, 'nullable', 'date_format:Y-m-d'],
            'gap_policy' => [...$sometimes, Rule::in(IntegrationConnection::GAP_POLICIES)],
            'pull_interval_minutes' => [...$sometimes, 'integer', 'between:1,1440'],
            // Read by the source's adapter (ids of the tenant's own records); a key sent as null is removed.
            'settings' => [...$sometimes, 'nullable', 'array'],
            'settings.*' => ['nullable', 'string', 'max:100'],
        ];
    }
}
