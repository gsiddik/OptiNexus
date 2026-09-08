<?php

use App\Http\Controllers\Api\V1\ServiceAccountController;
use Illuminate\Support\Facades\Route;

Route::prefix('service-accounts')->name('service-accounts.')->middleware(['auth:sanctum', 'permission:cgo.service_account.manage'])->group(function () {
    Route::get('/', [ServiceAccountController::class, 'index'])->name('index');
    Route::post('/', [ServiceAccountController::class, 'store'])->name('store');
    Route::get('/{serviceAccount}', [ServiceAccountController::class, 'show'])->name('show');
    Route::post('/{serviceAccount}/rotate-secret', [ServiceAccountController::class, 'rotateSecret'])->name('rotate-secret');
    Route::post('/{serviceAccount}/revoke', [ServiceAccountController::class, 'revoke'])->name('revoke');
});
