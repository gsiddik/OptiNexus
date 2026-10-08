<?php

namespace Tests\Feature\Integration;

use App\Jobs\DeliverBackchannelLogout;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\OidcClient;
use App\Models\OidcLogoutDelivery;
use App\Models\OidcSession;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserApplicationAccess;
use App\Services\Oidc\OidcKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\TestCase;

class LogoutDeliveryRequeueTest extends TestCase
{
    use CreatesIntegrationFixtures, RefreshDatabase;

    private Tenant $tenant;

    private Application $radar;

    private Application $fleet;

    private OidcClient $radarClient;

    private OidcClient $fleetClient;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant();
        $this->radar = $this->makeApplication(['application_code' => 'optiradar', 'name' => 'OptiRadar']);
        $this->fleet = $this->makeApplication(['application_code' => 'optifleet', 'name' => 'OptiFleet']);
        [$this->radarClient] = $this->makeOidcClient($this->radar, ['backchannel_logout_uri' => 'https://radar.example.test/api/session/openid/backchannel-logout']);
        [$this->fleetClient] = $this->makeOidcClient($this->fleet, ['backchannel_logout_uri' => 'https://fleet.example.test/api/v1/auth/sso/backchannel-logout']);
        $this->user = $this->makeSsoUser($this->tenant, $this->radar);
        $this->grantApplicationAccess($this->user, $this->tenant, $this->fleet);

