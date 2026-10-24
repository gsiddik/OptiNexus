<?php

use App\Http\Controllers\Api\V1\EmbedSessionController;
use App\Http\Controllers\Api\V1\RadarPositionController;
use Illuminate\Support\Facades\Route;

// Machine-to-machine only: an application opens a short-lived, read-only embed session of another one (OptiDeli -> OptiRadar).
Route::post('/embed/sessions', [EmbedSessionController::class, 'store'])
    ->middleware(['service_account:embed.create', 'service_account.tenant'])
    ->name('embed.sessions.store');

// Machine-to-machine only: the latest OptiRadar positions of the bound tenant, for an application entitled to OptiRadar for it.
Route::get('/radar/positions', [RadarPositionController::class, 'index'])
    ->middleware(['service_account:positions.read', 'service_account.tenant'])
    ->name('radar.positions.index');
