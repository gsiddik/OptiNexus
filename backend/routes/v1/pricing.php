<?php

use App\Http\Controllers\Api\V1\PriceController;
use App\Http\Controllers\Api\V1\PricingSimulationController;
use Illuminate\Support\Facades\Route;

Route::prefix('prices')->name('prices.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [PriceController::class, 'index'])->middleware('permission:cgo.pricing.view')->name('index');
    Route::post('/', [PriceController::class, 'store'])->middleware('permission:cgo.pricing.create')->name('store');
    Route::get('/{price}', [PriceController::class, 'show'])->middleware('permission:cgo.pricing.view')->name('show');
    Route::put('/{price}', [PriceController::class, 'update'])->middleware('permission:cgo.pricing.update')->name('update');
    Route::post('/{price}/activate', [PriceController::class, 'activate'])->middleware('permission:cgo.pricing.activate')->name('activate');
    Route::post('/{price}/retire', [PriceController::class, 'retire'])->middleware('permission:cgo.pricing.retire')->name('retire');
});

Route::prefix('pricing')->name('pricing.')->middleware('auth:sanctum')->group(function () {
    Route::post('/simulate', [PricingSimulationController::class, 'simulate'])->middleware('permission:cgo.pricing.simulate')->name('simulate');
    Route::post('/overrides', [PriceController::class, 'storeOverride'])->middleware('permission:cgo.pricing.override')->name('overrides');
});
