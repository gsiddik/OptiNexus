<?php

use Illuminate\Support\Facades\Route;

// Phase 1 governance API. All routes are prefixed with /api/v1 and versioned
// so future breaking changes ship as /api/v2 without disturbing integrators.
Route::prefix('v1')->name('api.v1.')->group(function () {
    require __DIR__.'/v1/auth.php';
    require __DIR__.'/v1/customers.php';
    require __DIR__.'/v1/tenants.php';
    require __DIR__.'/v1/applications.php';
    require __DIR__.'/v1/capabilities.php';
    require __DIR__.'/v1/permissions.php';
    require __DIR__.'/v1/roles.php';
    require __DIR__.'/v1/users.php';
    require __DIR__.'/v1/authorization.php';
    require __DIR__.'/v1/audit.php';
    require __DIR__.'/v1/service_accounts.php';

    // Phase 2 SaaS commercial core.
    require __DIR__.'/v1/products.php';
    require __DIR__.'/v1/plans.php';
    require __DIR__.'/v1/addons.php';
    require __DIR__.'/v1/pricing.php';
    require __DIR__.'/v1/subscriptions.php';
    require __DIR__.'/v1/entitlements.php';
    require __DIR__.'/v1/usage.php';
    require __DIR__.'/v1/billing.php';
    require __DIR__.'/v1/invoices.php';
});
