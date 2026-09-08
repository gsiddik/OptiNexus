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
        //
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
        ]);

        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));
    }
}
