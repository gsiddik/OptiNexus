<?php

use App\Http\Controllers\Gateway\GatewayController;
use Illuminate\Support\Facades\Route;

// API Gateway: the single entry point for cross-platform data exchange.
// Every route authenticates a client-credentials token (service_account),
// then resolves + verifies the tenant (gateway_tenant). Order matters.
Route::prefix('gateway/v1')->name('gateway.v1.')->group(function () {
    Route::put('/fleet/vehicles', [GatewayController::class, 'publishVehicles'])
        ->middleware(['service_account:gateway.fleet.write', 'gateway_tenant'])->name('fleet.vehicles.publish');

    Route::get('/vehicle-links', [GatewayController::class, 'vehicleLinks'])
        ->middleware(['service_account:gateway.fleet.read', 'gateway_tenant'])->name('vehicle-links.index');

    Route::post('/telematics/odometer-readings', [GatewayController::class, 'ingestReadings'])
        ->middleware(['service_account:gateway.telematics.write', 'gateway_tenant'])->name('telematics.readings.ingest');

    Route::get('/telematics/odometer-readings', [GatewayController::class, 'readings'])
        ->middleware(['service_account:gateway.telematics.read', 'gateway_tenant'])->name('telematics.readings.index');
});
