<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Identity\Services\TenantService;
use App\Support\TenantContext;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantController extends PlatformController
{
    public function __construct(TenantContext $context, private readonly TenantService $tenants)
    {
        parent::__construct($context);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Tenant::query()->orderBy('name');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }

        return response()->json($query->paginate(25));
    }

    public function show(Tenant $tenant): JsonResponse
    {
        return response()->json($tenant);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            ...$this->rules(),
            'code' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,48}[a-z0-9]$/', 'unique:tenants,code'],
            'admin' => ['nullable', 'array'],
            'admin.name' => ['required_with:admin', 'string', 'max:255'],
            'admin.email' => ['required_with:admin', 'email', 'max:255'],
            'admin.password' => ['nullable', $this->passwordRule()],
        ]);

        $admin = $data['admin'] ?? null;
        unset($data['admin']);

        return response()->json($this->tenants->create($data, $admin), 201);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate($this->rules(partial: true));

        return response()->json($this->tenants->update($tenant, $data));
    }

    public function transition(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Tenant::TRANSITIONS))],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        return response()->json($this->tenants->transition($tenant, $data['status'], $data['reason']));
    }

    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'timezone' => ['sometimes', Rule::in(DateTimeZone::listIdentifiers())],
            'default_locale' => ['sometimes', 'string', 'max:10'],
            'default_currency' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
        ];
    }
}
