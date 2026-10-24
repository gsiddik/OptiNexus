<?php

use App\Http\Controllers\Api\V1\UsageEventController;
use Illuminate\Support\Facades\Route;

Route::get('/usage', [UsageEventController::class, 'index'])
    ->middleware(['auth:sanctum', 'ecosystem', 'permission:cgo.usage.view'])
    ->name('usage.index');

Route::get('/tenants/{tenant}/usage', [UsageEventController::class, 'forTenant'])
    ->middleware(['auth:sanctum', 'ecosystem', 'permission:cgo.usage.view,tenant'])
    ->name('tenants.usage.index');

// Machine-to-machine only: integrated applications report chargeable usage.
Route::post('/usage-events', [UsageEventController::class, 'store'])
    ->middleware(['service_account:usage.write', 'service_account.tenant'])
    ->name('usage-events.store');
