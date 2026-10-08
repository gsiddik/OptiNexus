<?php

use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('users')->name('users.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [UserController::class, 'index'])->middleware('permission:cgo.user.view')->name('index');
    Route::post('/', [UserController::class, 'store'])->middleware('permission:cgo.user.create')->name('store');
    Route::get('/{user}', [UserController::class, 'show'])->middleware('permission:cgo.user.view')->name('show');
    Route::put('/{user}', [UserController::class, 'update'])->middleware('permission:cgo.user.update')->name('update');

    Route::post('/{user}/activate', [UserController::class, 'activate'])->middleware('permission:cgo.user.activate')->name('activate');
    Route::post('/{user}/suspend', [UserController::class, 'suspend'])->middleware('permission:cgo.user.suspend')->name('suspend');
    Route::post('/{user}/disable', [UserController::class, 'disable'])->middleware('permission:cgo.user.disable')->name('disable');

    Route::post('/{user}/force-logout', [UserController::class, 'forceLogout'])->middleware('permission:cgo.user.session.revoke')->name('force-logout');

    Route::get('/{user}/tenants', [UserController::class, 'tenants'])->middleware('permission:cgo.user.view')->name('tenants.index');
    Route::post('/{user}/tenants/{tenant}', [UserController::class, 'attachTenant'])->middleware('permission:cgo.user.tenant.assign,tenant')->name('tenants.attach');
    Route::delete('/{user}/tenants/{tenant}', [UserController::class, 'detachTenant'])->middleware('permission:cgo.user.tenant.assign,tenant')->name('tenants.detach');

    Route::get('/{user}/applications', [UserController::class, 'applications'])->middleware('permission:cgo.user.view')->name('applications.index');
    Route::post('/{user}/applications/{application}', [UserController::class, 'attachApplication'])->middleware('permission:cgo.user.application.assign,tenant_id')->name('applications.attach');
    Route::delete('/{user}/applications/{application}', [UserController::class, 'detachApplication'])->middleware('permission:cgo.user.application.assign,tenant_id')->name('applications.detach');

    Route::get('/{user}/roles', [UserController::class, 'roles'])->middleware('permission:cgo.user.view')->name('roles.index');
    Route::post('/{user}/roles/{role}', [UserController::class, 'attachRole'])->middleware('permission:cgo.user.role.assign,tenant_id')->name('roles.attach');
    Route::delete('/{user}/roles/{role}', [UserController::class, 'detachRole'])->middleware('permission:cgo.user.role.assign,tenant_id')->name('roles.detach');

    Route::get('/{user}/effective-permissions', [UserController::class, 'effectivePermissions'])->middleware('permission:cgo.user.view')->name('effective-permissions');
});
