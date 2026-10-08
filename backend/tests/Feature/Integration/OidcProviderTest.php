<?php

namespace Tests\Feature\Integration;

use App\Models\Application;
use App\Models\Tenant;
use App\Services\Oidc\OidcKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\TestCase;

class OidcProviderTest extends TestCase
{
    use CreatesIntegrationFixtures, RefreshDatabase;

    private Tenant $tenant;

    private Application $radar;

    private function setUpRadar(): array
    {
        $this->tenant = $this->makeTenant();
        $this->radar = $this->makeApplication(['application_code' => 'optiradar', 'name' => 'OptiRadar']);
        [$client, $secret] = $this->makeOidcClient($this->radar);
        $user = $this->makeSsoUser($this->tenant, $this->radar);

        return [$client, $secret, $user];
    }

    private function authorizeUrl($client, array $extra = []): string
    {
        return '/oidc/authorize?'.http_build_query(array_merge([
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => 'https://app.example.test/callback',
            'scope' => 'openid profile email',
            'state' => 'xyz',
        ], $extra));
    }

    private function signIn($client, $user, array $extra = [])
    {
        $this->get($this->authorizeUrl($client, $extra))->assertRedirect('/oidc/login');

        return $this->post('/oidc/login', ['email' => $user->email, 'password' => 'Passw0rd!Passw0rd']);
    }

    private function codeFrom($response): string
    {
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $q);
        $this->assertArrayHasKey('code', $q, 'Expected a code in '.$response->headers->get('Location'));

