<?php

use App\Http\Controllers\Api\V1\EntitlementController;
use Illuminate\Support\Facades\Route;

Route::prefix('entitlements')->name('entitlements.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [EntitlementController::class, 'index'])->middleware('permission:cgo.entitlement.view')->name('index');
    Route::post('/{entitlement}/suspend', [EntitlementController::class, 'suspend'])->middleware('permission:cgo.entitlement.suspend')->name('suspend');
    Route::post('/{entitlement}/restore', [EntitlementController::class, 'restore'])->middleware('permission:cgo.entitlement.restore')->name('restore');
});

// Machine-to-machine only: integrated applications ask whether a tenant is
// entitled to an application/feature/limit.
Route::post('/entitlements/check', [EntitlementController::class, 'check'])
    ->middleware(['service_account:entitlement.check'])
    ->name('entitlements.check');

Route::prefix('tenants/{tenant}')->name('tenants.')->middleware('auth:sanctum')->group(function () {
    Route::get('/entitlements', [EntitlementController::class, 'forTenant'])->middleware('permission:cgo.entitlement.view,tenant')->name('entitlements.index');
    Route::get('/effective-entitlements', [EntitlementController::class, 'effectiveForTenant'])->middleware('permission:cgo.entitlement.effective.view,tenant')->name('effective-entitlements');
    Route::post('/entitlement-overrides', [EntitlementController::class, 'storeOverride'])->middleware('permission:cgo.entitlement.override,tenant')->name('entitlement-overrides.store');
});

// Machine-to-machine only: an integrated application's authoritative view
// of a tenant's commercial state (plan, status, entitlements, period).
Route::get('/tenants/{tenant}/commercial-context', [EntitlementController::class, 'commercialContext'])
    ->middleware(['service_account:commercial.read'])
    ->name('tenants.commercial-context');
