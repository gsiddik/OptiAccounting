<?php

use App\Http\Controllers\Api\App\Integration;
use Illuminate\Support\Facades\Route;

/*
| OA6 integration routes. Included from routes/api.php inside the tenant `app` group (auth + tenant context already applied), so the paths are
| /api/v1/app/integration/*. Every route declares access:<permission>,module=ACCOUNTING_INTEGRATION,feature=INTEGRATION_CONNECTION; the central
| resolver is the only authority (READ_ONLY entitlement and an overdue subscription refuse every mutation there).
*/
$gate = fn (string $permission) => "access:{$permission},module=ACCOUNTING_INTEGRATION,feature=INTEGRATION_CONNECTION";

Route::prefix('integration')->group(function () use ($gate) {
    Route::get('connections', [Integration\ConnectionController::class, 'index'])->middleware($gate('accounting.integration.view'));
    Route::post('connections', [Integration\ConnectionController::class, 'store'])->middleware($gate('accounting.integration.manage'));
    Route::get('connections/{connection}', [Integration\ConnectionController::class, 'show'])->middleware($gate('accounting.integration.view'));
    Route::patch('connections/{connection}', [Integration\ConnectionController::class, 'update'])->middleware($gate('accounting.integration.manage'));
    Route::post('connections/{connection}/activate', [Integration\ConnectionController::class, 'activate'])->middleware($gate('accounting.integration.manage'));
    Route::post('connections/{connection}/pause', [Integration\ConnectionController::class, 'pause'])->middleware($gate('accounting.integration.manage'));
    Route::post('connections/{connection}/resume', [Integration\ConnectionController::class, 'resume'])->middleware($gate('accounting.integration.manage'));
    Route::post('connections/{connection}/disconnect', [Integration\ConnectionController::class, 'disconnect'])->middleware($gate('accounting.integration.manage'));
    Route::post('connections/{connection}/sync-now', [Integration\ConnectionController::class, 'syncNow'])->middleware($gate('accounting.integration.manage'));
    Route::get('connections/{connection}/summary', [Integration\ConnectionController::class, 'summary'])->middleware($gate('accounting.integration.view'));

    Route::get('events', [Integration\EventController::class, 'index'])->middleware($gate('accounting.integration.view'));
    Route::get('events/{event}', [Integration\EventController::class, 'show'])->middleware($gate('accounting.integration.view'));
    Route::post('events/{event}/retry', [Integration\EventController::class, 'retry'])->middleware($gate('accounting.integration.retry'));

    Route::get('dimensions', [Integration\ReferenceController::class, 'dimensions'])->middleware($gate('accounting.integration.view'));
    Route::get('source-links', [Integration\ReferenceController::class, 'sourceLinks'])->middleware($gate('accounting.integration.view'));
});
