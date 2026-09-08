<?php

use App\Http\Controllers\Api\V1\TenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('tenants')->name('tenants.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [TenantController::class, 'index'])->middleware('permission:cgo.tenant.view')->name('index');
    Route::post('/', [TenantController::class, 'store'])->middleware('permission:cgo.tenant.create')->name('store');
    Route::get('/{tenant}', [TenantController::class, 'show'])->middleware('permission:cgo.tenant.view,tenant')->name('show');
    Route::put('/{tenant}', [TenantController::class, 'update'])->middleware('permission:cgo.tenant.update,tenant')->name('update');

    Route::post('/{tenant}/provision', [TenantController::class, 'provision'])->middleware('permission:cgo.tenant.provision,tenant')->name('provision');
    Route::post('/{tenant}/activate', [TenantController::class, 'activate'])->middleware('permission:cgo.tenant.activate,tenant')->name('activate');
    Route::post('/{tenant}/suspend', [TenantController::class, 'suspend'])->middleware('permission:cgo.tenant.suspend,tenant')->name('suspend');
    Route::post('/{tenant}/reactivate', [TenantController::class, 'reactivate'])->middleware('permission:cgo.tenant.activate,tenant')->name('reactivate');
    Route::post('/{tenant}/terminate', [TenantController::class, 'terminate'])->middleware('permission:cgo.tenant.terminate,tenant')->name('terminate');
    Route::post('/{tenant}/archive', [TenantController::class, 'archive'])->middleware('permission:cgo.tenant.archive,tenant')->name('archive');

    Route::get('/{tenant}/applications', [TenantController::class, 'applications'])->middleware('permission:cgo.tenant.view,tenant')->name('applications.index');
    Route::post('/{tenant}/applications/{application}', [TenantController::class, 'attachApplication'])->middleware('permission:cgo.tenant.application.assign,tenant')->name('applications.attach');
    Route::delete('/{tenant}/applications/{application}', [TenantController::class, 'detachApplication'])->middleware('permission:cgo.tenant.application.assign,tenant')->name('applications.detach');

    Route::get('/{tenant}/admins', [TenantController::class, 'admins'])->middleware('permission:cgo.tenant.view,tenant')->name('admins.index');
    Route::post('/{tenant}/admins/{user}', [TenantController::class, 'addAdmin'])->middleware('permission:cgo.tenant.admin.assign,tenant')->name('admins.attach');
    Route::delete('/{tenant}/admins/{user}', [TenantController::class, 'removeAdmin'])->middleware('permission:cgo.tenant.admin.assign,tenant')->name('admins.detach');
});
