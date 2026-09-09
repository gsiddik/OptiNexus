<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Must happen in register(), not boot(): PassportServiceProvider
        // registers its own routes during ITS boot(), which runs before
        // this provider's boot() in the normal provider lifecycle - by
        // then it would be too late to suppress them. CGO only uses the
        // OAuth2 client-credentials grant (M2M service accounts), issued
        // via our own versioned /api/v1/oauth/token route. Passport's
        // built-in interactive routes (authorization_code, device code,
        // session-based /oauth/token, etc.) are unused, unversioned
        // attack surface for this API-only platform and are disabled
        // outright rather than merely left unlinked.
        Passport::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', fn ($request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));

        Passport::enablePasswordGrant(false);

        // Coarse-grained M2M API scopes for integrated applications
        // (OptiFleet, OptiAccounting, VMS, Taxi Management, ...). These sit
        // alongside - not instead of - the fine-grained RBAC permissions
        // resolved per human user by AuthorizationService.
        Passport::tokensCan([
            'authorization.check' => 'Evaluate authorization decisions for a user via POST /authorization/check',
            'governance.read' => 'Read tenant, application, capability, permission and user directory data',
            'audit.write' => 'Submit audit events on behalf of an integrated application',
            'introspect' => 'Validate CGO-issued tokens',
            'entitlement.check' => 'Evaluate entitlement decisions for a tenant via POST /entitlements/check',
            'commercial.read' => "Read a tenant's commercial context (plan, status, entitlements, billing period)",
            'usage.write' => 'Submit metered usage events on behalf of an integrated application',
        ]);

        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));
    }
}
