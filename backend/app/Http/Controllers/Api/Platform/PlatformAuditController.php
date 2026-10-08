<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlatformAuditController extends PlatformController
{
    public function index(Request $request): JsonResponse
    {
        $rows = $this->context->withoutTenantScope(function () use ($request) {
            $query = AuditLog::query()->orderByDesc('occurred_at');
            if ($tenant = $request->query('tenant_id')) {
                $query->where('tenant_id', $tenant);
            }
            if ($action = $request->query('action')) {
                $query->where('action', 'like', $action.'%');
            }

            return $query->paginate(50);
        });

        return response()->json($rows);
    }
}
