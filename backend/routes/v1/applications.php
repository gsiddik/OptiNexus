<?php

use App\Http\Controllers\Api\V1\ApplicationController;
use App\Http\Controllers\Api\V1\CapabilityController;
use Illuminate\Support\Facades\Route;

Route::prefix('applications')->name('applications.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [ApplicationController::class, 'index'])->middleware('permission:cgo.application.view')->name('index');
    Route::post('/', [ApplicationController::class, 'store'])->middleware('permission:cgo.application.create')->name('store');
    Route::get('/{application}', [ApplicationController::class, 'show'])->middleware('permission:cgo.application.view')->name('show');
    Route::put('/{application}', [ApplicationController::class, 'update'])->middleware('permission:cgo.application.update')->name('update');

    Route::post('/{application}/submit', [ApplicationController::class, 'submit'])->middleware('permission:cgo.application.submit')->name('submit');
    Route::post('/{application}/approve', [ApplicationController::class, 'approve'])->middleware('permission:cgo.application.approve')->name('approve');
    Route::post('/{application}/publish', [ApplicationController::class, 'publish'])->middleware('permission:cgo.application.publish')->name('publish');
    Route::post('/{application}/deprecate', [ApplicationController::class, 'deprecate'])->middleware('permission:cgo.application.deprecate')->name('deprecate');
    Route::post('/{application}/retire', [ApplicationController::class, 'retire'])->middleware('permission:cgo.application.retire')->name('retire');

    Route::get('/{application}/permissions', [ApplicationController::class, 'permissions'])->middleware('permission:cgo.permission.view')->name('permissions');

    Route::get('/{application}/capabilities', [CapabilityController::class, 'index'])->middleware('permission:cgo.capability.view')->name('capabilities.index');
    Route::post('/{application}/capabilities', [CapabilityController::class, 'store'])->middleware('permission:cgo.capability.create')->name('capabilities.store');
});
