<?php

use App\Http\Controllers\Api\V1\IntegrationController;
use Illuminate\Support\Facades\Route;

Route::prefix('integrations')->name('integrations.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [IntegrationController::class, 'index'])->middleware('permission:cgo.integration.view')->name('index');
    Route::post('/', [IntegrationController::class, 'store'])->middleware('permission:cgo.integration.create')->name('store');
    Route::get('/{integration}', [IntegrationController::class, 'show'])->middleware('permission:cgo.integration.view,integration')->name('show');
    Route::put('/{integration}', [IntegrationController::class, 'update'])->middleware('permission:cgo.integration.update,integration')->name('update');

    Route::post('/{integration}/activate', [IntegrationController::class, 'activate'])->middleware('permission:cgo.integration.activate,integration')->name('activate');
    Route::post('/{integration}/deactivate', [IntegrationController::class, 'deactivate'])->middleware('permission:cgo.integration.activate,integration')->name('deactivate');
    Route::post('/{integration}/test', [IntegrationController::class, 'test'])->middleware('permission:cgo.integration.test,integration')->name('test');
    Route::get('/{integration}/logs', [IntegrationController::class, 'logs'])->middleware('permission:cgo.integration.view,integration')->name('logs');

    Route::post('/{integration}/credentials', [IntegrationController::class, 'storeCredential'])->middleware('permission:cgo.integration.credential.rotate,integration')->name('credentials.store');
    Route::post('/{integration}/credentials/rotate', [IntegrationController::class, 'rotateCredential'])->middleware('permission:cgo.integration.credential.rotate,integration')->name('credentials.rotate');
    Route::post('/{integration}/credentials/revoke', [IntegrationController::class, 'revokeCredential'])->middleware('permission:cgo.integration.credential.rotate,integration')->name('credentials.revoke');
});
