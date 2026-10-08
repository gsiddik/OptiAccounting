<?php

namespace App\Providers;

use App\Support\IdentityMode;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Fail fast on a misconfigured installation instead of guessing a mode.
        IdentityMode::current();
    }
}
