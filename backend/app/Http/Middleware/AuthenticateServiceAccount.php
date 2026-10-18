<?php

namespace App\Http\Middleware;

use App\Models\ServiceAccount;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\AccessToken;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\ResourceServer;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;

/**
 * Machine-to-machine authentication for integration routes
 * (POST /authorization/check, POST /audit-events, ...).
 *
 * Passport 13 validates bearer tokens per-route via its ValidateToken
 * middleware family but does not expose the resolved client identity to
 * the request, and has no dedicated "client-credentials only" + scope +
 * service-account-resolution middleware combined. This does all three in
 * one pass: validates the token, rejects anything that isn't a pure
 * client-credentials grant (no end-user), enforces the required scope, and
 * resolves + attaches the caller's ServiceAccount so controllers never
 * trust client-supplied identity for audit/authorization decisions.
 *
 * Quota: the `api` IP limiter is skipped for these routes (see AppServiceProvider). Authenticated requests are throttled per OAuth
 * client (config/m2m.php), so one product's burst cannot lock another product out through a shared proxy address, and requests that
 * fail authentication are counted per source address, so bad tokens are still throttled.
 *
 * Usage: middleware('service_account:authorization.check')
 */
class AuthenticateServiceAccount
{
    public function __construct(private readonly ResourceServer $server) {}

    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $failureKey = 'm2m-auth-failed:'.$request->ip();
        $failureLimit = (int) config('m2m.failed_auth_per_minute_per_ip');

        if (RateLimiter::tooManyAttempts($failureKey, $failureLimit)) {
            return $this->limited(RateLimiter::availableIn($failureKey), $failureLimit, 0);
        }

        if ($denied = $this->authenticate($request, $scopes)) {
            RateLimiter::hit($failureKey, 60);

            return $denied;
        }

        $clientId = (string) $request->attributes->get('oauth_client_id');
        $quota = $this->quotaFor($scopes);
        $quotaKey = "m2m-client:{$clientId}";

        if (RateLimiter::tooManyAttempts($quotaKey, $quota)) {
            return $this->limited(RateLimiter::availableIn($quotaKey), $quota, 0);
        }

        RateLimiter::hit($quotaKey, 60);

        $response = $next($request);
        $response->headers->add([
            'X-RateLimit-Limit' => (string) $quota,
            'X-RateLimit-Remaining' => (string) RateLimiter::remaining($quotaKey, $quota),
        ]);

        return $response;
    }

    /** Validates token, grant, scopes and service account; on success attaches them to the request and returns null. */
    private function authenticate(Request $request, array $scopes): ?Response
    {
        try {
            $psrRequest = $this->server->validateAuthenticatedRequest((new PsrHttpFactory)->createRequest($request));
        } catch (OAuthServerException) {
            return $this->deny('UNAUTHENTICATED', 'A valid access token is required.', 401);
        }

        $token = AccessToken::fromPsrRequest($psrRequest);

        // League's client-credentials grant sets the token's "subject" to the
        // client's own identifier (there is no end user). A token whose
        // subject differs from its client_id was issued on behalf of a
        // human (authorization_code/password grant) and must be rejected
        // here - these M2M routes only accept pure client-credentials tokens.
        if (! empty($token->oauth_user_id) && $token->oauth_user_id !== $token->oauth_client_id) {
            return $this->deny('UNAUTHORIZED', 'This endpoint requires a client-credentials token.', 403);
        }

        foreach ($scopes as $scope) {
            if ($token->cant($scope)) {
                return $this->deny('UNAUTHORIZED', "The token is missing the required [{$scope}] scope.", 403);
            }
        }

        $serviceAccount = ServiceAccount::query()->where('oauth_client_id', $token->oauth_client_id)->first();

        if (! $serviceAccount || ! $serviceAccount->isActive()) {
            return $this->deny('UNAUTHORIZED', 'This client is not associated with an active service account.', 403);
        }

        foreach ($scopes as $scope) {
            if (! $serviceAccount->allowsScope($scope)) {
                return $this->deny('SCOPE_NOT_ALLOWED', "This service account is not allowed to use the [{$scope}] scope.", 403);
            }
        }

        $serviceAccount->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('service_account', $serviceAccount);
        $request->attributes->set('oauth_client_id', $token->oauth_client_id);

        return null;
    }

    /** The per-client ceiling of a route: the default, or the largest override among the scopes it requires. */
    private function quotaFor(array $scopes): int
    {
        $overrides = (array) config('m2m.scope_rate_per_minute');
        $quota = (int) config('m2m.rate_per_minute');

        foreach ($scopes as $scope) {
            $quota = max($quota, (int) ($overrides[$scope] ?? 0));
        }

        return $quota;
    }

    private function limited(int $retryAfter, int $limit, int $remaining): Response
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => 'RATE_LIMITED', 'message' => 'Too many requests.', 'details' => ['retry_after_seconds' => $retryAfter]],
        ], 429, [
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => (string) $limit,
            'X-RateLimit-Remaining' => (string) $remaining,
        ]);
    }

    private function deny(string $code, string $message, int $status): Response
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => $code, 'message' => $message, 'details' => null],
        ], $status);
    }
}
