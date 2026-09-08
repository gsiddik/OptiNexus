<?php

use App\Http\Controllers\Api\V1\CustomerController;
use Illuminate\Support\Facades\Route;

Route::prefix('customers')->name('customers.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [CustomerController::class, 'index'])->middleware('permission:cgo.customer.view')->name('index');
    Route::post('/', [CustomerController::class, 'store'])->middleware('permission:cgo.customer.create')->name('store');
    Route::get('/{customer}', [CustomerController::class, 'show'])->middleware('permission:cgo.customer.view')->name('show');
    Route::put('/{customer}', [CustomerController::class, 'update'])->middleware('permission:cgo.customer.update')->name('update');
    Route::patch('/{customer}/status', [CustomerController::class, 'updateStatus'])->middleware('permission:cgo.customer.update')->name('status');
    Route::post('/{customer}/activate', [CustomerController::class, 'activate'])->middleware('permission:cgo.customer.activate')->name('activate');
    Route::post('/{customer}/suspend', [CustomerController::class, 'suspend'])->middleware('permission:cgo.customer.suspend')->name('suspend');
    Route::post('/{customer}/terminate', [CustomerController::class, 'terminate'])->middleware('permission:cgo.customer.terminate')->name('terminate');
    Route::post('/{customer}/archive', [CustomerController::class, 'archive'])->middleware('permission:cgo.customer.archive')->name('archive');
});
