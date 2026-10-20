<?php

namespace App\Http\Controllers\Oidc;

use App\Http\Controllers\Controller;
use App\Models\OidcClient;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditService;
use App\Services\Ecosystem\AccountActivationService;
use App\Services\Ecosystem\ActivationException;
use App\Services\Oidc\OidcKeyService;
use App\Services\Oidc\OidcTokenService;
use App\Services\Oidc\SessionRevocationService;
use App\Services\Oidc\SsoAccessResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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

    /** Set while a new Admin Tenant is choosing its password: the only thing that session may do (no sign-in, no code, no token). */
    private const ACTIVATION_KEY = 'oidc.activation';

    private const LANG_KEY = 'oidc.lang';

    public function __construct(
        private readonly OidcKeyService $keys,
        private readonly OidcTokenService $tokens,
        private readonly SsoAccessResolver $access,
        private readonly AuditService $audit,
        private readonly SessionRevocationService $revocation,
        private readonly AccountActivationService $activation,
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
            'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'auth_time', 'sid', 'nonce', 'at_hash', 'email', 'email_verified', 'name', 'preferred_username', 'tenant_id', 'tenant_code', 'tenant_name', 'groups', 'apps'],
            'authorization_response_iss_parameter_supported' => true,
            'backchannel_logout_supported' => true,
            'backchannel_logout_session_supported' => true,
            'backchannel_logout_events_supported' => [SessionRevocationService::EVENT_BACKCHANNEL_LOGOUT, SessionRevocationService::EVENT_ACCESS_REVOKED],
        ]);
    }

    public function jwks(): JsonResponse
    {
        return response()->json($this->keys->jwks())->header('Cache-Control', 'public, max-age=300');
    }

    public function authorize(Request $request): Response|View
    {
        $this->applyLocale($request);
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
        $this->applyLocale($request);
        $pending = $request->session()->get(self::PENDING_KEY);
        if (! $pending) {
            return $this->errorPage('Open the application you want to use and choose "Sign in with OptiNexus".', 400);
        }

        $client = OidcClient::query()->with('application')->where('client_id', $pending['client_id'])->first();

        return view('oidc.login', ['applicationName' => $client?->application?->name ?? $client?->name]);
    }

    public function login(Request $request): Response|View|RedirectResponse
    {
        $this->applyLocale($request);
        $pending = $request->session()->get(self::PENDING_KEY);
        if (! $pending) {
            return $this->errorPage('Your sign-in request has expired. Start again from the application.', 400);
        }

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        // A new Admin Tenant an operator created: the account is still INVITED, so it can only run the activation steps, never sign in.
        if ($user && $user->status === User::STATUS_INVITED && $user->isInManagedActivation()) {
            return $this->loginDuringActivation($request, $user, $credentials);
        }

        if (! $user || ! $user->password || ! Hash::check($credentials['password'], $user->password) || ! $user->isActive()) {
            $this->audit->record('sso.login_failed', $request, actorIdentity: $credentials['email'], source: 'oidc');

            return back()->withInput(['email' => $credentials['email']])
                ->withErrors(['email' => __('oidc.errors.invalid_credentials')]);
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
        $this->applyLocale($request);
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

    // ---------------------------------------------------------------- first Admin Tenant: password choice

    /**
     * The credentials of an account in managed activation. Nothing here signs the account in: a correct temporary password opens the
     * password-change page (a session that can do nothing else), and a chosen password still waits for the operator's e-mail
     * verification. Wrong attempts count per account, whatever address they come from.
     *
     * @param  array{email: string, password: string}  $credentials
     */
    private function loginDuringActivation(Request $request, User $user, array $credentials): Response|View|RedirectResponse
    {
        $back = fn (string $message) => back()->withInput(['email' => $credentials['email']])->withErrors(['email' => $message]);

        if ($seconds = $this->activation->lockedFor($user)) {
            $this->activation->denied($user, $request, 'locked');

            return $back(__('activation.errors.locked', ['minutes' => max(1, (int) ceil($seconds / 60))]));
        }

        $correct = $user->password_change_required
            ? $this->activation->matchesTemporaryPassword($user, $credentials['password'])
            : ($user->password !== null && Hash::check($credentials['password'], $user->password));

        if (! $correct) {
            $this->activation->recordFailure($user);
            $this->audit->record('sso.login_failed', $request, actorIdentity: $credentials['email'], source: 'oidc');

            return $back(__('oidc.errors.invalid_credentials'));
        }

        if (! $user->password_change_required) {
            // The holder already chose a password; the e-mail verification is the operator's step and has not been recorded yet.
            RateLimiter::clear($this->activation->lockKey($user));
            $this->activation->denied($user, $request, 'email_verification_pending');

            return response()->view('oidc.activation-pending');
        }

        if ($user->temporaryPasswordExpired()) {
            $this->activation->denied($user, $request, 'expired');

            return $back(__('activation.errors.expired'));
        }

        $request->session()->regenerate();
        $request->session()->put(self::ACTIVATION_KEY, ['user_id' => $user->id, 'issued_at' => $user->temporary_password_issued_at->timestamp]);

        $this->audit->record('activation.started', $request, actor: $user, resourceType: 'User', resourceId: $user->id, source: 'activation');

        return redirect()->route('oidc.activate');
    }

    public function showActivation(Request $request): Response|View
    {
        $this->applyLocale($request);
        $user = $this->activationUser($request);

        if (! $user) {
            return $this->errorPage(__('activation.errors.session'), 400);
        }

        if ($user->temporaryPasswordExpired()) {
            return $this->activationState('expired', 410);
        }

        return $this->activationForm($user);
    }

    public function activate(Request $request): Response|View|RedirectResponse
    {
        $this->applyLocale($request);
        $user = $this->activationUser($request);

        if (! $user) {
            return $this->errorPage(__('activation.errors.session'), 400);
        }

        $temporary = (string) $request->input('temporary_password');
        $password = (string) $request->input('password');
        $errors = $this->validateActivation($temporary, $password, (string) $request->input('password_confirmation'));

        if ($errors !== []) {
            return $this->activationForm($user, $errors, 422);
        }

        try {
            $result = $this->activation->changePassword($user->id, $user->temporary_password_issued_at->timestamp, $temporary, $password, $request);
        } catch (ActivationException $e) {
            return match ($e->state) {
                ActivationException::WRONG_PASSWORD => $this->activationForm($user, ['temporary_password' => __('activation.errors.wrong_password', ['left' => $this->activation->attemptsLeft($user)])], 422),
                ActivationException::SAME_PASSWORD => $this->activationForm($user, ['password' => __('activation.errors.same_password')], 422),
                ActivationException::LOCKED => $this->activationState('locked', 429, $e->retryAfter),
                ActivationException::EXPIRED => $this->activationState('expired', 410),
                ActivationException::USED => $this->activationState('used', 410),
                default => $this->activationState('invalid', 409),
            };
        }

        // The bootstrap session ends here. What survives is the pending sign-in request and the chosen language, nothing else.
        $pending = $request->session()->get(self::PENDING_KEY);
        $lang = $request->session()->get(self::LANG_KEY);
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put(array_filter([self::PENDING_KEY => $pending, self::LANG_KEY => $lang]));

        if (! $result['activated']) {
            return response()->view('oidc.activation-pending');
        }

        // Both conditions hold: this is now an ordinary sign-in, proven by the password just chosen, in a brand-new session.
        $client = $pending ? OidcClient::query()->where('client_id', $pending['client_id'])->first() : null;
        if (! $client || ! $client->isActive()) {
            return $this->errorPage('The application is no longer registered with OptiNexus.', 400);
        }

        $member = $result['user']->refresh();
        Auth::guard('web')->login($member);
        $request->session()->put('oidc.auth_time', time());
        $member->forceFill(['last_login_at' => now()])->save();

        $this->audit->record('sso.login', $request, actor: $member, applicationId: $client->application_id, source: 'oidc');

        $pending['auth_time'] = time();

        return $this->continueAuthorization($request, $client, $member, $pending);
    }

    /** The account whose password change this browser session was opened for, or null when that is no longer valid. */
    private function activationUser(Request $request): ?User
    {
        $state = $request->session()->get(self::ACTIVATION_KEY);
        $user = is_array($state) && isset($state['user_id']) ? User::query()->find($state['user_id']) : null;

        if (! $user || $user->status !== User::STATUS_INVITED || ! $user->isInManagedActivation() || ! $user->password_change_required
            || $user->temporary_password_issued_at?->timestamp !== ($state['issued_at'] ?? null)) {
            $request->session()->forget(self::ACTIVATION_KEY);

            return null;
        }

        return $user;
    }

    /** @return array<string, string> */
    private function validateActivation(string $temporary, string $password, string $confirmation): array
    {
        $errors = [];
        $min = (int) config('activation.password_min_length');

        if ($temporary === '') {
            $errors['temporary_password'] = __('activation.errors.temporary_required');
        }

        if ($password === '') {
            $errors['password'] = __('activation.errors.password_required');
        } elseif (mb_strlen($password) < $min || mb_strlen($password) > 128) {
            $errors['password'] = __('activation.errors.password_min', ['min' => $min]);
        } elseif (! hash_equals($password, $confirmation)) {
            $errors['password'] = __('activation.errors.password_confirmed');
        }

        return $errors;
    }

    /** @param  array<string, string>  $errors */
    private function activationForm(User $user, array $errors = [], int $status = 200): Response
    {
        return response()->view('oidc.activate', ['email' => $user->email, 'errors' => $errors], $status);
    }

    private function activationState(string $state, int $status, ?int $retryAfter = null): Response
    {
        $minutes = $retryAfter ? max(1, (int) ceil($retryAfter / 60)) : (int) ceil(config('activation.lockout_seconds') / 60);
        $response = response()->view('oidc.activation-state', ['state' => $state, 'minutes' => $minutes], $status);

        if ($retryAfter) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /** English or Bahasa Indonesia: the visitor's choice (?lang=), else the browser's preference, else English. */
    private function applyLocale(Request $request): void
    {
        $locales = config('activation.locales');
        $lang = $request->query('lang');

        if (is_string($lang) && in_array($lang, $locales, true)) {
            $request->session()->put(self::LANG_KEY, $lang);
        }

        $chosen = $request->session()->get(self::LANG_KEY) ?? $request->getPreferredLanguage($locales) ?? 'en';
        app()->setLocale(in_array($chosen, $locales, true) ? $chosen : 'en');
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

    /**
     * RP-initiated logout (OIDC RP-Initiated Logout 1.0) and the entry point of
     * central logout: whoever is signed in here (the browser session, or the
     * user named by a valid id_token_hint) is signed out of every application,
     * and each application is told through Back-Channel Logout.
     */
    public function logout(Request $request): Response|View
    {
        $hint = $request->query('id_token_hint');
        $hintClaims = is_string($hint) && $hint !== '' ? $this->keys->verify($hint) : null;

        $webUser = Auth::guard('web')->user();
        $hintClient = isset($hintClaims['aud']) && is_string($hintClaims['aud'])
            ? OidcClient::query()->where('client_id', $hintClaims['aud'])->first() : null;
        $hintValid = $hintClient && ($hintClaims['iss'] ?? null) === config('oidc.issuer')
            && (! $request->query('client_id') || $request->query('client_id') === $hintClient->client_id);
        $user = $hintValid && isset($hintClaims['sub']) ? User::query()->find($hintClaims['sub']) : $webUser;

        if ($user instanceof User) {
            $this->revocation->logoutEverywhere($user, 'logout', $request);
        }

        if (! $webUser || ! $user || $webUser->id === $user->id) {
            $this->endWebSession($request);
        }

        $target = (string) $request->query('post_logout_redirect_uri');
        $clientId = (string) $request->query('client_id');

        if ($target !== '' && $clientId === '' && $hintClaims) {
            $clientId = (string) ($hintClaims['aud'] ?? '');
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
