<?php

use App\Http\Controllers\Api\V1\DelegationController;
use Illuminate\Support\Facades\Route;

// Tenant delegation (audit R08/A25), machine-to-machine only: a product backend manages the users and custom roles of ONE
// tenant for ITS OWN application on behalf of an Admin Tenant. Kept apart from the ecosystem admin API (no `ecosystem` gate
// applies here by design: the caller is a service account, and every call is re-verified by DelegationService).
Route::prefix('delegation/tenants/{tenant}')->name('delegation.')->middleware(['service_account:tenant.delegate'])->group(function () {
    Route::get('/catalog', [DelegationController::class, 'catalog'])->name('catalog');

    // Seat budget of this tenant for the calling product (contract: GOVERNANCE_CONTRACTS.md 2d). Read-only; the same figures ride in the
    // `meta.seats` of the users and invitations lists, and every add that would exceed them is refused with a SEAT_* code.
    Route::get('/seats', [DelegationController::class, 'seats'])->name('seats');

    Route::get('/users', [DelegationController::class, 'users'])->name('users.index');
    Route::post('/users', [DelegationController::class, 'addUser'])->name('users.store');
    Route::put('/users/{user}/roles', [DelegationController::class, 'setUserRoles'])->name('users.roles');
    Route::delete('/users/{user}', [DelegationController::class, 'revokeUser'])->name('users.revoke');

    // Onboarding by invitation (owner decision 2026-10-18): the normal way to add a new person. Contract: GOVERNANCE_CONTRACTS.md 2b.
    Route::get('/invitations', [DelegationController::class, 'invitations'])->name('invitations.index');
    Route::post('/invitations', [DelegationController::class, 'createInvitation'])->name('invitations.store');
    Route::post('/invitations/{invitation}/resend', [DelegationController::class, 'resendInvitation'])->name('invitations.resend');
    Route::post('/invitations/{invitation}/revoke', [DelegationController::class, 'revokeInvitation'])->name('invitations.revoke');

    Route::post('/roles', [DelegationController::class, 'createRole'])->name('roles.store');
    Route::put('/roles/{role}', [DelegationController::class, 'updateRole'])->name('roles.update');
    Route::delete('/roles/{role}', [DelegationController::class, 'deleteRole'])->name('roles.destroy');
});
