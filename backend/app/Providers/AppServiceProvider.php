<?php

namespace App\Providers;

use App\Domain\AccessControl\Services\LocalPermissionSource;
use App\Domain\AccessControl\Services\PermissionSource;
use App\Domain\Integration\Optinexus\NexusPermissionSource;
use App\Domain\Integration\Optinexus\OptinexusSettings;
use App\Support\IdentityMode;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One context per request/job (scoped, so queue workers and Octane never leak a tenant).
        $this->app->scoped(TenantContext::class);

        // The one place the identity mode picks an implementation; authorization code never branches on it.
        $this->app->bind(PermissionSource::class, fn ($app) => OptinexusSettings::active()
            ? $app->make(NexusPermissionSource::class)
            : $app->make(LocalPermissionSource::class));
    }

    public function boot(): void
    {
        // Fail fast on a misconfigured installation instead of guessing a mode.
        IdentityMode::current();

        RateLimiter::for('sso', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
        RateLimiter::for('backchannel', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
    }
}