        return $q['code'];
    }

    public function test_discovery_and_jwks_are_published(): void
    {
        $this->getJson('/.well-known/openid-configuration')
            ->assertOk()
            ->assertJsonPath('response_types_supported.0', 'code')
            ->assertJsonPath('id_token_signing_alg_values_supported.0', 'RS256');

        $this->getJson('/oidc/jwks')->assertOk()->assertJsonPath('keys.0.kty', 'RSA');
    }

    public function test_full_code_flow_issues_verifiable_id_token_with_tenant_claims(): void
    {
        [$client, $secret, $user] = $this->setUpRadar();

        $response = $this->signIn($client, $user);
        $response->assertRedirectContains('https://app.example.test/callback');
        $code = $this->codeFrom($response);

        $token = $this->postJson('/api/oidc/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => 'https://app.example.test/callback',
            'client_id' => $client->client_id,
            'client_secret' => $secret,
        ])->assertOk()->assertJsonStructure(['access_token', 'id_token', 'expires_in']);

        $claims = app(OidcKeyService::class)->verify($token->json('id_token'));
        $this->assertNotNull($claims, 'id_token signature must verify against the published key');
        $this->assertSame($user->id, $claims['sub']);
        $this->assertSame($this->tenant->id, $claims['tenant_id']);
        $this->assertSame($client->client_id, $claims['aud']);
        $this->assertSame($user->email, $claims['email']);
        $this->assertContains('optiradar', $claims['groups']);

        $this->getJson('/api/oidc/userinfo', ['Authorization' => 'Bearer '.$token->json('access_token')])
            ->assertOk()
            ->assertJsonPath('tenant_code', $this->tenant->tenant_code);
    }

    public function test_second_application_signs_in_without_a_new_login(): void
    {
        [$radarClient, , $user] = $this->setUpRadar();
        $fleet = $this->makeApplication(['application_code' => 'optifleet', 'name' => 'OptiFleet']);
        [$fleetClient] = $this->makeOidcClient($fleet);
        $this->grantApplicationAccess($user, $this->tenant, $fleet);

        $this->codeFrom($this->signIn($radarClient, $user));

        // Same browser session, different application: answered straight away.
        $second = $this->get($this->authorizeUrl($fleetClient));
        $second->assertRedirectContains('https://app.example.test/callback');
        $this->codeFrom($second);
    }

    public function test_user_without_application_access_is_denied(): void
    {
        [$client, , ] = $this->setUpRadar();
        $outsider = \App\Models\User::factory()->create(['status' => 'ACTIVE', 'password' => 'Passw0rd!Passw0rd']);
        $this->attachUserToTenant($outsider, $this->tenant); // member, but never given OptiRadar

        $response = $this->signIn($client, $outsider);

        $this->assertStringContainsString('error=access_denied', $response->headers->get('Location'));
    }

    public function test_tenant_not_subscribed_to_application_is_denied(): void
    {
        [$client, , $user] = $this->setUpRadar();
        $this->tenant->applications()->updateExistingPivot($this->radar->id, ['status' => 'SUSPENDED']);

        $this->assertStringContainsString('error=access_denied', $this->signIn($client, $user)->headers->get('Location'));
    }

    public function test_suspended_tenant_is_denied(): void
    {
        [$client, , $user] = $this->setUpRadar();
        $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->assertStringContainsString('error=access_denied', $this->signIn($client, $user)->headers->get('Location'));
    }

    public function test_wrong_password_is_rejected(): void
    {
        [$client, , $user] = $this->setUpRadar();
        $this->get($this->authorizeUrl($client));

        $this->post('/oidc/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    public function test_unregistered_redirect_uri_is_never_redirected_to(): void
    {
        [$client] = $this->setUpRadar();

        $this->get($this->authorizeUrl($client, ['redirect_uri' => 'https://evil.example.test/callback']))->assertStatus(400);
        // Prefix of a registered URI must not match either.
        $this->get($this->authorizeUrl($client, ['redirect_uri' => 'https://app.example.test/callback/../x']))->assertStatus(400);
    }

    public function test_code_is_single_use_and_replay_revokes_the_token(): void
    {
        [$client, $secret, $user] = $this->setUpRadar();
        $code = $this->codeFrom($this->signIn($client, $user));
        $body = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://app.example.test/callback', 'client_id' => $client->client_id, 'client_secret' => $secret];

        $first = $this->postJson('/api/oidc/token', $body)->assertOk();
        $this->postJson('/api/oidc/token', $body)->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

        $this->getJson('/api/oidc/userinfo', ['Authorization' => 'Bearer '.$first->json('access_token')])->assertStatus(401);
    }

    public function test_wrong_client_secret_is_rejected(): void
    {
        [$client, , $user] = $this->setUpRadar();
        $code = $this->codeFrom($this->signIn($client, $user));

        $this->postJson('/api/oidc/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://app.example.test/callback',
            'client_id' => $client->client_id, 'client_secret' => 'nope',
        ])->assertStatus(401)->assertJsonPath('error', 'invalid_client');
    }

    public function test_pkce_is_enforced_for_public_clients(): void
    {
        $this->tenant = $this->makeTenant();
        $this->radar = $this->makeApplication(['application_code' => 'optiradar']);
        [$client] = $this->makeOidcClient($this->radar, ['client_secret_hash' => null]);
        $user = $this->makeSsoUser($this->tenant, $this->radar);

        // No challenge from a public client: refused.
        $this->assertStringContainsString('error=invalid_request', $this->get($this->authorizeUrl($client))->headers->get('Location'));

        [$verifier, $challenge] = $this->pkcePair();
        $extra = ['code_challenge' => $challenge, 'code_challenge_method' => 'S256'];
        $code = $this->codeFrom($this->signIn($client, $user, $extra));

        $body = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://app.example.test/callback', 'client_id' => $client->client_id];
        $this->postJson('/api/oidc/token', $body + ['code_verifier' => str_repeat('a', 64)])->assertStatus(400);

        // The failed attempt consumed the code; a fresh round with the right verifier succeeds.
        $code = $this->codeFrom($this->get($this->authorizeUrl($client, $extra)));
        $this->postJson('/api/oidc/token', array_merge($body, ['code' => $code, 'code_verifier' => $verifier]))->assertOk();
    }

    public function test_userinfo_stops_working_when_access_is_revoked(): void
    {
        [$client, $secret, $user] = $this->setUpRadar();
        $code = $this->codeFrom($this->signIn($client, $user));
        $token = $this->postJson('/api/oidc/token', [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://app.example.test/callback',
            'client_id' => $client->client_id, 'client_secret' => $secret,
        ])->json('access_token');

        $user->applicationAccess()->update(['status' => 'REVOKED']);

        $this->getJson('/api/oidc/userinfo', ['Authorization' => 'Bearer '.$token])->assertStatus(401);
    }

    public function test_multiple_tenants_require_a_choice_unless_hinted(): void
    {
        [$client, , $user] = $this->setUpRadar();
        $second = $this->makeTenant(null, ['name' => 'Second Co']);
        $this->attachUserToTenant($user, $second);
        $this->grantApplicationAccess($user, $second, $this->radar);

        $this->get($this->authorizeUrl($client));
        $this->post('/oidc/login', ['email' => $user->email, 'password' => 'Passw0rd!Passw0rd'])->assertOk()->assertSee('Second Co');

        $response = $this->post('/oidc/tenant', ['tenant_id' => $second->id]);
        $code = $this->codeFrom($response);
        $this->assertNotEmpty($code);

        $hinted = $this->get($this->authorizeUrl($client, ['tenant_hint' => $this->tenant->tenant_code]));
        $this->assertNotEmpty($this->codeFrom($hinted));
    }

    public function test_prompt_none_without_session_returns_login_required(): void
    {
        [$client] = $this->setUpRadar();

        $this->assertStringContainsString('error=login_required', $this->get($this->authorizeUrl($client, ['prompt' => 'none']))->headers->get('Location'));
    }

    public function test_logout_redirects_only_to_registered_uris(): void
    {
        [$client] = $this->setUpRadar();

        $this->get('/oidc/logout?'.http_build_query(['client_id' => $client->client_id, 'post_logout_redirect_uri' => 'https://app.example.test/bye', 'state' => 's1']))
            ->assertRedirect('https://app.example.test/bye?state=s1');

        $this->get('/oidc/logout?'.http_build_query(['client_id' => $client->client_id, 'post_logout_redirect_uri' => 'https://evil.example.test/']))
            ->assertOk()->assertSee('signed out');
    }
}
