<?php

use App\Http\Controllers\Api\V1\AuditLogController;
use Illuminate\Support\Facades\Route;

Route::prefix('audit-logs')->name('audit-logs.')->middleware('auth:sanctum')->group(function () {
    Route::get('/', [AuditLogController::class, 'index'])->middleware('permission:cgo.audit.view')->name('index');
    Route::get('/{auditLog}', [AuditLogController::class, 'show'])->middleware('permission:cgo.audit.view')->name('show');
});

// Machine-to-machine only: trusted integrated applications submit audit
// events for actions they perform in their own systems. Never exposes
// PUT/DELETE - audit history is immutable through every normal API path.
Route::post('/audit-events', [AuditLogController::class, 'storeEvent'])
    ->middleware(['service_account:audit.write'])
    ->name('audit-events.store');
