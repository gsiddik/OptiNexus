<?php

namespace App\Http\Middleware;

use App\Models\ServiceAccount;
use Closure;
use Illuminate\Http\Request;
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
 * Usage: middleware('service_account:authorization.check')
 */
class AuthenticateServiceAccount
{
    public function __construct(private readonly ResourceServer $server) {}

    public function handle(Request $request, Closure $next, string ...$scopes): Response
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

        $serviceAccount->forceFill(['last_used_at' => now()])->saveQuietly();

        $request->attributes->set('service_account', $serviceAccount);
        $request->attributes->set('oauth_client_id', $token->oauth_client_id);

        return $next($request);
    }

    private function deny(string $code, string $message, int $status): Response
    {
        return response()->json([
            'success' => false,
            'error' => ['code' => $code, 'message' => $message, 'details' => null],
        ], $status);
    }
}
