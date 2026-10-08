<?php

use App\Http\Controllers\Oidc\OidcProviderController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// OpenID Connect provider - browser-facing endpoints (session + CSRF).
Route::get('/.well-known/openid-configuration', [OidcProviderController::class, 'discovery'])->name('oidc.discovery');
Route::get('/oidc/jwks', [OidcProviderController::class, 'jwks'])->name('oidc.jwks');
Route::get('/oidc/authorize', [OidcProviderController::class, 'authorize'])->name('oidc.authorize');
Route::get('/oidc/login', [OidcProviderController::class, 'showLogin'])->name('oidc.login');
Route::post('/oidc/login', [OidcProviderController::class, 'login'])->middleware('throttle:10,1')->name('oidc.login.submit');
Route::post('/oidc/tenant', [OidcProviderController::class, 'selectTenant'])->name('oidc.tenant.select');
Route::get('/oidc/logout', [OidcProviderController::class, 'logout'])->name('oidc.logout');
