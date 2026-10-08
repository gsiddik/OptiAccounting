<?php

use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

/*
| Versioned API (docs/architecture/SYSTEM_ARCHITECTURE.md §7):
|   /api/v1/platform/*     platform / superadmin
|   /api/v1/app/*          tenant application
|   /api/v1/integration/*  machine-to-machine only
*/
Route::prefix('v1')->group(function () {
    Route::get('health', HealthController::class)->name('api.v1.health');
});
