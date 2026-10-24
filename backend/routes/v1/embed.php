<?php

use App\Http\Controllers\Api\V1\EmbedSessionController;
use Illuminate\Support\Facades\Route;

// Machine-to-machine only: an application opens a short-lived, read-only embed session of another one (OptiDeli -> OptiRadar).
Route::post('/embed/sessions', [EmbedSessionController::class, 'store'])
    ->middleware(['service_account:embed.create', 'service_account.tenant'])
    ->name('embed.sessions.store');
