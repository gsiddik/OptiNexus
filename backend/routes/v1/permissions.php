<?php

use App\Http\Controllers\Api\V1\PermissionController;
use Illuminate\Support\Facades\Route;

Route::prefix('permissions')->name('permissions.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [PermissionController::class, 'index'])->middleware('permission:cgo.permission.view')->name('index');
    Route::post('/', [PermissionController::class, 'store'])->middleware('permission:cgo.permission.create')->name('store');
    Route::get('/{permission}', [PermissionController::class, 'show'])->middleware('permission:cgo.permission.view')->name('show');
    Route::put('/{permission}', [PermissionController::class, 'update'])->middleware('permission:cgo.permission.update')->name('update');
    Route::post('/{permission}/deprecate', [PermissionController::class, 'deprecate'])->middleware('permission:cgo.permission.update')->name('deprecate');
});
