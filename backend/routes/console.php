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

// Central logout safety net: access lost without a hook (expired subscription, direct database edit).
Schedule::command('oidc:reconcile-access')
    ->everyMinute()
    ->withoutOverlapping();
