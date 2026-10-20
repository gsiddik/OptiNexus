<?php

use App\Http\Controllers\Api\V1\EcosystemOnboardingController;
use App\Http\Controllers\Api\V1\TenantAdminAccountController;
use Illuminate\Support\Facades\Route;

// Ecosystem operators only (audit A14): what is still missing before a tenant can really use its products.
Route::prefix('ecosystem')->name('ecosystem.')->middleware(['auth:sanctum', 'ecosystem'])->group(function () {
    Route::get('/onboarding/tenants/{tenant}', [EcosystemOnboardingController::class, 'show'])
        ->middleware('permission:cgo.tenant.view,tenant')->name('onboarding.show');

    // First Admin Tenant (owner decision 2026-10-18, second batch; GOVERNANCE_CONTRACTS.md 2c): created and activated by an operator, no e-mail.
    // Granting follows the same permissions as the step-by-step calls (checked per tenant inside the service); verifying an e-mail
    // address needs its own permission and is never available to the account itself.
    Route::prefix('tenants/{tenant}/admin-accounts')->name('admin-accounts.')->group(function () {
        Route::get('/', [TenantAdminAccountController::class, 'index'])
            ->middleware('permission:cgo.tenant.view,tenant')->name('index');
        Route::get('/account-options', [TenantAdminAccountController::class, 'accountOptions'])
            ->middleware('permission:cgo.tenant.admin.assign,tenant')->name('account-options');
        Route::post('/', [TenantAdminAccountController::class, 'store'])
            ->middleware(['throttle:20,1', 'permission:cgo.tenant.admin.assign,tenant'])->name('store');
        Route::post('/{user}/email-verification', [TenantAdminAccountController::class, 'verifyEmail'])
            ->middleware(['throttle:20,1', 'permission:cgo.user.email.verify'])->name('email-verification');
        Route::post('/{user}/temporary-credential', [TenantAdminAccountController::class, 'reissueCredential'])
            ->middleware(['throttle:20,1', 'permission:cgo.tenant.admin.assign,tenant'])->name('temporary-credential');
    });
});
