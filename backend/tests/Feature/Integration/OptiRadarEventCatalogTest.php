<?php

namespace Tests\Feature\Integration;

use App\Models\Application;
use App\Models\Event;
use App\Models\EventCatalogEntry;
use App\Models\Tenant;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\OptiFleetEventCatalogSeeder;
use Database\Seeders\OptiRadarEventCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\TestCase;

class OptiRadarEventCatalogTest extends TestCase
{
    use CreatesIntegrationFixtures, RefreshDatabase;

    private Application $radar;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->radar = $this->makeApplication(['application_code' => 'optiradar', 'name' => 'OptiRadar']);
        $this->tenant = $this->makeTenant();
        $this->tenant->applications()->attach($this->radar->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
    }

    private function radarToken(?Tenant $tenant = null): string
    {
        return $this->serviceToken($this->radar, ['event.write'], $tenant ?? $this->tenant);
    }

    /** The shape OptiRadar sends (see OptinexusEventRecorder in the OptiRadar fork). */
    private function envelope(string $key, array $payload = [], array $override = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'event_key' => $key,
            'event_version' => '1',
            'tenant_id' => $this->tenant->id,
            'occurred_at' => now()->toIso8601String(),
            'correlation_id' => 'device:10',
            'data' => [
                'aggregate_type' => 'device',
                'aggregate_id' => '10',
                'correlation' => ['unique_id' => 'IMEI10', 'position_id' => 77],
                'payload' => array_merge([
                    'device_id' => 10,
                    'device_name' => 'Truck 10',
                    'unique_id' => 'IMEI10',
                    'event_time' => now()->toIso8601String(),
                    'latitude' => -6.2,
                    'longitude' => 106.8,
                    'speed_kmh' => 100.0,
                ], $payload),
            ],
        ], $override);
    }

    public function test_the_seeder_registers_the_five_optiradar_events_once_and_is_repeatable(): void
    {
        $this->seed(OptiRadarEventCatalogSeeder::class);
        $this->seed(OptiRadarEventCatalogSeeder::class);

        $entries = EventCatalogEntry::query()->where('application_id', $this->radar->id)->get();
        $this->assertEqualsCanonicalizing([
            'optiradar.device.online',
            'optiradar.device.offline',
            'optiradar.geofence.entered',
            'optiradar.geofence.exited',
            'optiradar.device.overspeed',
        ], $entries->pluck('event_key')->all());
        $this->assertTrue($entries->every(fn ($entry) => $entry->isActive()));
    }

    public function test_the_seeder_waits_politely_when_the_application_is_not_registered_yet(): void
    {
        $this->radar->delete();

        $this->seed(OptiRadarEventCatalogSeeder::class);

        $this->assertSame(0, EventCatalogEntry::query()->count());
    }

    public function test_every_registered_event_is_accepted_and_a_replay_is_idempotent(): void
    {
        $this->seed(OptiRadarEventCatalogSeeder::class);
        $token = $this->radarToken();

        foreach (array_keys(OptiRadarEventCatalogSeeder::EVENTS) as $key) {
            $envelope = $this->envelope($key, $key === 'optiradar.device.overspeed' ? ['speed_limit_kmh' => 50.0] : []);

            $this->withToken($token)->postJson('/api/v1/events', $envelope)->assertStatus(201);
            $this->withToken($token)->postJson('/api/v1/events', $envelope)->assertStatus(200);
        }

        $this->assertSame(5, Event::query()->count());
        $event = Event::query()->where('event_key', 'optiradar.device.overspeed')->sole();
        $this->assertSame($this->radar->id, $event->source_application_id);
        $this->assertSame($this->tenant->id, $event->tenant_id);
        $this->assertEquals(50.0, $event->data['payload']['speed_limit_kmh']);
        $this->assertSame('device:10', $event->correlation_id);
    }

    public function test_an_unregistered_event_key_is_refused_until_the_catalog_is_seeded(): void
    {
        $token = $this->radarToken();
        $envelope = $this->envelope('optiradar.device.offline');

        $this->withToken($token)->postJson('/api/v1/events', $envelope)
            ->assertStatus(422)->assertJsonPath('error.code', 'EVENT_INVALID');

        $this->seed(OptiRadarEventCatalogSeeder::class);

        $this->withToken($token)->postJson('/api/v1/events', $envelope)->assertStatus(201);
    }

    public function test_a_payload_that_breaks_the_registered_shape_is_refused(): void
    {
        $this->seed(OptiRadarEventCatalogSeeder::class);
        $bad = $this->envelope('optiradar.device.online', [], ['data' => ['aggregate_type' => 'device', 'payload' => 'not an object']]);

        $this->withToken($this->radarToken())->postJson('/api/v1/events', $bad)
            ->assertStatus(422)->assertJsonPath('error.code', 'EVENT_SCHEMA_INVALID');
    }

    public function test_a_tenant_that_is_not_subscribed_to_optiradar_is_refused(): void
    {
        $this->seed(OptiRadarEventCatalogSeeder::class);
        $stranger = $this->makeTenant();

        $this->withToken($this->serviceToken($this->radar, ['event.write']))
            ->postJson('/api/v1/events', $this->envelope('optiradar.device.online', [], ['tenant_id' => $stranger->id]))
            ->assertStatus(403)->assertJsonPath('error.code', 'EVENT_SOURCE_DENIED');
    }

    public function test_the_default_seed_registers_the_events_of_both_platforms(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(
            count(OptiRadarEventCatalogSeeder::EVENTS),
            EventCatalogEntry::query()->where('event_key', 'like', 'optiradar.%')->count(),
        );
        $this->assertSame(
            count(OptiFleetEventCatalogSeeder::EVENTS),
            EventCatalogEntry::query()->where('event_key', 'like', 'optifleet.%')->count(),
        );
    }
}
