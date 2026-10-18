<?php

use App\Http\Controllers\InvitationPageController;
use App\Http\Controllers\Oidc\OidcProviderController;
use App\Http\Middleware\InvitationPageHeaders;
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

// Onboarding by invitation (owner decision 2026-10-18): the invitee's pages. The e-mailed link validates and hands over to a clean
// URL (nothing is consumed by opening it); acceptance is a CSRF-protected form post. See GOVERNANCE_CONTRACTS.md section 2b.
Route::middleware(InvitationPageHeaders::class)->prefix('invitations')->group(function () {
    Route::get('/accept', [InvitationPageController::class, 'show'])->middleware('throttle:invitation-view')->name('invitations.accept');
    Route::post('/accept', [InvitationPageController::class, 'submit'])->middleware('throttle:invitation-submit')->name('invitations.accept.submit');
    Route::get('/{token}', [InvitationPageController::class, 'open'])->middleware('throttle:invitation-open')->where('token', '[A-Za-z0-9]{1,128}')->name('invitations.open');
});
