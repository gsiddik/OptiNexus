<?php

use App\Http\Controllers\Api\V1\OidcClientController;
use Illuminate\Support\Facades\Route;

// SSO (OpenID Connect) client registrations for integrated applications.
Route::prefix('oidc-clients')->name('oidc-clients.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [OidcClientController::class, 'index'])->middleware('permission:cgo.sso.client.view')->name('index');
    Route::post('/', [OidcClientController::class, 'store'])->middleware('permission:cgo.sso.client.manage')->name('store');
    Route::get('/{oidcClient}', [OidcClientController::class, 'show'])->middleware('permission:cgo.sso.client.view')->name('show');
    Route::put('/{oidcClient}', [OidcClientController::class, 'update'])->middleware('permission:cgo.sso.client.manage')->name('update');
    Route::post('/{oidcClient}/rotate-secret', [OidcClientController::class, 'rotateSecret'])->middleware('permission:cgo.sso.client.manage')->name('rotate-secret');
    Route::post('/{oidcClient}/revoke', [OidcClientController::class, 'revoke'])->middleware('permission:cgo.sso.client.manage')->name('revoke');
});
