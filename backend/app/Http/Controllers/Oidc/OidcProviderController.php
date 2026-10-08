<?php

namespace App\Http\Controllers\Oidc;

use App\Http\Controllers\Controller;
use App\Models\OidcClient;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Oidc\OidcKeyService;
use App\Services\Oidc\OidcTokenService;
use App\Services\Oidc\SsoAccessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * OpenID Connect provider (authorization code flow, optional PKCE).
 *
 * Browser-facing steps (authorize, login, tenant choice, logout) run on the
 * `web` guard's session, which is what makes single sign-on work: after one
 * login, the next application's authorize request is answered immediately.
 * Back-channel steps (token, userinfo) are stateless JSON endpoints.
 */
class OidcProviderController extends Controller
{
    private const PENDING_KEY = 'oidc.pending';

    public function __construct(
        private readonly OidcKeyService $keys,
        private readonly OidcTokenService $tokens,
        private readonly SsoAccessResolver $access,
        private readonly AuditService $audit,
    ) {}

    public function discovery(): JsonResponse
    {
        $issuer = config('oidc.issuer');

        return response()->json([
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer.'/oidc/authorize',
            'token_endpoint' => $issuer.'/api/oidc/token',
            'userinfo_endpoint' => $issuer.'/api/oidc/userinfo',
            'jwks_uri' => $issuer.'/oidc/jwks',
            'end_session_endpoint' => $issuer.'/oidc/logout',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code'],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'scopes_supported' => config('oidc.scopes_supported'),
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post', 'none'],
            'code_challenge_methods_supported' => ['S256'],
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'nonce', 'at_hash', 'email', 'email_verified', 'name', 'preferred_username', 'tenant_id', 'tenant_code', 'tenant_name', 'groups', 'apps'],
            'authorization_response_iss_parameter_supported' => true,
        ]);
    }

    public function jwks(): JsonResponse
    {
        return response()->json($this->keys->jwks())->header('Cache-Control', 'public, max-age=300');
    }

    public function authorize(Request $request): Response|View
    {
        $client = OidcClient::query()->where('client_id', (string) $request->query('client_id'))->first();
        $redirectUri = (string) $request->query('redirect_uri');

        // Without a trusted client + redirect URI there is nowhere safe to
        // send an error, so it is shown here instead (RFC 6749 §4.1.2.1).
        if (! $client || ! $client->isActive() || ! $client->allowsRedirectUri($redirectUri)) {
            return $this->errorPage('The application or its redirect address is not registered with OptiNexus.', 400);
        }

        $state = $request->query('state');

        if ($request->query('response_type') !== 'code') {
            return $this->redirectError($redirectUri, 'unsupported_response_type', $state);
        }

        $scopes = array_values(array_unique(array_filter(explode(' ', (string) $request->query('scope')))));
        if (! in_array('openid', $scopes, true)) {
            return $this->redirectError($redirectUri, 'invalid_scope', $state, 'The openid scope is required.');
        }
        $scopes = array_values(array_intersect($scopes, config('oidc.scopes_supported')));

        $challenge = $request->query('code_challenge');
        $method = $request->query('code_challenge_method');
        if ($challenge !== null && $method !== 'S256') {
            return $this->redirectError($redirectUri, 'invalid_request', $state, 'Only the S256 code challenge method is supported.');
        }
        if ($challenge === null && ($client->require_pkce || ! $client->isConfidential())) {
            return $this->redirectError($redirectUri, 'invalid_request', $state, 'This client must use PKCE (S256).');
        }

        $pending = [
            'client_id' => $client->client_id,
            'redirect_uri' => $redirectUri,
            'scopes' => $scopes,
            'state' => $state,
            'nonce' => $request->query('nonce'),
            'code_challenge' => $challenge,
            'code_challenge_method' => $challenge ? 'S256' : null,
            'tenant_hint' => $request->query('tenant_hint'),
        ];

        $user = Auth::guard('web')->user();
        $prompt = (string) $request->query('prompt');

        if (! $user instanceof User || ! $user->isActive() || $prompt === 'login') {
            if ($prompt === 'none') {
                return $this->redirectError($redirectUri, 'login_required', $state);
            }

            if ($user && $prompt === 'login') {
                $this->endWebSession($request);
            }

            $request->session()->put(self::PENDING_KEY, $pending);

            return redirect()->route('oidc.login');
        }

        $pending['auth_time'] = $request->session()->get('oidc.auth_time', time());

        return $this->continueAuthorization($request, $client, $user, $pending);
    }

    public function showLogin(Request $request): View|Response
    {
        $pending = $request->session()->get(self::PENDING_KEY);
        if (! $pending) {
            return $this->errorPage('Open the application you want to use and choose "Sign in with OptiNexus".', 400);
        }

        $client = OidcClient::query()->with('application')->where('client_id', $pending['client_id'])->first();

        return view('oidc.login', ['applicationName' => $client?->application?->name ?? $client?->name]);
    }

    public function login(Request $request): Response|View
    {
        $pending = $request->session()->get(self::PENDING_KEY);
        if (! $pending) {
            return $this->errorPage('Your sign-in request has expired. Start again from the application.', 400);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! $user->password || ! Hash::check($credentials['password'], $user->password) || ! $user->isActive()) {
            $this->audit->record('sso.login_failed', $request, actorIdentity: $credentials['email'], source: 'oidc');

            return back()->withInput(['email' => $credentials['email']])
                ->withErrors(['email' => 'These credentials do not match an active OptiNexus account.']);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('oidc.auth_time', time());
        $user->forceFill(['last_login_at' => now()])->save();

        $client = OidcClient::query()->where('client_id', $pending['client_id'])->first();
        if (! $client || ! $client->isActive()) {
            return $this->errorPage('The application is no longer registered with OptiNexus.', 400);
        }

        $this->audit->record('sso.login', $request, actor: $user, applicationId: $client->application_id, source: 'oidc');

        $pending['auth_time'] = time();

        return $this->continueAuthorization($request, $client, $user, $pending);
    }

    public function selectTenant(Request $request): Response|View
    {
        $pending = $request->session()->get(self::PENDING_KEY);
        $user = Auth::guard('web')->user();

        if (! $pending || ! $user instanceof User) {
            return $this->errorPage('Your sign-in request has expired. Start again from the application.', 400);
        }

        $client = OidcClient::query()->where('client_id', $pending['client_id'])->first();
        if (! $client || ! $client->isActive()) {
            return $this->errorPage('The application is no longer registered with OptiNexus.', 400);
        }

        $pending['tenant_hint'] = (string) $request->input('tenant_id');
        $pending['auth_time'] = $request->session()->get('oidc.auth_time', time());

        return $this->continueAuthorization($request, $client, $user, $pending);
    }

    public function token(Request $request): JsonResponse
    {
        [$clientId, $clientSecret] = $this->clientCredentials($request);
        $client = $clientId ? OidcClient::query()->where('client_id', $clientId)->first() : null;

        if (! $client || ! $client->isActive()) {
            return $this->oauthError('invalid_client', 401);
        }

        if ($client->isConfidential() && ! $client->verifySecret($clientSecret)) {
            return $this->oauthError('invalid_client', 401);
        }

        if ($request->input('grant_type') !== 'authorization_code') {
            return $this->oauthError('unsupported_grant_type', 400);
        }

        $code = (string) $request->input('code');
        $redirectUri = (string) $request->input('redirect_uri');
        if ($code === '' || $redirectUri === '') {
            return $this->oauthError('invalid_request', 400);
        }

        [$response, $error] = $this->tokens->redeemCode($client, $code, $redirectUri, $request->input('code_verifier'));

        if ($error) {
            return $this->oauthError($error, 400);
        }

        return response()->json($response)->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    public function userinfo(Request $request): JsonResponse
    {
        $plain = $request->bearerToken();
        $token = $plain ? $this->tokens->findUsableAccessToken($plain) : null;

        if (! $token) {
            return response()->json(['error' => 'invalid_token'], 401)
                ->header('WWW-Authenticate', 'Bearer error="invalid_token"');
        }

        $user = $token->user;
        $tenant = $token->tenant;

        // Re-checked on every call so a revoked user or a lapsed subscription
        // stops working at the relying party's next userinfo refresh.
        if (! $this->access->canAccess($user, $token->client->application, $tenant)) {
            return response()->json(['error' => 'invalid_token', 'error_description' => 'Access to this application has been revoked.'], 401)
                ->header('WWW-Authenticate', 'Bearer error="invalid_token"');
        }

        return response()->json($this->tokens->userClaims($user, $tenant, $token->scopes))
            ->header('Cache-Control', 'no-store');
    }

    public function logout(Request $request): Response|View
    {
        $this->endWebSession($request);

        $target = (string) $request->query('post_logout_redirect_uri');
        $clientId = (string) $request->query('client_id');

        if ($target !== '' && $clientId === '' && ($hint = $request->query('id_token_hint'))) {
            $clientId = (string) ($this->keys->verify($hint)['aud'] ?? '');
        }

        $client = $clientId !== '' ? OidcClient::query()->where('client_id', $clientId)->first() : null;

        if ($target !== '' && $client && $client->allowsPostLogoutRedirectUri($target)) {
            $state = $request->query('state');

            return redirect()->away($target.($state ? (str_contains($target, '?') ? '&' : '?').'state='.urlencode($state) : ''));
        }

        return view('oidc.logged-out');
    }

    private function continueAuthorization(Request $request, OidcClient $client, User $user, array $pending): Response|View
    {
        $application = $client->application;
        $tenants = $this->access->eligibleTenants($user, $application);

        if ($tenants->isEmpty()) {
            $request->session()->forget(self::PENDING_KEY);
            $this->audit->record('sso.access_denied', $request, actor: $user, applicationId: $application->id, source: 'oidc');

            return $this->redirectError($pending['redirect_uri'], 'access_denied', $pending['state'], 'Your organization is not subscribed to this application, or you have not been given access to it.');
        }

        $hint = $pending['tenant_hint'] ?? null;
        $tenant = $hint
            ? $tenants->first(fn (Tenant $t) => $t->id === $hint || $t->tenant_code === $hint)
            : null;

        if (! $tenant && $tenants->count() === 1) {
            $tenant = $tenants->first();
        }

        if (! $tenant) {
            $request->session()->put(self::PENDING_KEY, $pending);

            return view('oidc.select-tenant', [
                'tenants' => $tenants,
                'applicationName' => $application->name,
            ]);
        }

        $request->session()->forget(self::PENDING_KEY);

        $code = $this->tokens->issueCode($client, $user, $tenant, $pending);

        $this->audit->record('sso.authorized', $request, actor: $user, tenantId: $tenant->id, applicationId: $application->id, source: 'oidc');

        return redirect()->away($this->appendQuery($pending['redirect_uri'], array_filter([
            'code' => $code,
            'state' => $pending['state'],
            'iss' => config('oidc.issuer'),
        ], fn ($v) => $v !== null)));
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function clientCredentials(Request $request): array
    {
        $header = (string) $request->header('Authorization');
        if (Str::startsWith($header, 'Basic ')) {
            $decoded = base64_decode(substr($header, 6), true);
            if ($decoded !== false && str_contains($decoded, ':')) {
                [$id, $secret] = explode(':', $decoded, 2);

                return [urldecode($id), urldecode($secret)];
            }
        }

        return [$request->input('client_id'), $request->input('client_secret')];
    }

    private function endWebSession(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    private function redirectError(string $redirectUri, string $error, ?string $state, ?string $description = null): RedirectResponse
    {
        return redirect()->away($this->appendQuery($redirectUri, array_filter([
            'error' => $error,
            'error_description' => $description,
            'state' => $state,
            'iss' => config('oidc.issuer'),
        ], fn ($v) => $v !== null)));
    }

    private function appendQuery(string $uri, array $params): string
    {
        return $uri.(str_contains($uri, '?') ? '&' : '?').http_build_query($params);
    }

    private function oauthError(string $error, int $status): JsonResponse
    {
        $response = response()->json(['error' => $error], $status)->header('Cache-Control', 'no-store');

        return $status === 401 ? $response->header('WWW-Authenticate', 'Basic realm="optinexus"') : $response;
    }

    private function errorPage(string $message, int $status): Response
    {
        return response()->view('oidc.error', ['message' => $message], $status);
    }
}
