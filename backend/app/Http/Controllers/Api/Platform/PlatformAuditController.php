<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformAuditController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['tenant_id' => ['nullable', 'uuid'], 'action' => ['nullable', 'string', 'max:100']]);

        $rows = $this->context->withoutTenantScope(function () use ($filters) {
            $query = AuditLog::query()->orderByDesc('occurred_at');
            if ($tenant = $filters['tenant_id'] ?? null) {
                $query->where('tenant_id', $tenant);
            }
            if ($action = $filters['action'] ?? null) {
                $query->where('action', 'like', addcslashes($action, '%_\\').'%');
            }

            return $query->paginate(50);
        });

        return response()->json($rows);
    }
}
