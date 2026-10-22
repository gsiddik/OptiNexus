<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// OptiRadar odometer pull (no-op unless OPTIRADAR_SYNC_ENABLED=true).
Schedule::command('gateway:sync-optiradar')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn () => config('gateway.optiradar.enabled'));

Schedule::command('subscriptions:process-deadlines')->everyMinute()->withoutOverlapping();

// Central logout safety net: access lost without a hook (expired subscription, direct database edit).
Schedule::command('oidc:reconcile-access')
    ->everyMinute()
    ->withoutOverlapping();

// Invitations whose link ran out are stamped EXPIRED (and audited) once an hour.
Schedule::command('invitations:expire')
    ->hourly()
    ->withoutOverlapping();
