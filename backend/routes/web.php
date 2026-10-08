<?php

use Illuminate\Support\Facades\Route;

// The backend is API-only; the React SPA lives in frontend/.
Route::get('/', fn () => response()->json(['service' => 'optiaccounting-api', 'docs' => '/api/v1/health']));
