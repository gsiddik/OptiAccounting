<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// OptiNexus adapter (identity mode `optinexus`; both commands do nothing otherwise).
Schedule::command('optiaccounting:nexus:relay-events')->everyMinute()->withoutOverlapping(10);
Schedule::command('optiaccounting:nexus:sync-entitlements')->everyFiveMinutes()->withoutOverlapping(10);
