<?php

use App\Http\Controllers\Api\V1\AuthorizationController;
use Illuminate\Support\Facades\Route;

// Machine-to-machine only: integrated applications call this with a
// client-credentials token carrying the `authorization.check` scope.
Route::post('/authorization/check', [AuthorizationController::class, 'check'])
    ->middleware(['service_account:authorization.check'])
    ->name('authorization.check');
