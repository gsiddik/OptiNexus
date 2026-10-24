<?php

use App\Http\Controllers\Api\V1\AuthorizationController;
use Illuminate\Support\Facades\Route;

// Machine-to-machine only: integrated applications call this with a
// client-credentials token carrying the `authorization.check` scope.
Route::post('/authorization/check', [AuthorizationController::class, 'check'])
    ->middleware(['service_account:authorization.check', 'service_account.tenant'])
    ->name('authorization.check');

// One request for everything a user holds in a tenant, for the application the calling service account is bound to
// (replaces one /authorization/check per catalog permission; the individual endpoint above stays for compatibility).
Route::post('/authorization/effective-permissions', [AuthorizationController::class, 'effectivePermissions'])
    ->middleware(['service_account:authorization.check'])
    ->name('authorization.effective-permissions');

// The products a person may switch to from the caller's application, in the tenant they are using, and a revalidation of the
// caller's own session (audit A29). Governance portal never listed.
Route::post('/authorization/product-context', [AuthorizationController::class, 'productContext'])
    ->middleware(['service_account:authorization.check'])
    ->name('authorization.product-context');
