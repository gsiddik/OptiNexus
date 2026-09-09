<?php

use App\Http\Controllers\Api\V1\FeatureFlagController;
use Illuminate\Support\Facades\Route;

Route::prefix('feature-flags')->name('feature-flags.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [FeatureFlagController::class, 'index'])->middleware('permission:cgo.featureflag.view')->name('index');
    Route::post('/', [FeatureFlagController::class, 'store'])->middleware('permission:cgo.featureflag.create')->name('store');
    Route::post('/evaluate', [FeatureFlagController::class, 'evaluate'])->middleware('permission:cgo.featureflag.view')->name('evaluate');
    Route::get('/{featureFlag}', [FeatureFlagController::class, 'show'])->middleware('permission:cgo.featureflag.view')->name('show');
    Route::put('/{featureFlag}', [FeatureFlagController::class, 'update'])->middleware('permission:cgo.featureflag.update')->name('update');
    Route::post('/{featureFlag}/activate', [FeatureFlagController::class, 'activate'])->middleware('permission:cgo.featureflag.update')->name('activate');
    Route::post('/{featureFlag}/deactivate', [FeatureFlagController::class, 'deactivate'])->middleware('permission:cgo.featureflag.update')->name('deactivate');

    Route::post('/{featureFlag}/overrides', [FeatureFlagController::class, 'storeOverride'])->middleware('permission:cgo.featureflag.override')->name('overrides.store');
    Route::delete('/{featureFlag}/overrides/{override}', [FeatureFlagController::class, 'destroyOverride'])->middleware('permission:cgo.featureflag.override')->name('overrides.destroy');
});
