<?php

use App\Http\Controllers\Api\V1\PlanController;
use Illuminate\Support\Facades\Route;

Route::prefix('plans')->name('plans.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [PlanController::class, 'index'])->middleware('permission:cgo.plan.view')->name('index');
    Route::post('/', [PlanController::class, 'store'])->middleware('permission:cgo.plan.create')->name('store');
    Route::get('/{plan}', [PlanController::class, 'show'])->middleware('permission:cgo.plan.view')->name('show');
    Route::put('/{plan}', [PlanController::class, 'update'])->middleware('permission:cgo.plan.update')->name('update');

    Route::post('/{plan}/clone', [PlanController::class, 'clone'])->middleware('permission:cgo.plan.clone')->name('clone');
    Route::post('/{plan}/activate', [PlanController::class, 'activate'])->middleware('permission:cgo.plan.activate')->name('activate');
    Route::post('/{plan}/deactivate', [PlanController::class, 'deactivate'])->middleware('permission:cgo.plan.deactivate')->name('deactivate');
    Route::post('/{plan}/retire', [PlanController::class, 'retire'])->middleware('permission:cgo.plan.retire')->name('retire');

    Route::get('/{plan}/capabilities', [PlanController::class, 'capabilities'])->middleware('permission:cgo.plan.view')->name('capabilities.index');
    Route::post('/{plan}/capabilities/{capability}', [PlanController::class, 'attachCapability'])->middleware('permission:cgo.plan.capability.assign')->name('capabilities.attach');
    Route::delete('/{plan}/capabilities/{capability}', [PlanController::class, 'detachCapability'])->middleware('permission:cgo.plan.capability.revoke')->name('capabilities.detach');

    Route::get('/{plan}/limits', [PlanController::class, 'limits'])->middleware('permission:cgo.plan.view')->name('limits.index');
    Route::post('/{plan}/limits', [PlanController::class, 'configureLimit'])->middleware('permission:cgo.plan.limit.configure')->name('limits.configure');
    Route::delete('/{plan}/limits/{limit}', [PlanController::class, 'removeLimit'])->middleware('permission:cgo.plan.limit.configure')->name('limits.remove');
});
