<?php

use App\Http\Controllers\Api\V1\BillingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/billings', [BillingController::class, 'index'])->middleware('permission:cgo.billing.view')->name('billings.index');
    Route::get('/billings/{billing}', [BillingController::class, 'show'])->middleware('permission:cgo.billing.view,billing')->name('billings.show');

    Route::post('/billing-runs', [BillingController::class, 'run'])->middleware('permission:cgo.billing.create')->name('billing-runs.store');

    Route::post('/billings/{billing}/recalculate', [BillingController::class, 'recalculate'])->middleware('permission:cgo.billing.update,billing')->name('billings.recalculate');
    Route::post('/billings/{billing}/review', [BillingController::class, 'review'])->middleware('permission:cgo.billing.review,billing')->name('billings.review');
    Route::post('/billings/{billing}/finalize', [BillingController::class, 'finalize'])->middleware('permission:cgo.billing.finalize,billing')->name('billings.finalize');
    Route::post('/billings/{billing}/cancel', [BillingController::class, 'cancel'])->middleware('permission:cgo.billing.cancel,billing')->name('billings.cancel');
    Route::post('/billings/{billing}/adjustments', [BillingController::class, 'addAdjustment'])->middleware('permission:cgo.billing.adjust,billing')->name('billings.adjustments.store');
});
