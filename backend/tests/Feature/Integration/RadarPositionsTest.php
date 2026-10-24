<?php

namespace Tests\Feature\Integration;

use App\Models\Application;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\TestCase;

/** K5: an application reads the latest OptiRadar positions of its own tenant through OptiNexus, never another tenant's. */
class RadarPositionsTest extends TestCase
{
    use CreatesGovernanceFixtures, CreatesIntegrationFixtures, RefreshDatabase;

    private Application $deli;

    private Application $radar;

    protected function setUp(): void
    {
        parent::setUp();

        config(['gateway.optiradar.base_url' => 'https://radar.test', 'gateway.optiradar.token' => 'sync-token']);
        Cache::flush();
        $this->deli = $this->makeApplication(['application_code' => 'optideli', 'name' => 'OptiDeli']);
        $this->radar = $this->makeApplication(['application_code' => 'optiradar', 'name' => 'OptiRadar']);
    }

    private function entitled(Tenant $tenant, bool $radar = true): void
    {
        foreach ([[true, $this->deli], [$radar, $this->radar]] as [$yes, $app]) {
            if ($yes) {
                $tenant->applications()->attach($app->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
                $this->makeActiveApplicationSubscription($tenant, $app);
            }
        }
    }

    /** Tenant A owns group 1 (and its child group 3); tenant B owns group 2; group 4 belongs to nobody. */
    private function fakeRadar(Tenant $a, Tenant $b): void
    {
        Http::fake([
            'radar.test/api/groups' => Http::response([
                ['id' => 1, 'name' => 'A', 'groupId' => 0, 'attributes' => ['optinexusTenantId' => $a->id]],
                ['id' => 2, 'name' => 'B', 'groupId' => 0, 'attributes' => ['optinexusTenantId' => $b->id]],
                ['id' => 3, 'name' => 'A depot', 'groupId' => 1, 'attributes' => []],
                ['id' => 4, 'name' => 'Loose', 'groupId' => 0, 'attributes' => []],
            ]),
            'radar.test/api/devices' => Http::response([
                ['id' => 10, 'name' => 'Truck A1', 'groupId' => 1, 'status' => 'online', 'attributes' => ['plate' => 'B 1 AA']],
                ['id' => 11, 'name' => 'Truck A2', 'groupId' => 3, 'status' => 'offline', 'attributes' => []],
                ['id' => 20, 'name' => 'Truck B1', 'groupId' => 2, 'status' => 'online', 'attributes' => []],
                ['id' => 30, 'name' => 'Orphan', 'groupId' => 4, 'status' => 'online', 'attributes' => []],
                ['id' => 40, 'name' => 'No group', 'status' => 'online', 'attributes' => []],
            ]),
            'radar.test/api/positions' => Http::response([
                ['deviceId' => 10, 'latitude' => -6.2, 'longitude' => 106.8, 'speed' => 10, 'course' => 90, 'valid' => true, 'fixTime' => '2026-10-24T10:00:00.000+00:00'],
                ['deviceId' => 20, 'latitude' => 1.0, 'longitude' => 2.0, 'speed' => 0, 'course' => 0, 'valid' => true, 'fixTime' => '2026-10-24T10:00:00.000+00:00'],
            ]),
        ]);
    }

    private function read(string $token, Tenant $tenant)
    {
        return $this->withToken($token)->getJson('/api/v1/radar/positions?tenant_id='.$tenant->id);
    }

    public function test_a_tenant_sees_only_its_own_devices_including_child_groups(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $this->entitled($a);
        $this->fakeRadar($a, $b);

        $response = $this->read($this->serviceToken($this->deli, ['positions.read'], $a), $a)->assertOk();

        $this->assertSame(['10', '11'], collect($response->json('data'))->pluck('device_ref')->sort()->values()->all());
        $this->assertSame(2, $response->json('meta.count'));
        $first = collect($response->json('data'))->firstWhere('device_ref', '10');
        $this->assertSame(['Truck A1', 'B 1 AA', 'online', 18.5], [$first['name'], $first['registration_number'], $first['status'], $first['position']['speed_kmh']]);
        $this->assertNull(collect($response->json('data'))->firstWhere('device_ref', '11')['position']);
        $this->assertStringNotContainsString('Truck B1', $response->getContent());
    }

    public function test_a_tenant_without_a_radar_subscription_is_refused(): void
    {
        $a = $this->makeTenant();
        $this->entitled($a, radar: false);
        Http::fake();

        $this->read($this->serviceToken($this->deli, ['positions.read'], $a), $a)->assertStatus(403)->assertJsonPath('error.code', 'RADAR_NOT_ENTITLED');
        Http::assertNothingSent();
    }

    public function test_a_service_account_bound_to_another_tenant_is_refused(): void
    {
        $mine = $this->makeTenant();
        $other = $this->makeTenant();
        $this->entitled($other);
        Http::fake();

        $this->read($this->serviceToken($this->deli, ['positions.read'], $mine), $other)->assertStatus(403)->assertJsonPath('error.code', 'TENANT_MISMATCH');
        Http::assertNothingSent();
    }

    public function test_a_token_without_the_positions_scope_is_refused(): void
    {
        $a = $this->makeTenant();
        $this->entitled($a);

        $this->read($this->serviceToken($this->deli, ['embed.create'], $a), $a)->assertStatus(403);
    }

    public function test_one_radar_read_is_shared_between_callers(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $this->entitled($a);
        $this->entitled($b);
        $this->fakeRadar($a, $b);
        config(['gateway.optiradar.positions_cache_seconds' => 30]);

        $this->read($this->serviceToken($this->deli, ['positions.read'], $a), $a)->assertOk();
        $this->read($this->serviceToken($this->deli, ['positions.read'], $b), $b)->assertOk()->assertJsonPath('data.0.device_ref', '20');

        Http::assertSentCount(3); // groups, devices, positions: once
    }

    public function test_radar_failing_gives_a_502(): void
    {
        $a = $this->makeTenant();
        $this->entitled($a);
        Http::fake(['*' => Http::response('no', 500)]);

        $this->read($this->serviceToken($this->deli, ['positions.read'], $a), $a)->assertStatus(502)->assertJsonPath('error.code', 'RADAR_UNAVAILABLE');
    }

    public function test_an_unconfigured_radar_gives_a_502_and_sends_nothing(): void
    {
        $a = $this->makeTenant();
        $this->entitled($a);
        config(['gateway.optiradar.token' => null]);
        Http::fake();

        $this->read($this->serviceToken($this->deli, ['positions.read'], $a), $a)->assertStatus(502);
        Http::assertNothingSent();
    }
}
