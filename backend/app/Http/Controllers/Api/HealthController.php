<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Liveness/readiness probe for the API. Reports no tenant data and no secrets.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $database = $this->databaseIsReachable();

        return response()->json([
            'service' => 'optiaccounting-api',
            'api_version' => 'v1',
            'identity_mode' => config('optiaccounting.identity_mode'),
            'checks' => ['database' => $database ? 'ok' : 'unavailable'],
        ], $database ? 200 : 503);
    }

    private function databaseIsReachable(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
