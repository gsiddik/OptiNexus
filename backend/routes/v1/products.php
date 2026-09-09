<?php

use App\Http\Controllers\Api\V1\ProductController;
use Illuminate\Support\Facades\Route;

Route::prefix('products')->name('products.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [ProductController::class, 'index'])->middleware('permission:cgo.product.view')->name('index');
    Route::post('/', [ProductController::class, 'store'])->middleware('permission:cgo.product.create')->name('store');
    Route::get('/{product}', [ProductController::class, 'show'])->middleware('permission:cgo.product.view')->name('show');
    Route::put('/{product}', [ProductController::class, 'update'])->middleware('permission:cgo.product.update')->name('update');

    Route::post('/{product}/activate', [ProductController::class, 'activate'])->middleware('permission:cgo.product.activate')->name('activate');
    Route::post('/{product}/deactivate', [ProductController::class, 'deactivate'])->middleware('permission:cgo.product.deactivate')->name('deactivate');
    Route::post('/{product}/retire', [ProductController::class, 'retire'])->middleware('permission:cgo.product.retire')->name('retire');

    Route::get('/{product}/applications', [ProductController::class, 'applications'])->middleware('permission:cgo.product.view')->name('applications.index');
    Route::post('/{product}/applications/{application}', [ProductController::class, 'attachApplication'])->middleware('permission:cgo.product.application.assign')->name('applications.attach');
    Route::delete('/{product}/applications/{application}', [ProductController::class, 'detachApplication'])->middleware('permission:cgo.product.application.revoke')->name('applications.detach');

    Route::get('/{product}/capabilities', [ProductController::class, 'capabilities'])->middleware('permission:cgo.product.view')->name('capabilities.index');
    Route::post('/{product}/capabilities/{capability}', [ProductController::class, 'attachCapability'])->middleware('permission:cgo.product.capability.assign')->name('capabilities.attach');
    Route::delete('/{product}/capabilities/{capability}', [ProductController::class, 'detachCapability'])->middleware('permission:cgo.product.capability.revoke')->name('capabilities.detach');
});
