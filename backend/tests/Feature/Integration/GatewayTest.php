<?php

namespace Tests\Feature\Integration;

use App\Models\Application;
use App\Models\GatewayFleetVehicle;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlatformIntegrationPermissionSeeder;
use Database\Seeders\PlatformIntegrationRoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\TestCase;

class GatewayTest extends TestCase
{
    use CreatesIntegrationFixtures, RefreshDatabase;

    private Tenant $tenant;

    private Application $fleet;

    private Application $radar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->makeTenant();
        $this->fleet = $this->makeApplication(['application_code' => 'optifleet']);
        $this->radar = $this->makeApplication(['application_code' => 'optiradar']);
        $this->tenant->applications()->attach($this->fleet->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $this->tenant->applications()->attach($this->radar->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
    }

    private function as(string $token, ?Tenant $tenantHeader = null): static
    {
        $headers = ['Authorization' => "Bearer {$token}"];
        if ($tenantHeader) {
            $headers['X-Tenant-Id'] = $tenantHeader->id;
        }

        return $this->withHeaders($headers);
    }

    private function fleetToken(): string
    {
        return $this->serviceToken($this->fleet, ['gateway.fleet.write', 'gateway.fleet.read', 'gateway.telematics.read'], $this->tenant);
    }

    private function radarToken(): string
    {
        return $this->serviceToken($this->radar, ['gateway.telematics.write'], $this->tenant);
    }

    private function publish(array $vehicles): void
    {
        $this->as($this->fleetToken())->putJson('/api/gateway/v1/fleet/vehicles', ['vehicles' => $vehicles])->assertOk();
    }

    private function reading(string $device, string $km, string $at, array $extra = []): array
    {
        return array_merge(['device_ref' => $device, 'odometer_km' => $km, 'recorded_at' => $at], $extra);
    }

    public function test_readings_flow_from_radar_to_fleet_mapped_by_registration_number(): void
    {
        $this->publish([['id' => 'veh-1', 'registration_number' => 'B 1234 XYZ', 'vin' => 'VIN1']]);

        $this->as($this->radarToken())->postJson('/api/gateway/v1/telematics/odometer-readings', ['readings' => [
            $this->reading('dev-9', '12500.5', '2026-10-08T01:00:00Z', ['device_name' => 'b-1234-xyz']),
        ]])->assertStatus(202)->assertJsonPath('data.accepted', 1);

        $feed = $this->as($this->fleetToken())->getJson('/api/gateway/v1/telematics/odometer-readings')->assertOk();
        $feed->assertJsonPath('data.items.0.vehicle_id', 'veh-1')
            ->assertJsonPath('data.items.0.odometer_km', '12500.50')
            ->assertJsonPath('data.items.0.odometer_kind', 'DEVICE_ODOMETER')
            ->assertJsonPath('data.items.0.registration_number', 'B 1234 XYZ');
    }

    public function test_readings_are_idempotent_and_cursor_pages_forward(): void
    {
        $this->publish([['id' => 'veh-1', 'registration_number' => 'B1234XYZ']]);
        $radar = $this->radarToken();
        $batch = ['readings' => [
            $this->reading('dev-9', '100', '2026-10-08T01:00:00Z', ['registration_number' => 'B1234XYZ']),
            $this->reading('dev-9', '110', '2026-10-08T02:00:00Z'),
        ]];

        $this->as($radar)->postJson('/api/gateway/v1/telematics/odometer-readings', $batch)->assertJsonPath('data.accepted', 2);
        $this->as($radar)->postJson('/api/gateway/v1/telematics/odometer-readings', $batch)
            ->assertJsonPath('data.accepted', 0)->assertJsonPath('data.duplicates', 2);

        $fleet = $this->fleetToken();
        $page = $this->as($fleet)->getJson('/api/gateway/v1/telematics/odometer-readings?limit=1')->assertOk();
        $this->assertCount(1, $page->json('data.items'));

        $next = $this->as($fleet)->getJson('/api/gateway/v1/telematics/odometer-readings?cursor='.$page->json('data.next_cursor'))->json('data');
        $this->assertCount(1, $next['items']);
        $this->assertSame('110.00', $next['items'][0]['odometer_km']);

        $end = $this->as($fleet)->getJson('/api/gateway/v1/telematics/odometer-readings?cursor='.$next['next_cursor'])->json('data');
        $this->assertSame([], $end['items']);
    }

    public function test_unmatched_device_is_linked_later_and_its_latest_reading_is_delivered(): void
    {
        // Reading arrives first: no vehicle with that plate exists yet.
        $this->as($this->radarToken())->postJson('/api/gateway/v1/telematics/odometer-readings', ['readings' => [
            $this->reading('dev-5', '999', '2026-10-08T01:00:00Z', ['registration_number' => 'D 55 AA']),
        ]])->assertStatus(202);

        $this->assertSame([], $this->as($this->fleetToken())->getJson('/api/gateway/v1/telematics/odometer-readings')->json('data.items'));

        // The fleet app publishes the vehicle afterwards - the stationary device must still deliver.
        $this->publish([['id' => 'veh-5', 'registration_number' => 'D55AA']]);

        $items = $this->as($this->fleetToken())->getJson('/api/gateway/v1/telematics/odometer-readings')->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('veh-5', $items[0]['vehicle_id']);
    }

    public function test_ambiguous_plate_is_not_auto_linked(): void
    {
        $this->publish([
            ['id' => 'a', 'registration_number' => 'B 1 AA'],
            ['id' => 'b', 'registration_number' => 'B1-AA'],
        ]);
        $this->as($this->radarToken())->postJson('/api/gateway/v1/telematics/odometer-readings', ['readings' => [
            $this->reading('dev-1', '5', '2026-10-08T01:00:00Z', ['registration_number' => 'B1AA']),
        ]])->assertStatus(202);

        $this->assertSame([], $this->as($this->fleetToken())->getJson('/api/gateway/v1/telematics/odometer-readings')->json('data.items'));
    }

    public function test_tenant_isolation_between_tenants(): void
    {
        $this->publish([['id' => 'veh-1', 'registration_number' => 'B1234XYZ']]);
        $this->as($this->radarToken())->postJson('/api/gateway/v1/telematics/odometer-readings', ['readings' => [
            $this->reading('dev-9', '1', '2026-10-08T01:00:00Z', ['registration_number' => 'B1234XYZ']),
        ]]);

        $other = $this->makeTenant(null, ['name' => 'Other']);
        $other->applications()->attach($this->fleet->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        $otherFleet = $this->serviceToken($this->fleet, ['gateway.telematics.read'], $other);

        $this->as($otherFleet)->getJson('/api/gateway/v1/telematics/odometer-readings')->assertOk()->assertJsonPath('data.items', []);
    }

    public function test_tenant_bound_account_cannot_switch_tenant_by_header(): void
    {
        $other = $this->makeTenant(null, ['name' => 'Other']);
        $other->applications()->attach($this->fleet->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);

        $this->as($this->fleetToken(), $other)->getJson('/api/gateway/v1/vehicle-links')
            ->assertStatus(403)->assertJsonPath('error.code', 'TENANT_MISMATCH');
    }

    public function test_platform_account_must_name_a_subscribed_tenant(): void
    {
        $platform = $this->serviceToken($this->fleet, ['gateway.fleet.read']);

        $this->as($platform)->getJson('/api/gateway/v1/vehicle-links')->assertStatus(422)->assertJsonPath('error.code', 'TENANT_REQUIRED');
        $this->as($platform, $this->tenant)->getJson('/api/gateway/v1/vehicle-links')->assertOk();

        $unsubscribed = $this->makeTenant(null, ['name' => 'No fleet']);
        $this->as($platform, $unsubscribed)->getJson('/api/gateway/v1/vehicle-links')
            ->assertStatus(403)->assertJsonPath('error.code', 'TENANT_NOT_SUBSCRIBED');
    }

    public function test_suspended_tenant_is_refused(): void
    {
        $token = $this->fleetToken();
        $this->tenant->update(['status' => Tenant::STATUS_SUSPENDED]);

        $this->as($token)->getJson('/api/gateway/v1/vehicle-links')->assertStatus(403)->assertJsonPath('error.code', 'TENANT_NOT_SUBSCRIBED');
    }

    public function test_scopes_are_enforced(): void
    {
        $readOnly = $this->serviceToken($this->fleet, ['gateway.fleet.read'], $this->tenant);

        $this->as($readOnly)->putJson('/api/gateway/v1/fleet/vehicles', ['vehicles' => [['id' => '1', 'registration_number' => 'X']]])->assertStatus(403);
        $this->flushHeaders()->getJson('/api/gateway/v1/vehicle-links')->assertStatus(401);
    }

    public function test_odometer_kind_is_validated_and_returned(): void
    {
        $this->publish([['id' => 'veh-1', 'registration_number' => 'B1234XYZ']]);

        $this->as($this->radarToken())->postJson('/api/gateway/v1/telematics/odometer-readings', ['readings' => [
            $this->reading('dev-9', '10', '2026-10-08T01:00:00Z', ['registration_number' => 'B1234XYZ', 'odometer_kind' => 'BOGUS']),
        ]])->assertStatus(422);

        $this->as($this->radarToken())->postJson('/api/gateway/v1/telematics/odometer-readings', ['readings' => [
            $this->reading('dev-9', '10', '2026-10-08T01:00:00Z', ['registration_number' => 'B1234XYZ', 'odometer_kind' => 'GPS_DISTANCE']),
        ]])->assertStatus(202);

        $this->as($this->fleetToken())->getJson('/api/gateway/v1/telematics/odometer-readings')
            ->assertJsonPath('data.items.0.odometer_kind', 'GPS_DISTANCE');
    }

    public function test_validation_rejects_negative_odometer(): void
    {
        $this->as($this->radarToken())->postJson('/api/gateway/v1/telematics/odometer-readings', ['readings' => [
            $this->reading('dev-1', '-5', '2026-10-08T01:00:00Z'),
        ]])->assertStatus(422);
    }

    public function test_requests_are_logged_per_tenant(): void
    {
        $this->as($this->fleetToken())->getJson('/api/gateway/v1/vehicle-links')->assertOk();

        $this->assertDatabaseHas('gateway_request_logs', ['tenant_id' => $this->tenant->id, 'path' => '/api/gateway/v1/vehicle-links', 'status_code' => 200]);
    }

    public function test_admin_can_link_manually_and_manual_links_survive_new_readings(): void
    {
        $this->publish([['id' => 'veh-1', 'registration_number' => 'B1234XYZ'], ['id' => 'veh-2', 'registration_number' => 'L9999ZZ']]);
        $this->as($this->radarToken())->postJson('/api/gateway/v1/telematics/odometer-readings', ['readings' => [
            $this->reading('dev-7', '50', '2026-10-08T01:00:00Z', ['registration_number' => 'NOPE']),
        ]]);

        $this->seed(PermissionSeeder::class);
        $this->seed(PlatformIntegrationPermissionSeeder::class);
        $this->seed(PlatformIntegrationRoleSeeder::class);
        $user = User::factory()->create(['status' => 'ACTIVE']);
        $user->userRoles()->create(['role_id' => Role::where('code', 'PLATFORM_INTEGRATION_ADMIN')->first()->id, 'tenant_id' => null]);
        Sanctum::actingAs($user);

        $unmatched = $this->getJson("/api/v1/gateway/tenants/{$this->tenant->id}/vehicle-links?status=UNMATCHED")->assertOk()->json('data');
        $this->assertCount(1, $unmatched);

        $vehicle = GatewayFleetVehicle::where('external_vehicle_id', 'veh-2')->first();
        $this->putJson("/api/v1/gateway/tenants/{$this->tenant->id}/vehicle-links/{$unmatched[0]['id']}", ['fleet_vehicle_id' => $vehicle->id])
            ->assertOk()->assertJsonPath('data.link_type', 'MANUAL');

        // A later reading with a plate that would match veh-1 must not move the manual link.
        $this->as($this->radarToken())->postJson('/api/gateway/v1/telematics/odometer-readings', ['readings' => [
            $this->reading('dev-7', '60', '2026-10-08T02:00:00Z', ['registration_number' => 'B1234XYZ']),
        ]]);

        $items = $this->as($this->fleetToken())->getJson('/api/gateway/v1/telematics/odometer-readings')->json('data.items');
        $this->assertSame(['veh-2'], array_values(array_unique(array_column($items, 'vehicle_id'))));
    }

    public function test_admin_endpoints_require_permission(): void
    {
        Sanctum::actingAs(User::factory()->create(['status' => 'ACTIVE']));

        $this->getJson("/api/v1/gateway/tenants/{$this->tenant->id}/vehicle-links")->assertStatus(403);
    }
}
