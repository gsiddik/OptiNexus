<?php

use App\Http\Controllers\Api\V1\GatewayAdminController;
use Illuminate\Support\Facades\Route;

// Human-admin side of the API Gateway (the machine side is routes/api_gateway.php).
Route::prefix('gateway/tenants/{tenant}')->name('gateway.admin.')->middleware('auth:sanctum')->group(function () {
    Route::get('/vehicle-links', [GatewayAdminController::class, 'links'])->middleware('permission:cgo.gateway.link.view,tenant')->name('links.index');
    Route::put('/vehicle-links/{link}', [GatewayAdminController::class, 'link'])->middleware('permission:cgo.gateway.link.manage,tenant')->name('links.update');
    Route::delete('/vehicle-links/{link}', [GatewayAdminController::class, 'unlink'])->middleware('permission:cgo.gateway.link.manage,tenant')->name('links.destroy');
    Route::get('/fleet-vehicles', [GatewayAdminController::class, 'vehicles'])->middleware('permission:cgo.gateway.link.view,tenant')->name('vehicles.index');
    Route::get('/request-logs', [GatewayAdminController::class, 'logs'])->middleware('permission:cgo.gateway.log.view,tenant')->name('logs.index');
});
