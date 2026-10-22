<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Price;
use App\Models\Tenant;
use App\Models\User;
use App\Observers\OperationalMailObserver;
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

        // Signing keys may live on a persistent volume (containers): PASSPORT_KEY_PATH names the directory that holds
        // oauth-private.key / oauth-public.key. Unset = storage/ as before.
        if ($keyPath = env('PASSPORT_KEY_PATH')) {
            Passport::loadKeysFrom($keyPath);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([Customer::class, Tenant::class, User::class, Invoice::class, Price::class] as $model) {
            $model::observe(OperationalMailObserver::class);
        }
        // Human (interactive) API routes: 120/min per user, or per address when not signed in. Machine-to-machine routes
        // (`service_account:` middleware) are throttled per authenticated OAuth client inside that middleware instead
        // (config/m2m.php): a shared proxy address must not pool every product's server-to-server traffic into one bucket.
        RateLimiter::for('api', function ($request) {
            if (collect($request->route()?->gatherMiddleware() ?? [])->contains(fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'service_account'))) {
                return Limit::none();
            }

            return Limit::perMinute(120)->by($request->user()?->id ?: $request->ip());
        });

        // Invitation pages (owner decision 2026-10-18). Named limiters with their own keys: the plain `throttle:N,M` form shares one counter per
        // address across every route that uses it, which would let invitation traffic eat the sign-in budget (and the other way round).
        RateLimiter::for('invitation-open', fn ($request) => Limit::perMinute(30)->by('invitation-open:'.$request->ip()));
        RateLimiter::for('invitation-view', fn ($request) => Limit::perMinute(60)->by('invitation-view:'.$request->ip()));
        RateLimiter::for('invitation-submit', fn ($request) => [
            Limit::perMinute(10)->by('invitation-submit:'.$request->ip()),
            Limit::perMinute(20)->by('invitation-submit-id:'.($request->session()->get('invitation.id') ?? $request->ip())),
        ]);

        // First Admin Tenant: the password-change page (owner decision 2026-10-18, second batch). Its own counter, so it cannot eat the sign-in budget.
        RateLimiter::for('activation-submit', fn ($request) => Limit::perMinute(10)->by('activation-submit:'.$request->ip()));

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
            'commercial.payment.submit' => 'Submit tenant invoice payments with an authorized acting user',
            'commercial.read' => "Read a tenant's commercial context (plan, status, entitlements, billing period)",
            'usage.write' => 'Submit metered usage events on behalf of an integrated application',
            'event.write' => 'Submit orchestration events (facts) on behalf of an integrated application',
            'gateway.fleet.write' => 'Publish the fleet vehicle directory to the API Gateway',
            'gateway.fleet.read' => 'Read vehicle <-> telematics device links through the API Gateway',
            'gateway.telematics.write' => 'Push telematics odometer readings into the API Gateway',
            'gateway.telematics.read' => 'Read the telematics odometer feed for linked vehicles from the API Gateway',
            'tenant.delegate' => "Manage a tenant's users and custom roles for the caller's own application on behalf of an Admin Tenant (delegation API)",
            'access.evaluate' => 'Evaluate aggregated effective access (entitlement + permission + policy + feature flag) via POST /access/evaluate',
        ]);

        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));
    }
}