        Queue::fake();
        Http::fake(fn () => Http::response('', 204));
    }

    private function failed(OidcClient $client, string $type = 'LOGOUT', string $reason = 'logout', ?Tenant $tenant = null, ?User $user = null, int $hoursAgo = 1): OidcLogoutDelivery
    {
        $delivery = OidcLogoutDelivery::create([
            'oidc_client_id' => $client->id,
            'user_id' => ($user ?? $this->user)->id,
            'tenant_id' => $tenant?->id,
            'type' => $type,
            'reason' => $reason,
            'status' => OidcLogoutDelivery::STATUS_FAILED,
            'attempts' => 6,
            'last_http_status' => 503,
            'last_error' => 'HTTP 503',
        ]);
        OidcLogoutDelivery::query()->whereKey($delivery->id)->update(['created_at' => now()->subHours($hoursAgo)]);

        return $delivery->fresh();
    }

    private function queuedIds(): array
    {
        $ids = [];
        Queue::assertPushed(DeliverBackchannelLogout::class, function (DeliverBackchannelLogout $job) use (&$ids) {
            $ids[] = $job->deliveryId;

            return true;
        });

        return $ids;
    }

    public function test_a_failed_logout_is_sent_again_with_a_fresh_start(): void
    {
        $delivery = $this->failed($this->radarClient);

        $this->artisan('oidc:requeue-logout-deliveries')
            ->expectsOutputToContain('Sent again: 1')
            ->assertExitCode(0);

        $delivery->refresh();
        $this->assertSame('PENDING', $delivery->status);
        $this->assertSame(0, $delivery->attempts);
        $this->assertNull($delivery->last_http_status);
        $this->assertSame([$delivery->id], $this->queuedIds());
        Queue::assertPushed(DeliverBackchannelLogout::class, 1);
    }

    public function test_the_sent_again_call_is_really_delivered_and_marked_so(): void
    {
        $delivery = $this->failed($this->radarClient);

        $this->artisan('oidc:requeue-logout-deliveries')->assertExitCode(0);
        foreach ($this->queuedIds() as $id) {
            (new DeliverBackchannelLogout($id))->handle(app(OidcKeyService::class));
        }

        $delivery->refresh();
        $this->assertSame('DELIVERED', $delivery->status);
        $this->assertSame(1, $delivery->attempts);
        $this->assertNotNull($delivery->delivered_at);
        Http::assertSentCount(1);
    }

    public function test_only_failed_deliveries_are_touched(): void
    {
        $pending = $this->failed($this->radarClient);
        $pending->update(['status' => OidcLogoutDelivery::STATUS_PENDING, 'attempts' => 2]);
        $delivered = $this->failed($this->fleetClient);
        $delivered->update(['status' => OidcLogoutDelivery::STATUS_DELIVERED, 'attempts' => 1]);

        $this->artisan('oidc:requeue-logout-deliveries')->expectsOutputToContain('Sent again: 0')->assertExitCode(0);

        $this->assertSame(2, $pending->fresh()->attempts);
        $this->assertSame('DELIVERED', $delivered->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_logout_is_not_repeated_when_the_user_signed_in_again_since(): void
    {
        $delivery = $this->failed($this->radarClient, hoursAgo: 2);
        OidcSession::create(['user_id' => $this->user->id, 'tenant_id' => $this->tenant->id, 'oidc_client_id' => $this->radarClient->id, 'expires_at' => now()->addDay()]);

        $this->artisan('oidc:requeue-logout-deliveries')
            ->expectsOutputToContain('Sent again: 0')
            ->expectsOutputToContain('signed in again since: 1')
            ->assertExitCode(0);

        $this->assertSame('FAILED', $delivery->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_sign_in_to_another_application_does_not_block_the_repeat(): void
    {
        $delivery = $this->failed($this->radarClient, hoursAgo: 2);
        OidcSession::create(['user_id' => $this->user->id, 'tenant_id' => $this->tenant->id, 'oidc_client_id' => $this->fleetClient->id, 'expires_at' => now()->addDay()]);

        $this->artisan('oidc:requeue-logout-deliveries')->expectsOutputToContain('Sent again: 1')->assertExitCode(0);

        $this->assertSame('PENDING', $delivery->fresh()->status);
    }

    public function test_a_sign_in_from_before_the_logout_does_not_block_the_repeat(): void
    {
        $delivery = $this->failed($this->radarClient, hoursAgo: 1);
        $session = OidcSession::create(['user_id' => $this->user->id, 'tenant_id' => $this->tenant->id, 'oidc_client_id' => $this->radarClient->id, 'expires_at' => now()->addDay(), 'ended_at' => now()->subMinutes(50)]);
        OidcSession::query()->whereKey($session->id)->update(['created_at' => now()->subHours(3)]);

        $this->artisan('oidc:requeue-logout-deliveries')->expectsOutputToContain('Sent again: 1')->assertExitCode(0);

        $this->assertSame('PENDING', $delivery->fresh()->status);
    }

    public function test_a_logout_older_than_the_limit_is_left_alone_unless_the_limit_is_raised(): void
    {
        $delivery = $this->failed($this->radarClient, hoursAgo: 30);

        $this->artisan('oidc:requeue-logout-deliveries')
            ->expectsOutputToContain('Sent again: 0')
            ->expectsOutputToContain('older than the limit: 1')
            ->assertExitCode(0);
        $this->assertSame('FAILED', $delivery->fresh()->status);

        $this->artisan('oidc:requeue-logout-deliveries', ['--max-age' => 48])->expectsOutputToContain('Sent again: 1')->assertExitCode(0);
        $this->assertSame('PENDING', $delivery->fresh()->status);
    }

    public function test_an_access_revoked_call_is_repeated_while_the_user_is_still_suspended_however_old_it_is(): void
    {
        $this->user->update(['status' => User::STATUS_SUSPENDED]);
        $delivery = $this->failed($this->radarClient, 'ACCESS_REVOKED', 'user_suspended', hoursAgo: 24 * 10);

        $this->artisan('oidc:requeue-logout-deliveries')->expectsOutputToContain('Sent again: 1')->assertExitCode(0);

        $this->assertSame('PENDING', $delivery->fresh()->status);
        $this->assertSame([$delivery->id], $this->queuedIds());
    }

    public function test_an_access_revoked_call_is_not_repeated_after_the_user_was_reinstated(): void
    {
        $delivery = $this->failed($this->radarClient, 'ACCESS_REVOKED', 'user_suspended');

        $this->artisan('oidc:requeue-logout-deliveries')
            ->expectsOutputToContain('Sent again: 0')
            ->expectsOutputToContain('access has been restored: 1')
            ->assertExitCode(0);

        $this->assertSame('FAILED', $delivery->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_tenant_scoped_call_follows_the_access_the_user_has_in_that_tenant_now(): void
    {
        $this->tenant->applications()->updateExistingPivot($this->radar->id, ['status' => 'SUSPENDED']);
        $stillDenied = $this->failed($this->radarClient, 'ACCESS_REVOKED', 'application_not_assigned', $this->tenant);
        // Access to OptiFleet in the same tenant was never taken away.
        $restored = $this->failed($this->fleetClient, 'ACCESS_REVOKED', 'application_access_revoked', $this->tenant);

        $this->artisan('oidc:requeue-logout-deliveries')
            ->expectsOutputToContain('Sent again: 1')
            ->expectsOutputToContain('access has been restored: 1')
            ->assertExitCode(0);

        $this->assertSame('PENDING', $stillDenied->fresh()->status);
        $this->assertSame('FAILED', $restored->fresh()->status);
    }

    public function test_an_application_access_revocation_without_tenant_is_repeated_only_while_the_grant_is_gone(): void
    {
        $delivery = $this->failed($this->radarClient, 'ACCESS_REVOKED', 'application_access_revoked');

        // The grant is still there: nothing to take away.
        $this->artisan('oidc:requeue-logout-deliveries')->expectsOutputToContain('Sent again: 0')->assertExitCode(0);
        $this->assertSame('FAILED', $delivery->fresh()->status);

        UserApplicationAccess::query()->where('user_id', $this->user->id)->where('application_id', $this->radar->id)
            ->update(['status' => 'REVOKED']);

        $this->artisan('oidc:requeue-logout-deliveries')->expectsOutputToContain('Sent again: 1')->assertExitCode(0);
        $this->assertSame('PENDING', $delivery->fresh()->status);
    }

    public function test_an_application_that_lost_its_endpoint_or_was_revoked_is_skipped(): void
    {
        $noEndpoint = $this->failed($this->radarClient);
        $this->radarClient->update(['backchannel_logout_uri' => null]);
        $revokedClient = $this->failed($this->fleetClient);
        $this->fleetClient->update(['status' => OidcClient::STATUS_REVOKED]);

        $this->artisan('oidc:requeue-logout-deliveries')
            ->expectsOutputToContain('Sent again: 0')
            ->expectsOutputToContain('no usable logout endpoint: 2')
            ->assertExitCode(0);

        $this->assertSame('FAILED', $noEndpoint->fresh()->status);
        $this->assertSame('FAILED', $revokedClient->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_dry_run_reports_without_changing_anything(): void
    {
        $delivery = $this->failed($this->radarClient);

        $this->artisan('oidc:requeue-logout-deliveries', ['--dry-run' => true])
            ->expectsOutputToContain('Would send again: 1')
            ->assertExitCode(0);

        $delivery->refresh();
        $this->assertSame('FAILED', $delivery->status);
        $this->assertSame(6, $delivery->attempts);
        $this->assertSame(503, $delivery->last_http_status);
        Queue::assertNothingPushed();
        $this->assertSame(0, AuditLog::query()->where('action', 'oidc.logout_deliveries_requeued')->count());
    }

    public function test_the_client_and_user_filters_narrow_the_selection(): void
    {
        $other = $this->makeSsoUser($this->tenant, $this->radar);
        $radarOfUser = $this->failed($this->radarClient);
        $fleetOfUser = $this->failed($this->fleetClient);
        $radarOfOther = $this->failed($this->radarClient, user: $other);

        $this->artisan('oidc:requeue-logout-deliveries', ['--client' => $this->radarClient->client_id, '--user' => $this->user->id])
            ->expectsOutputToContain('Sent again: 1')
            ->assertExitCode(0);

        $this->assertSame('PENDING', $radarOfUser->fresh()->status);
        $this->assertSame('FAILED', $fleetOfUser->fresh()->status);
        $this->assertSame('FAILED', $radarOfOther->fresh()->status);

        $this->artisan('oidc:requeue-logout-deliveries', ['--client' => $this->fleetClient->client_id])
            ->expectsOutputToContain('Sent again: 1')
            ->assertExitCode(0);
        $this->assertSame('PENDING', $fleetOfUser->fresh()->status);
        $this->assertSame('FAILED', $radarOfOther->fresh()->status);
    }

    public function test_the_run_is_recorded_in_the_audit_log_and_a_second_run_finds_nothing(): void
    {
        $this->failed($this->radarClient);
        $this->failed($this->fleetClient);

        $this->artisan('oidc:requeue-logout-deliveries')->assertExitCode(0);

        $audit = AuditLog::query()->where('action', 'oidc.logout_deliveries_requeued')->sole();
        $this->assertSame(2, $audit->new_value['requeued']);

        $this->artisan('oidc:requeue-logout-deliveries')->expectsOutputToContain('Sent again: 0')->assertExitCode(0);
        $this->assertSame(1, AuditLog::query()->where('action', 'oidc.logout_deliveries_requeued')->count());
    }
}
