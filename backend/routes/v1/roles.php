<?php

use App\Http\Controllers\Api\V1\RoleController;
use Illuminate\Support\Facades\Route;

Route::prefix('roles')->name('roles.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [RoleController::class, 'index'])->middleware('permission:cgo.role.view')->name('index');
    Route::post('/', [RoleController::class, 'store'])->middleware('permission:cgo.role.create')->name('store');
    Route::get('/{role}', [RoleController::class, 'show'])->middleware('permission:cgo.role.view,role')->name('show');
    Route::put('/{role}', [RoleController::class, 'update'])->middleware('permission:cgo.role.update,role')->name('update');

    Route::post('/{role}/clone', [RoleController::class, 'clone'])->middleware('permission:cgo.role.clone,role')->name('clone');
    Route::post('/{role}/disable', [RoleController::class, 'disable'])->middleware('permission:cgo.role.update,role')->name('disable');

    Route::get('/{role}/permissions', [RoleController::class, 'permissions'])->middleware('permission:cgo.role.view,role')->name('permissions.index');
    Route::post('/{role}/permissions/{permission}', [RoleController::class, 'attachPermission'])->middleware('permission:cgo.role.permission.grant,role')->name('permissions.attach');
    Route::delete('/{role}/permissions/{permission}', [RoleController::class, 'detachPermission'])->middleware('permission:cgo.role.permission.grant,role')->name('permissions.detach');
});
