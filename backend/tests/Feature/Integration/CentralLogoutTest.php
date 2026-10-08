<?php

namespace Tests\Feature\Integration;

use App\Jobs\DeliverBackchannelLogout;
use App\Models\Application;
use App\Models\OidcAccessToken;
use App\Models\OidcClient;
use App\Models\OidcLogoutDelivery;
use App\Models\OidcSession;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Oidc\OidcKeyService;
use App\Services\Oidc\SessionRevocationService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlatformIntegrationPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\TestCase;

class CentralLogoutTest extends TestCase
{
    use CreatesIntegrationFixtures, RefreshDatabase;

    private Tenant $tenant;

    private Application $radar;

    private Application $fleet;

    private OidcClient $radarClient;

    private OidcClient $fleetClient;

    private string $radarSecret;

    private string $fleetSecret;

    private User $user;

    /** Answers the next application calls, in order: [status, body]. Defaults to 204. */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant();
        $this->radar = $this->makeApplication(['application_code' => 'optiradar', 'name' => 'OptiRadar']);
        $this->fleet = $this->makeApplication(['application_code' => 'optifleet', 'name' => 'OptiFleet']);
        [$this->radarClient, $this->radarSecret] = $this->makeOidcClient($this->radar, ['backchannel_logout_uri' => 'https://radar.example.test/api/session/openid/backchannel-logout']);
        [$this->fleetClient, $this->fleetSecret] = $this->makeOidcClient($this->fleet, ['backchannel_logout_uri' => 'https://fleet.example.test/api/v1/auth/sso/backchannel-logout']);
        $this->user = $this->makeSsoUser($this->tenant, $this->radar);
        $this->grantApplicationAccess($this->user, $this->tenant, $this->fleet);

