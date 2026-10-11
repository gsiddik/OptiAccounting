<?php

use App\Http\Controllers\Api\App\Reconciliation\ReconciliationController;
use App\Http\Controllers\Api\App\Reconciliation\ReconciliationExceptionController;
use Illuminate\Support\Facades\Route;

/*
| OA5 reconciliation routes. Included from routes/api.php inside the tenant `accounting` prefix group (auth + tenant context already applied).
| Every route declares access:<permission>,module=ACCOUNTING_REPORTING,feature=<feature>; the central resolver is the only authority.
| `{type}` is the slug of a ReconciliationResult type (ap-gl, ar-gl, cash-bank-gl, fixed-asset-gl, tax-gl, fx-gl); a type whose own module is not
| entitled answers 403 MODULE_NOT_ENTITLED. The legacy singular routes (reconciliation/ap|ar|cash-bank, asset-reconciliation) are unchanged.
*/
$rp = fn (string $permission, string $feature = 'RECONCILIATION') => "access:{$permission},module=ACCOUNTING_REPORTING,feature={$feature}";

Route::get('reconciliations', [ReconciliationController::class, 'overview'])->middleware($rp('accounting.reconciliation.view'));
Route::get('reconciliations/export', [ReconciliationController::class, 'exportAll'])->middleware($rp('accounting.report.export'));
Route::get('reconciliations/runs', [ReconciliationController::class, 'runs'])->middleware($rp('accounting.reconciliation.view'));
Route::get('reconciliations/{type}', [ReconciliationController::class, 'show'])->middleware($rp('accounting.reconciliation.view'));
Route::post('reconciliations/{type}/run', [ReconciliationController::class, 'run'])->middleware($rp('accounting.reconciliation.run'));
Route::get('reconciliations/{type}/export', [ReconciliationController::class, 'export'])->middleware($rp('accounting.report.export'));

Route::get('reconciliation-exceptions', [ReconciliationExceptionController::class, 'index'])->middleware($rp('accounting.reconciliation.view'));
Route::post('reconciliation-exceptions/{exception}/assign', [ReconciliationExceptionController::class, 'assign'])->middleware($rp('accounting.reconciliation.exception.manage'));
Route::post('reconciliation-exceptions/{exception}/explain', [ReconciliationExceptionController::class, 'explain'])->middleware($rp('accounting.reconciliation.exception.manage'));
Route::post('reconciliation-exceptions/{exception}/resolve', [ReconciliationExceptionController::class, 'resolve'])->middleware($rp('accounting.reconciliation.exception.manage'));
