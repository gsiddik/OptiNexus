<?php

use App\Http\Controllers\Api\V1\AddonController;
use Illuminate\Support\Facades\Route;

Route::prefix('addons')->name('addons.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [AddonController::class, 'index'])->middleware('permission:cgo.addon.view')->name('index');
    Route::post('/', [AddonController::class, 'store'])->middleware('permission:cgo.addon.create')->name('store');
    Route::get('/{addon}', [AddonController::class, 'show'])->middleware('permission:cgo.addon.view')->name('show');
    Route::put('/{addon}', [AddonController::class, 'update'])->middleware('permission:cgo.addon.update')->name('update');

    Route::post('/{addon}/activate', [AddonController::class, 'activate'])->middleware('permission:cgo.addon.activate')->name('activate');
    Route::post('/{addon}/deactivate', [AddonController::class, 'deactivate'])->middleware('permission:cgo.addon.deactivate')->name('deactivate');
    Route::post('/{addon}/retire', [AddonController::class, 'retire'])->middleware('permission:cgo.addon.retire')->name('retire');

    Route::get('/{addon}/capabilities', [AddonController::class, 'capabilities'])->middleware('permission:cgo.addon.view')->name('capabilities.index');
    Route::post('/{addon}/capabilities/{capability}', [AddonController::class, 'attachCapability'])->middleware('permission:cgo.addon.capability.assign')->name('capabilities.attach');
    Route::delete('/{addon}/capabilities/{capability}', [AddonController::class, 'detachCapability'])->middleware('permission:cgo.addon.capability.revoke')->name('capabilities.detach');

    Route::get('/{addon}/limits', [AddonController::class, 'limits'])->middleware('permission:cgo.addon.view')->name('limits.index');
    Route::post('/{addon}/limits', [AddonController::class, 'configureLimit'])->middleware('permission:cgo.addon.limit.configure')->name('limits.configure');
});