        Http::fake(function () {
            [$status, $body] = array_shift($this->answers) ?? [204, ''];

            return Http::response($body, $status);
        });
    }

    private function actingAsIntegrationAdmin(): User
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(PlatformIntegrationPermissionSeeder::class);

        return $this->actingAsSuperAdmin();
    }

    /** Runs the whole code flow; returns the token response of the application sign-in. */
    private function signInTo(OidcClient $client, string $secret, ?User $user = null, ?Tenant $tenant = null): array
    {
        $user ??= $this->user;
        $authorize = '/oidc/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => $client->client_id, 'redirect_uri' => 'https://app.example.test/callback',
            'scope' => 'openid profile email', 'state' => 'xyz',
        ]);

        // Already signed in at OptiNexus: the code comes straight back. Otherwise the login form answers.
        $response = $this->get($authorize);
        if (str_contains((string) $response->headers->get('Location'), '/oidc/login')) {
            $response = $this->post('/oidc/login', ['email' => $user->email, 'password' => 'Passw0rd!Passw0rd']);
        }

        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query, 'Expected a code in '.$response->headers->get('Location'));

        return $this->postJson('/api/oidc/token', [
            'grant_type' => 'authorization_code', 'code' => $query['code'], 'redirect_uri' => 'https://app.example.test/callback',
            'client_id' => $client->client_id, 'client_secret' => $secret,
        ])->assertOk()->json();
    }

    /** @return array<int, array{url: string, claims: array}> */
    private function sentLogoutTokens(): array
    {
        $sent = [];
        foreach (Http::recorded() as [$request]) {
            parse_str($request->body(), $form);
            if (isset($form['logout_token'])) {
                $claims = app(OidcKeyService::class)->verify($form['logout_token']);
                $this->assertNotNull($claims, 'logout_token must verify against the published key');
                $sent[] = ['url' => $request->url(), 'claims' => $claims];
            }
        }

        return $sent;
    }

    public function test_sign_in_creates_a_session_and_puts_its_id_in_the_id_token(): void
    {
        $tokens = $this->signInTo($this->radarClient, $this->radarSecret);

        $claims = app(OidcKeyService::class)->verify($tokens['id_token']);
        $session = OidcSession::query()->where('user_id', $this->user->id)->sole();

        $this->assertSame($session->id, $claims['sid']);
        $this->assertSame($this->radarClient->id, $session->oidc_client_id);
        $this->assertSame($this->tenant->id, $session->tenant_id);
        $this->assertNull($session->ended_at);
        $this->assertSame($session->id, OidcAccessToken::query()->sole()->oidc_session_id);
    }

    public function test_discovery_announces_back_channel_logout(): void
    {
        $this->getJson('/.well-known/openid-configuration')
            ->assertOk()
            ->assertJsonPath('backchannel_logout_supported', true)
            ->assertJsonPath('backchannel_logout_session_supported', true);
    }

    public function test_suspending_a_user_ends_sessions_and_tells_every_application_to_deactivate_the_account(): void
    {
        $tokens = $this->signInTo($this->radarClient, $this->radarSecret);
        $this->signInTo($this->fleetClient, $this->fleetSecret);
        $this->actingAsSuperAdmin();

        $this->postJson("/api/v1/users/{$this->user->id}/suspend")->assertOk();

        $sent = $this->sentLogoutTokens();
        $this->assertCount(2, $sent);
        foreach ($sent as $call) {
            $claims = $call['claims'];
            $this->assertSame(config('oidc.issuer'), $claims['iss']);
            $this->assertSame($this->user->id, $claims['sub']);
            $this->assertSame($this->user->email, $claims['email']);
            $this->assertArrayHasKey(SessionRevocationService::EVENT_BACKCHANNEL_LOGOUT, $claims['events']);
            $this->assertSame([], $claims['events'][SessionRevocationService::EVENT_BACKCHANNEL_LOGOUT]);
            $this->assertSame('user_suspended', $claims['events'][SessionRevocationService::EVENT_ACCESS_REVOKED]['reason']);
            $this->assertSame('user', $claims['events'][SessionRevocationService::EVENT_ACCESS_REVOKED]['scope']);
            $this->assertArrayNotHasKey('nonce', $claims);
            $this->assertGreaterThan($claims['iat'], $claims['exp']);
            $this->assertNotEmpty($claims['jti']);
        }
        $this->assertEqualsCanonicalizing(
            [$this->radarClient->backchannel_logout_uri, $this->fleetClient->backchannel_logout_uri],
            array_column($sent, 'url'),
        );
        $this->assertSame(2, OidcLogoutDelivery::query()->where('status', 'DELIVERED')->count());
        $this->assertSame(0, OidcSession::query()->active()->count());
        $this->assertSame(0, OidcAccessToken::query()->whereNull('revoked_at')->count());

        // The access token the application held no longer works.
        $this->getJson('/api/oidc/userinfo', ['Authorization' => 'Bearer '.$tokens['access_token']])->assertStatus(401);
    }

    public function test_removing_one_application_only_tells_that_application(): void
    {
        $this->signInTo($this->radarClient, $this->radarSecret);
        $this->signInTo($this->fleetClient, $this->fleetSecret);
        $this->actingAsSuperAdmin();

        $this->deleteJson("/api/v1/users/{$this->user->id}/applications/{$this->radar->id}?tenant_id={$this->tenant->id}")->assertOk();

        $sent = $this->sentLogoutTokens();
        $this->assertCount(1, $sent);
        $this->assertSame($this->radarClient->backchannel_logout_uri, $sent[0]['url']);
        $revoked = $sent[0]['claims']['events'][SessionRevocationService::EVENT_ACCESS_REVOKED];
        $this->assertSame('application_access_revoked', $revoked['reason']);
        $this->assertSame('tenant', $revoked['scope']);
        $this->assertSame($this->tenant->id, $revoked['tenant_id']);

        $active = OidcSession::query()->active()->get();
        $this->assertCount(1, $active);
        $this->assertSame($this->fleetClient->id, $active->first()->oidc_client_id);
    }

    public function test_removing_a_tenant_membership_leaves_the_other_tenant_alone(): void
    {
        $this->signInTo($this->radarClient, $this->radarSecret);
        $otherTenant = $this->makeTenant();
        $this->attachUserToTenant($this->user, $otherTenant);
        $this->grantApplicationAccess($this->user, $otherTenant, $this->radar);
        $this->actingAsSuperAdmin();
        // A second sign-in, into the other tenant.
        OidcSession::create(['user_id' => $this->user->id, 'tenant_id' => $otherTenant->id, 'oidc_client_id' => $this->radarClient->id, 'expires_at' => now()->addDay()]);

        $this->deleteJson("/api/v1/users/{$this->user->id}/tenants/{$this->tenant->id}")->assertOk();

        // Both applications the user could open in that tenant are told, and only for that tenant.
        $sent = $this->sentLogoutTokens();
        $this->assertCount(2, $sent);
        foreach ($sent as $call) {
            $revoked = $call['claims']['events'][SessionRevocationService::EVENT_ACCESS_REVOKED];
            $this->assertSame('tenant_membership_removed', $revoked['reason']);
            $this->assertSame('tenant', $revoked['scope']);
            $this->assertSame($this->tenant->id, $revoked['tenant_id']);
        }
        $this->assertSame([$otherTenant->id], OidcSession::query()->active()->pluck('tenant_id')->all());
    }

    public function test_suspending_a_tenant_revokes_everyone_in_it(): void
    {
        $colleague = $this->makeSsoUser($this->tenant, $this->radar);
        $this->signInTo($this->radarClient, $this->radarSecret);
        OidcSession::create(['user_id' => $colleague->id, 'tenant_id' => $this->tenant->id, 'oidc_client_id' => $this->radarClient->id, 'expires_at' => now()->addDay()]);
        $this->actingAsSuperAdmin();

        $this->postJson("/api/v1/tenants/{$this->tenant->id}/suspend")->assertOk();

        // The user can open two applications here, the colleague one.
        $subjects = array_count_values(array_map(fn ($call) => $call['claims']['sub'], $this->sentLogoutTokens()));
        $this->assertSame([$this->user->id => 2, $colleague->id => 1], $subjects);
        $this->assertSame(0, OidcSession::query()->active()->count());
    }

    public function test_logging_out_of_one_application_signs_the_user_out_of_all_of_them(): void
    {
        $tokens = $this->signInTo($this->radarClient, $this->radarSecret);
        $this->signInTo($this->fleetClient, $this->fleetSecret);

        $this->get('/oidc/logout?'.http_build_query(['id_token_hint' => $tokens['id_token']]))->assertOk();

        $sent = $this->sentLogoutTokens();
        $this->assertCount(2, $sent);
        foreach ($sent as $call) {
            $this->assertSame($this->user->id, $call['claims']['sub']);
            $this->assertArrayNotHasKey(SessionRevocationService::EVENT_ACCESS_REVOKED, $call['claims']['events']);
        }
        $this->assertSame(0, OidcSession::query()->active()->count());
        $this->assertFalse($this->isAuthenticated('web'));
        // Logging out does not disable anyone's account.
        $this->assertTrue($this->user->fresh()->isActive());
    }

    public function test_access_lost_without_a_hook_is_caught_by_the_reconciler(): void
    {
        $this->signInTo($this->radarClient, $this->radarSecret);
        $this->tenant->applications()->updateExistingPivot($this->radar->id, ['status' => 'SUSPENDED']);

        $this->artisan('oidc:reconcile-access')->assertExitCode(0);

        $sent = $this->sentLogoutTokens();
        $this->assertCount(1, $sent);
        $this->assertSame('application_not_assigned', $sent[0]['claims']['events'][SessionRevocationService::EVENT_ACCESS_REVOKED]['reason']);
        $this->assertSame(0, OidcSession::query()->active()->count());

        // Nothing left to do on a second run.
        $this->artisan('oidc:reconcile-access')->assertExitCode(0);
        Http::assertSentCount(1);
    }

    public function test_a_session_whose_access_still_holds_is_left_alone_by_the_reconciler(): void
    {
        $this->signInTo($this->radarClient, $this->radarSecret);

        $this->artisan('oidc:reconcile-access')->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(1, OidcSession::query()->active()->count());
    }

    public function test_an_application_without_a_logout_endpoint_still_loses_the_session(): void
    {
        $this->radarClient->update(['backchannel_logout_uri' => null]);
        $this->signInTo($this->radarClient, $this->radarSecret);
        $this->actingAsSuperAdmin();

        $this->postJson("/api/v1/users/{$this->user->id}/disable")->assertOk();

        // Only OptiFleet (access, no session) is told; OptiRadar has nowhere to be told.
        $sent = $this->sentLogoutTokens();
        $this->assertSame([$this->fleetClient->backchannel_logout_uri], array_column($sent, 'url'));
        $this->assertSame(0, OidcSession::query()->active()->count());
        $this->assertSame(0, OidcLogoutDelivery::query()->where('oidc_client_id', $this->radarClient->id)->count());
    }

    public function test_user_wide_revocation_also_closes_optinexus_own_tokens(): void
    {
        $this->user->createToken('spa');
        $this->actingAsSuperAdmin();

        $this->postJson("/api/v1/users/{$this->user->id}/suspend")->assertOk();

        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_force_logout_needs_its_permission_and_ends_sessions(): void
    {
        $this->signInTo($this->radarClient, $this->radarSecret);

        $this->seedGovernanceBaseline();
        $nobody = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($nobody);
        $this->postJson("/api/v1/users/{$this->user->id}/force-logout")->assertStatus(403);
        $this->assertSame(1, OidcSession::query()->active()->count());

        $this->actingAsSuperAdmin();
        $this->postJson("/api/v1/users/{$this->user->id}/force-logout")->assertOk()->assertJsonPath('data.applications_notified', 1);
        $this->assertSame(0, OidcSession::query()->active()->count());
    }

    public function test_revoking_a_client_ends_its_sessions(): void
    {
        $this->signInTo($this->radarClient, $this->radarSecret);
        $this->actingAsIntegrationAdmin();

        $this->postJson("/api/v1/oidc-clients/{$this->radarClient->id}/revoke")->assertOk();

        $this->assertSame(0, OidcSession::query()->active()->count());
    }

    public function test_client_api_accepts_and_returns_the_back_channel_logout_uri(): void
    {
        $this->actingAsIntegrationAdmin();

        $this->putJson("/api/v1/oidc-clients/{$this->radarClient->id}", ['backchannel_logout_uri' => 'https://radar.example.test/hook'])
            ->assertOk()->assertJsonPath('data.backchannel_logout_uri', 'https://radar.example.test/hook');
        $this->putJson("/api/v1/oidc-clients/{$this->radarClient->id}", ['backchannel_logout_uri' => 'javascript:alert(1)'])
            ->assertStatus(422);
    }

    public function test_delivery_outcomes_are_recorded_and_retried_only_when_it_can_help(): void
    {
        Queue::fake();
        $this->signInTo($this->radarClient, $this->radarSecret);
        app(SessionRevocationService::class)->logoutEverywhere($this->user);
        $delivery = OidcLogoutDelivery::query()->where('oidc_client_id', $this->radarClient->id)->sole();
        $keys = app(OidcKeyService::class);

        // Server error: the job fails (so the queue retries) and the row stays pending.
        $this->answers = [[503, 'boom']];
        try {
            (new DeliverBackchannelLogout($delivery->id))->handle($keys);
            $this->fail('A 503 must make the job retry.');
        } catch (\RuntimeException) {
        }
        $delivery->refresh();
        $this->assertSame('PENDING', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertSame(503, $delivery->last_http_status);

        // The application answers 400: it understood and refused, no point repeating.
        $this->answers = [[400, 'invalid_request']];
        (new DeliverBackchannelLogout($delivery->id))->handle($keys);
        $this->assertSame('FAILED', $delivery->fresh()->status);

        // A delivery that fails once and succeeds on the next attempt.
        $retry = OidcLogoutDelivery::create(['oidc_client_id' => $this->radarClient->id, 'user_id' => $this->user->id, 'type' => 'LOGOUT', 'reason' => 'logout', 'status' => 'PENDING']);
        $this->answers = [[502, 'down'], [204, '']];
        try {
            (new DeliverBackchannelLogout($retry->id))->handle($keys);
            $this->fail('A 502 must make the job retry.');
        } catch (\RuntimeException) {
        }
        (new DeliverBackchannelLogout($retry->id))->handle($keys);
        $retry->refresh();
        $this->assertSame('DELIVERED', $retry->status);
        $this->assertSame(2, $retry->attempts);
        $this->assertNotNull($retry->delivered_at);

        // Already delivered: nothing is sent again.
        $before = count(Http::recorded());
        (new DeliverBackchannelLogout($retry->id))->handle($keys);
        $this->assertCount($before, Http::recorded());
    }
}
