<?php

use App\Http\Controllers\Api\V1\WorkflowInstanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('workflow-instances')->name('workflow-instances.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [WorkflowInstanceController::class, 'index'])->middleware('permission:cgo.workflow.view')->name('index');
    Route::get('/{instance}', [WorkflowInstanceController::class, 'show'])->middleware('permission:cgo.workflow.view,instance')->name('show');
    Route::post('/{instance}/retry', [WorkflowInstanceController::class, 'retry'])->middleware('permission:cgo.workflow.retry,instance')->name('retry');
    Route::post('/{instance}/cancel', [WorkflowInstanceController::class, 'cancel'])->middleware('permission:cgo.workflow.cancel,instance')->name('cancel');
});
