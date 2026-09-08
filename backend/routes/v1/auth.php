<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Controllers\AccessTokenController;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('login');
    Route::post('/introspect', [AuthController::class, 'introspect'])->middleware('throttle:30,1')->name('introspect');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('/context', [AuthController::class, 'context'])->name('context');
    });
});

// Standards-compliant OAuth2 token endpoint (RFC 6749 client_credentials
// grant) used by integrated applications' service accounts.
Route::post('/oauth/token', [AccessTokenController::class, 'issueToken'])
    ->middleware('throttle:60,1')
    ->name('oauth.token');
