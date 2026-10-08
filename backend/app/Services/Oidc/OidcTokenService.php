<?php

namespace App\Services\Oidc;

use App\Models\OidcAccessToken;
use App\Models\OidcAuthorizationCode;
use App\Models\OidcClient;
use App\Models\OidcSession;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues and redeems authorization codes and tokens. Codes and access tokens
 * are random 256-bit values stored only as SHA-256 hashes; codes are single
 * use, enforced under a row lock so two concurrent redemptions cannot both win.
 */
class OidcTokenService
{
    public function __construct(
        private readonly OidcKeyService $keys,
        private readonly SsoAccessResolver $access,
    ) {}

    public function issueCode(OidcClient $client, User $user, Tenant $tenant, array $pending): string
    {
        $code = Str::random(64);

        OidcAuthorizationCode::create([
            'code_hash' => hash('sha256', $code),
            'oidc_client_id' => $client->id,
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'redirect_uri' => $pending['redirect_uri'],
            'scopes' => $pending['scopes'],
            'nonce' => $pending['nonce'] ?? null,
            'code_challenge' => $pending['code_challenge'] ?? null,
            'code_challenge_method' => $pending['code_challenge_method'] ?? null,
            'auth_time' => now()->setTimestamp((int) ($pending['auth_time'] ?? time())),
            'expires_at' => now()->addSeconds(config('oidc.code_ttl_seconds')),
        ]);

        return $code;
    }

    /**
     * @return array{0: ?array, 1: ?string} [token response, OAuth error code]
     */
    public function redeemCode(OidcClient $client, string $code, string $redirectUri, ?string $codeVerifier): array
    {
        return DB::transaction(function () use ($client, $code, $redirectUri, $codeVerifier) {
            $authCode = OidcAuthorizationCode::query()
                ->where('code_hash', hash('sha256', $code))
                ->lockForUpdate()
                ->first();

            if (! $authCode || $authCode->oidc_client_id !== $client->id) {
                return [null, 'invalid_grant'];
            }

            if ($authCode->consumed_at !== null) {
                // RFC 6749 §4.1.2: a replayed code revokes what it already issued.
                OidcAccessToken::query()
                    ->where('authorization_code_id', $authCode->id)
                    ->whereNull('revoked_at')
                    ->update(['revoked_at' => now()]);

                return [null, 'invalid_grant'];
            }

            $authCode->forceFill(['consumed_at' => now()])->save();

            if ($authCode->expires_at->isPast() || $authCode->redirect_uri !== $redirectUri) {
                return [null, 'invalid_grant'];
            }

            if (! $this->verifyPkce($authCode, $codeVerifier)) {
                return [null, 'invalid_grant'];
            }

            $user = $authCode->user;
            $tenant = $authCode->tenant;

            // Access may have been revoked between the login and the redemption.
            if (! $this->access->canAccess($user, $client->application, $tenant)) {
                return [null, 'access_denied'];
            }

            // The sid claim: one entry per application sign-in, used for central logout.
            $session = OidcSession::create([
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'oidc_client_id' => $client->id,
                'expires_at' => now()->addSeconds((int) config('oidc.session_ttl_seconds')),
            ]);

            $plainToken = Str::random(64);
            $ttl = (int) config('oidc.access_token_ttl_seconds');

            OidcAccessToken::create([
                'token_hash' => hash('sha256', $plainToken),
                'oidc_client_id' => $client->id,
                'user_id' => $user->id,
                'tenant_id' => $tenant->id,
                'authorization_code_id' => $authCode->id,
                'oidc_session_id' => $session->id,
                'scopes' => $authCode->scopes,
                'expires_at' => now()->addSeconds($ttl),
            ]);

            $idToken = $this->keys->sign($this->idTokenClaims($authCode, $client, $user, $tenant, $plainToken, $session));

            return [[
                'access_token' => $plainToken,
                'token_type' => 'Bearer',
                'expires_in' => $ttl,
                'id_token' => $idToken,
                'scope' => implode(' ', $authCode->scopes),
            ], null];
        });
    }

    public function findUsableAccessToken(string $plainToken): ?OidcAccessToken
    {
        $token = OidcAccessToken::query()->where('token_hash', hash('sha256', $plainToken))->first();

        return $token?->isUsable() ? $token : null;
    }

    /**
     * Identity claims shared by the id_token and the userinfo response.
     */
    public function userClaims(User $user, Tenant $tenant, array $scopes): array
    {
        $claims = ['sub' => $user->id];

        if (in_array('email', $scopes, true)) {
            $claims['email'] = $user->email;
            $claims['email_verified'] = $user->email_verified_at !== null;
        }

        if (in_array('profile', $scopes, true)) {
            $claims['name'] = $user->name;
            $claims['preferred_username'] = $user->email;
        }

        // Tenant context is always included: every relying party is
        // multi-tenant and must never guess which tenant the login is for.
        $claims['tenant_id'] = $tenant->id;
        $claims['tenant_code'] = $tenant->tenant_code;
        $claims['tenant_name'] = $tenant->name;

        $apps = $this->access->launchableApplications($user, $tenant);
        $claims['groups'] = array_column($apps, 'code');
        $claims['apps'] = $apps;

        return $claims;
    }

    private function idTokenClaims(OidcAuthorizationCode $code, OidcClient $client, User $user, Tenant $tenant, string $accessToken, OidcSession $session): array
    {
        $now = time();
        $claims = [
            'iss' => config('oidc.issuer'),
            'aud' => $client->client_id,
            'iat' => $now,
            'exp' => $now + (int) config('oidc.id_token_ttl_seconds'),
            'auth_time' => $code->auth_time->getTimestamp(),
            'sid' => $session->id,
            // OIDC Core §3.1.3.6: left half of SHA-256 of the access token.
            'at_hash' => OidcKeyService::base64Url(substr(hash('sha256', $accessToken, true), 0, 16)),
        ] + $this->userClaims($user, $tenant, $code->scopes);

        if ($code->nonce !== null) {
            $claims['nonce'] = $code->nonce;
        }

        return $claims;
    }

    private function verifyPkce(OidcAuthorizationCode $code, ?string $verifier): bool
    {
        if ($code->code_challenge === null) {
            return true;
        }

        if ($verifier === null || ! preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verifier)) {
            return false;
        }

        $computed = OidcKeyService::base64Url(hash('sha256', $verifier, true));

        return hash_equals($code->code_challenge, $computed);
    }
}
