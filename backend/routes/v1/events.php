<?php

use App\Http\Controllers\Api\V1\EventCatalogController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\EventDeliveryController;
use Illuminate\Support\Facades\Route;

Route::prefix('event-catalog')->name('event-catalog.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [EventCatalogController::class, 'index'])->middleware('permission:cgo.event.catalog.view')->name('index');
    Route::post('/', [EventCatalogController::class, 'store'])->middleware('permission:cgo.event.catalog.manage')->name('store');
    Route::get('/{event}', [EventCatalogController::class, 'show'])->middleware('permission:cgo.event.catalog.view')->name('show');
    Route::put('/{event}', [EventCatalogController::class, 'update'])->middleware('permission:cgo.event.catalog.manage')->name('update');
});

// Machine-to-machine only: trusted integrated applications report facts.
Route::post('/events', [EventController::class, 'store'])
    ->middleware(['service_account:event.write'])
    ->name('events.store');

Route::prefix('event-deliveries')->name('event-deliveries.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [EventDeliveryController::class, 'index'])->middleware('permission:cgo.event.delivery.view')->name('index');
    Route::get('/{delivery}', [EventDeliveryController::class, 'show'])->middleware('permission:cgo.event.delivery.view')->name('show');
    Route::post('/{delivery}/retry', [EventDeliveryController::class, 'retry'])->middleware('permission:cgo.event.delivery.retry')->name('retry');
    Route::post('/{delivery}/discard', [EventDeliveryController::class, 'discard'])->middleware('permission:cgo.event.delivery.discard')->name('discard');
});
