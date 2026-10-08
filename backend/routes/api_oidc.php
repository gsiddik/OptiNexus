<?php

use App\Http\Controllers\Oidc\OidcProviderController;
use Illuminate\Support\Facades\Route;

Route::prefix('oidc')->name('api.oidc.')->group(function () {
    Route::post('/token', [OidcProviderController::class, 'token'])->middleware('throttle:60,1')->name('token');
    Route::match(['get', 'post'], '/userinfo', [OidcProviderController::class, 'userinfo'])->name('userinfo');
});
