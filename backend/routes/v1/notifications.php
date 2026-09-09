<?php

use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\NotificationRuleController;
use App\Http\Controllers\Api\V1\NotificationTemplateController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::prefix('notification-templates')->name('notification-templates.')->group(function () {
        Route::get('/', [NotificationTemplateController::class, 'index'])->middleware('permission:cgo.notification.template.manage')->name('index');
        Route::post('/', [NotificationTemplateController::class, 'store'])->middleware('permission:cgo.notification.template.manage')->name('store');
        Route::get('/{notificationTemplate}', [NotificationTemplateController::class, 'show'])->middleware('permission:cgo.notification.template.manage')->name('show');
        Route::put('/{notificationTemplate}', [NotificationTemplateController::class, 'update'])->middleware('permission:cgo.notification.template.manage')->name('update');
    });

    Route::prefix('notification-rules')->name('notification-rules.')->group(function () {
        Route::get('/', [NotificationRuleController::class, 'index'])->middleware('permission:cgo.notification.rule.manage')->name('index');
        Route::post('/', [NotificationRuleController::class, 'store'])->middleware('permission:cgo.notification.rule.manage')->name('store');
        Route::get('/{notificationRule}', [NotificationRuleController::class, 'show'])->middleware('permission:cgo.notification.rule.manage,notificationRule')->name('show');
        Route::put('/{notificationRule}', [NotificationRuleController::class, 'update'])->middleware('permission:cgo.notification.rule.manage,notificationRule')->name('update');
    });

    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->middleware('permission:cgo.notification.delivery.view')->name('index');
        Route::post('/send', [NotificationController::class, 'send'])->middleware('permission:cgo.notification.send')->name('send');
        Route::get('/{notification}', [NotificationController::class, 'show'])->middleware('permission:cgo.notification.delivery.view,notification')->name('show');
        Route::post('/{notification}/retry', [NotificationController::class, 'retry'])->middleware('permission:cgo.notification.delivery.retry,notification')->name('retry');
        Route::post('/{notification}/cancel', [NotificationController::class, 'cancel'])->middleware('permission:cgo.notification.delivery.retry,notification')->name('cancel');
    });
});
