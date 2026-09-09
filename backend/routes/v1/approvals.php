<?php

use App\Http\Controllers\Api\V1\ApprovalDefinitionController;
use App\Http\Controllers\Api\V1\ApprovalDelegationController;
use App\Http\Controllers\Api\V1\ApprovalRequestController;
use Illuminate\Support\Facades\Route;

Route::prefix('approval-definitions')->name('approval-definitions.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [ApprovalDefinitionController::class, 'index'])->middleware('permission:cgo.approval.definition.view')->name('index');
    Route::post('/', [ApprovalDefinitionController::class, 'store'])->middleware('permission:cgo.approval.definition.manage')->name('store');
    Route::get('/{definition}', [ApprovalDefinitionController::class, 'show'])->middleware('permission:cgo.approval.definition.view')->name('show');
    Route::put('/{definition}', [ApprovalDefinitionController::class, 'update'])->middleware('permission:cgo.approval.definition.manage')->name('update');
});

Route::prefix('approval-requests')->name('approval-requests.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [ApprovalRequestController::class, 'index'])->middleware('permission:cgo.approval.request.view')->name('index');
    Route::get('/{approvalRequest}', [ApprovalRequestController::class, 'show'])->middleware('permission:cgo.approval.request.view,approvalRequest')->name('show');
    Route::post('/{approvalRequest}/approve', [ApprovalRequestController::class, 'approve'])->middleware('permission:cgo.approval.approve,approvalRequest')->name('approve');
    Route::post('/{approvalRequest}/reject', [ApprovalRequestController::class, 'reject'])->middleware('permission:cgo.approval.reject,approvalRequest')->name('reject');
    Route::post('/{approvalRequest}/return', [ApprovalRequestController::class, 'return'])->middleware('permission:cgo.approval.return,approvalRequest')->name('return');
});

Route::prefix('approval-delegations')->name('approval-delegations.')->middleware('auth:sanctum')->group(function () {
    Route::post('/', [ApprovalDelegationController::class, 'store'])->middleware('permission:cgo.approval.delegate')->name('store');
    Route::delete('/{delegation}', [ApprovalDelegationController::class, 'destroy'])->middleware('permission:cgo.approval.delegate')->name('destroy');
});
