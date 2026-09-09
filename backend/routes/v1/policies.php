<?php

use App\Http\Controllers\Api\V1\PolicyController;
use Illuminate\Support\Facades\Route;

Route::prefix('policies')->name('policies.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [PolicyController::class, 'index'])->middleware('permission:cgo.policy.view')->name('index');
    Route::post('/', [PolicyController::class, 'store'])->middleware('permission:cgo.policy.create')->name('store');
    Route::post('/simulate', [PolicyController::class, 'simulate'])->middleware('permission:cgo.policy.simulate')->name('simulate');
    Route::get('/{policy}', [PolicyController::class, 'show'])->middleware('permission:cgo.policy.view,policy')->name('show');
    Route::put('/{policy}', [PolicyController::class, 'update'])->middleware('permission:cgo.policy.update,policy')->name('update');

    Route::post('/{policy}/activate', [PolicyController::class, 'activate'])->middleware('permission:cgo.policy.activate,policy')->name('activate');
    Route::post('/{policy}/deactivate', [PolicyController::class, 'deactivate'])->middleware('permission:cgo.policy.deactivate,policy')->name('deactivate');
    Route::post('/{policy}/deprecate', [PolicyController::class, 'deprecate'])->middleware('permission:cgo.policy.deprecate,policy')->name('deprecate');
    Route::post('/{policy}/clone', [PolicyController::class, 'clone'])->middleware('permission:cgo.policy.create,policy')->name('clone');
});
