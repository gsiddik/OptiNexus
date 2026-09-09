<?php

use App\Http\Controllers\Api\V1\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('workflows')->name('workflows.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [WorkflowController::class, 'index'])->middleware('permission:cgo.workflow.view')->name('index');
    Route::post('/', [WorkflowController::class, 'store'])->middleware('permission:cgo.workflow.create')->name('store');
    Route::get('/{workflow}', [WorkflowController::class, 'show'])->middleware('permission:cgo.workflow.view,workflow')->name('show');
    Route::put('/{workflow}', [WorkflowController::class, 'update'])->middleware('permission:cgo.workflow.update,workflow')->name('update');

    Route::post('/{workflow}/clone', [WorkflowController::class, 'clone'])->middleware('permission:cgo.workflow.create,workflow')->name('clone');
    Route::post('/{workflow}/validate', [WorkflowController::class, 'validateDefinition'])->middleware('permission:cgo.workflow.update,workflow')->name('validate');
    Route::post('/{workflow}/activate', [WorkflowController::class, 'activate'])->middleware('permission:cgo.workflow.activate,workflow')->name('activate');
    Route::post('/{workflow}/deactivate', [WorkflowController::class, 'deactivate'])->middleware('permission:cgo.workflow.activate,workflow')->name('deactivate');
    Route::post('/{workflow}/execute', [WorkflowController::class, 'execute'])->middleware('permission:cgo.workflow.execute,workflow')->name('execute');
});
