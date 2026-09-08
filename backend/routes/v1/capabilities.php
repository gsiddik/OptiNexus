<?php

use App\Http\Controllers\Api\V1\CapabilityController;
use Illuminate\Support\Facades\Route;

Route::prefix('capabilities')->name('capabilities.')->middleware('auth:sanctum')->group(function () {
    Route::get('/{capability}', [CapabilityController::class, 'show'])->middleware('permission:cgo.capability.view')->name('show');
    Route::put('/{capability}', [CapabilityController::class, 'update'])->middleware('permission:cgo.capability.update')->name('update');
    Route::delete('/{capability}', [CapabilityController::class, 'destroy'])->middleware('permission:cgo.capability.delete')->name('destroy');

    Route::post('/{capability}/move', [CapabilityController::class, 'move'])->middleware('permission:cgo.capability.update')->name('move');
    Route::post('/{capability}/reorder', [CapabilityController::class, 'reorder'])->middleware('permission:cgo.capability.update')->name('reorder');
    Route::post('/{capability}/activate', [CapabilityController::class, 'activate'])->middleware('permission:cgo.capability.activate')->name('activate');
    Route::post('/{capability}/disable', [CapabilityController::class, 'disable'])->middleware('permission:cgo.capability.disable')->name('disable');
    Route::post('/{capability}/deprecate', [CapabilityController::class, 'deprecate'])->middleware('permission:cgo.capability.deprecate')->name('deprecate');

    Route::get('/{capability}/permissions', [CapabilityController::class, 'permissions'])->middleware('permission:cgo.permission.view')->name('permissions.index');
    Route::post('/{capability}/permissions/{permission}', [CapabilityController::class, 'attachPermission'])->middleware('permission:cgo.capability.permission.map')->name('permissions.attach');
    Route::delete('/{capability}/permissions/{permission}', [CapabilityController::class, 'detachPermission'])->middleware('permission:cgo.capability.permission.map')->name('permissions.detach');
});
