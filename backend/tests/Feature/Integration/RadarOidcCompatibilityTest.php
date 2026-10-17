<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant;
use App\Services\Oidc\OidcKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\TestCase;

/**
 * The exact conversation OptiRadar has with OptiNexus after the A08/A09 fixes: a confidential client registered with
 * require_pkce = true that sends state, nonce and an S256 challenge, authenticates with HTTP Basic at the token
 * endpoint, validates the ID token (issuer, audience, nonce, subject) and cross-checks the user info subject.
 */
class RadarOidcCompatibilityTest extends TestCase
{
    use CreatesIntegrationFixtures, RefreshDatabase;

    private const REDIRECT = 'https://radar.example.test/api/session/openid/callback';

    private Tenant $tenant;

    private function radarFlow(array $authorizeOverrides = []): array
    {
        $this->tenant = $this->makeTenant();
        $radar = $this->makeApplication(['application_code' => 'optiradar', 'name' => 'OptiRadar']);
        [$client, $secret] = $this->makeOidcClient($radar, [
            'redirect_uris' => [self::REDIRECT],
            'require_pkce' => true,
        ]);
        $user = $this->makeSsoUser($this->tenant, $radar);

        $verifier = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $query = array_merge([
            'response_type' => 'code',
            'client_id' => $client->client_id,
            'redirect_uri' => self::REDIRECT,
            'scope' => 'openid profile email',
            'state' => 'state-from-radar',
            'nonce' => 'nonce-from-radar',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], $authorizeOverrides);

        return [$client, $secret, $user, $verifier, $query];
    }

    private function authorize(array $query)
    {
        return $this->get('/oidc/authorize?'.http_build_query($query));
    }

    public function test_a_require_pkce_client_without_a_challenge_is_refused(): void
    {
        [, , , , $query] = $this->radarFlow();
        unset($query['code_challenge'], $query['code_challenge_method']);

        $location = $this->authorize($query)->headers->get('Location');

        $this->assertStringStartsWith(self::REDIRECT, $location);
        $this->assertStringContainsString('error=invalid_request', $location);
        $this->assertStringContainsString('state=state-from-radar', $location);
    }

    public function test_plain_challenges_are_refused(): void
    {
        [, , , , $query] = $this->radarFlow(['code_challenge_method' => 'plain']);

        $this->assertStringContainsString('error=invalid_request', $this->authorize($query)->headers->get('Location'));
    }

    public function test_the_whole_radar_conversation_works_and_every_check_radar_makes_can_pass(): void
    {
        [$client, $secret, $user, $verifier, $query] = $this->radarFlow();

        $this->authorize($query)->assertRedirect('/oidc/login');
        $response = $this->post('/oidc/login', ['email' => $user->email, 'password' => 'Passw0rd!Passw0rd']);
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT, $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $callback);

        // What Radar checks on the callback: its own state, and the issuer of the response (RFC 9207).
        $this->assertSame('state-from-radar', $callback['state']);
        $this->assertSame(rtrim(config('oidc.issuer'), '/'), rtrim($callback['iss'], '/'));

        $token = $this->call('POST', '/api/oidc/token', [
            'grant_type' => 'authorization_code',
            'code' => $callback['code'],
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $verifier,
        ], [], [], [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode($client->client_id.':'.$secret),
            'HTTP_ACCEPT' => 'application/json',
        ])->assertOk();

        $claims = app(OidcKeyService::class)->verify($token->json('id_token'));
        $this->assertNotNull($claims);
        $this->assertSame(rtrim(config('oidc.issuer'), '/'), rtrim($claims['iss'], '/'));
        $this->assertSame($client->client_id, $claims['aud']);
        $this->assertSame('nonce-from-radar', $claims['nonce']);
        $this->assertSame($user->id, $claims['sub']);
        $this->assertGreaterThan(time(), $claims['exp']);
        $this->assertLessThanOrEqual(time() + 60, $claims['iat']);

        $info = $this->getJson('/api/oidc/userinfo', ['Authorization' => 'Bearer '.$token->json('access_token')])
            ->assertOk();
        $this->assertSame($claims['sub'], $info->json('sub'), 'user info and ID token name the same subject');
        $this->assertTrue($info->json('email_verified'));
        $this->assertSame($this->tenant->id, $info->json('tenant_id'));
    }

    public function test_a_wrong_verifier_or_a_missing_one_fails_the_exchange(): void
    {
        [$client, $secret, $user, $verifier, $query] = $this->radarFlow();
        $this->authorize($query);
        $location = $this->post('/oidc/login', ['email' => $user->email, 'password' => 'Passw0rd!Passw0rd'])
            ->headers->get('Location');
        parse_str(parse_url($location, PHP_URL_QUERY), $callback);
        $headers = ['HTTP_AUTHORIZATION' => 'Basic '.base64_encode($client->client_id.':'.$secret), 'HTTP_ACCEPT' => 'application/json'];
        $body = ['grant_type' => 'authorization_code', 'code' => $callback['code'], 'redirect_uri' => self::REDIRECT];

        $this->call('POST', '/api/oidc/token', $body, [], [], $headers)->assertStatus(400);

        // The refused attempt used the code up: even the right verifier cannot redeem it now.
        $this->call('POST', '/api/oidc/token', $body + ['code_verifier' => $verifier], [], [], $headers)->assertStatus(400);
    }
}
