<?php

use App\Http\Controllers\Api\V1\EcosystemOnboardingController;
use Illuminate\Support\Facades\Route;

// Ecosystem operators only (audit A14): what is still missing before a tenant can really use its products.
Route::prefix('ecosystem')->name('ecosystem.')->middleware(['auth:sanctum', 'ecosystem'])->group(function () {
    Route::get('/onboarding/tenants/{tenant}', [EcosystemOnboardingController::class, 'show'])
        ->middleware('permission:cgo.tenant.view,tenant')->name('onboarding.show');
});
