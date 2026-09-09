<?php

use App\Http\Controllers\Api\V1\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('subscriptions')->name('subscriptions.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [SubscriptionController::class, 'index'])->middleware('permission:cgo.subscription.view')->name('index');
    Route::post('/', [SubscriptionController::class, 'store'])->middleware('permission:cgo.subscription.create,tenant_id')->name('store');
    Route::get('/{subscription}', [SubscriptionController::class, 'show'])->middleware('permission:cgo.subscription.view,subscription')->name('show');
    Route::put('/{subscription}', [SubscriptionController::class, 'update'])->middleware('permission:cgo.subscription.update,subscription')->name('update');

    Route::post('/{subscription}/start-trial', [SubscriptionController::class, 'startTrial'])->middleware('permission:cgo.subscription.activate,subscription')->name('start-trial');
    Route::post('/{subscription}/activate', [SubscriptionController::class, 'activate'])->middleware('permission:cgo.subscription.activate,subscription')->name('activate');
    Route::post('/{subscription}/upgrade', [SubscriptionController::class, 'upgrade'])->middleware('permission:cgo.subscription.upgrade,subscription')->name('upgrade');
    Route::post('/{subscription}/downgrade', [SubscriptionController::class, 'downgrade'])->middleware('permission:cgo.subscription.downgrade,subscription')->name('downgrade');
    Route::post('/{subscription}/renew', [SubscriptionController::class, 'renew'])->middleware('permission:cgo.subscription.renew,subscription')->name('renew');
    Route::post('/{subscription}/cancel', [SubscriptionController::class, 'cancel'])->middleware('permission:cgo.subscription.cancel,subscription')->name('cancel');
    Route::post('/{subscription}/suspend', [SubscriptionController::class, 'suspend'])->middleware('permission:cgo.subscription.suspend,subscription')->name('suspend');
    Route::post('/{subscription}/reactivate', [SubscriptionController::class, 'reactivate'])->middleware('permission:cgo.subscription.reactivate,subscription')->name('reactivate');
    Route::post('/{subscription}/expire', [SubscriptionController::class, 'expire'])->middleware('permission:cgo.subscription.terminate,subscription')->name('expire');
    Route::post('/{subscription}/terminate', [SubscriptionController::class, 'terminate'])->middleware('permission:cgo.subscription.terminate,subscription')->name('terminate');
});
