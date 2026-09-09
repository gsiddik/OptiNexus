<?php

use App\Http\Controllers\Api\V1\AccessController;
use Illuminate\Support\Facades\Route;

// Machine-to-machine only: aggregates entitlement + permission + policy +
// feature flag into one effective-access decision for integrated apps.
Route::post('/access/evaluate', [AccessController::class, 'evaluate'])
    ->middleware(['service_account:access.evaluate'])
    ->name('access.evaluate');
