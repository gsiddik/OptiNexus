<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// OptiRadar odometer pull (no-op unless OPTIRADAR_SYNC_ENABLED=true).
\Illuminate\Support\Facades\Schedule::command('gateway:sync-optiradar')
    ->everyMinute()
    ->withoutOverlapping()
    ->when(fn () => config('gateway.optiradar.enabled'));
