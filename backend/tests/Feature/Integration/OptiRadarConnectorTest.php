<?php

namespace Tests\Feature\Integration;

use App\Services\Gateway\OptiRadarConnector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\TestCase;

class OptiRadarConnectorTest extends TestCase
{
    use CreatesIntegrationFixtures, RefreshDatabase;

    public function test_sync_maps_group_to_tenant_and_converts_metres_to_km(): void
    {
        config(['gateway.optiradar.base_url' => 'http://radar.test', 'gateway.optiradar.token' => 'tok']);

        $tenant = $this->makeTenant();
        $fleet = $this->makeApplication(['application_code' => 'optifleet']);
        $radar = $this->makeApplication(['application_code' => 'optiradar']);
        $tenant->applications()->attach($radar->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);

        $other = $this->makeTenant(null, ['name' => 'Not subscribed']);

        Http::fake([
            'radar.test/api/groups' => Http::response([
                ['id' => 1, 'name' => 'Acme', 'attributes' => ['optinexusTenantId' => $tenant->id]],
                ['id' => 2, 'name' => 'Acme Jakarta', 'groupId' => 1, 'attributes' => []],
                ['id' => 3, 'name' => 'Rogue', 'attributes' => ['optinexusTenantId' => $other->id]],
                ['id' => 4, 'name' => 'No tenant', 'attributes' => []],
            ]),
            'radar.test/api/devices' => Http::response([
                ['id' => 10, 'name' => 'B 1234 XYZ', 'groupId' => 2, 'attributes' => []],
                ['id' => 11, 'name' => 'Truck', 'groupId' => 1, 'attributes' => ['plate' => 'D 9 AA']],
                ['id' => 12, 'name' => 'Other tenant', 'groupId' => 3, 'attributes' => []],
                ['id' => 13, 'name' => 'Ungrouped', 'groupId' => 4, 'attributes' => []],
                ['id' => 14, 'name' => 'No position', 'groupId' => 1, 'attributes' => []],
            ]),
            'radar.test/api/positions' => Http::response([
                ['deviceId' => 10, 'fixTime' => '2026-10-08T01:00:00.000+00:00', 'attributes' => ['totalDistance' => 12345678, 'odometer' => 12500000]],
                ['deviceId' => 11, 'fixTime' => '2026-10-08T01:05:00.000+00:00', 'attributes' => ['totalDistance' => 999]],
                ['deviceId' => 12, 'fixTime' => '2026-10-08T01:00:00.000+00:00', 'attributes' => ['totalDistance' => 5000]],
                ['deviceId' => 13, 'fixTime' => '2026-10-08T01:00:00.000+00:00', 'attributes' => ['totalDistance' => 5000]],
            ]),
        ]);

        $first = app(OptiRadarConnector::class)->sync();
        $this->assertSame(2, $first['accepted']);
        $this->assertSame(3, $first['skipped']);

        // Device-reported odometer wins over GPS distance; metres become km exactly.
        $this->assertDatabaseHas('gateway_odometer_readings', ['tenant_id' => $tenant->id, 'device_ref' => '10', 'odometer_km' => '12500.00']);
        $this->assertDatabaseHas('gateway_odometer_readings', ['tenant_id' => $tenant->id, 'device_ref' => '11', 'odometer_km' => '1.00']);
        $this->assertDatabaseMissing('gateway_odometer_readings', ['device_ref' => '12']);

        // Same fix times again: nothing new.
        $second = app(OptiRadarConnector::class)->sync();
        $this->assertSame(0, $second['accepted']);
        $this->assertSame(2, $second['duplicates']);
    }

    public function test_command_fails_clearly_when_unconfigured(): void
    {
        config(['gateway.optiradar.base_url' => '', 'gateway.optiradar.token' => null]);

        $this->artisan('gateway:sync-optiradar')->expectsOutputToContain('not configured')->assertFailed();
    }
}
