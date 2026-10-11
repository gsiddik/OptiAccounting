<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// OptiNexus adapter (identity mode `optinexus`; both commands do nothing otherwise).
Schedule::command('optientry:nexus:relay-events')->everyMinute()->withoutOverlapping(10);
Schedule::command('optientry:nexus:sync-entitlements')->everyFiveMinutes()->withoutOverlapping(10);

// Integration platform (OA6): pull the source feeds, then apply whatever is due. Both skip tenants that are not entitled or not writable.
Schedule::command('integration:pull')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('integration:process')->everyFiveMinutes()->withoutOverlapping(10);
