<?php

namespace Tests\Feature\Integration;

use App\Models\Application;
use App\Models\Event;
use App\Models\EventCatalogEntry;
use App\Models\Tenant;
use Database\Seeders\OptiFleetEventCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\TestCase;

class OptiFleetEventCatalogTest extends TestCase
{
    use CreatesIntegrationFixtures, RefreshDatabase;

    private function fleetToken(Tenant $tenant): string
    {
        return $this->serviceToken($this->fleet, ['event.write'], $tenant);
    }

    private Application $fleet;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fleet = $this->makeApplication(['application_code' => 'optifleet', 'name' => 'OptiFleet']);
        $this->tenant = $this->makeTenant();
        $this->tenant->applications()->attach($this->fleet->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
    }

    private function envelope(string $key, array $override = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'event_key' => $key,
            'event_version' => '1',
            'tenant_id' => $this->tenant->id,
            'occurred_at' => now()->toIso8601String(),
            'data' => [
                'aggregate_type' => 'workshop_invoice',
                'aggregate_id' => (string) Str::uuid(),
                'correlation' => ['work_order_id' => (string) Str::uuid()],
                'payload' => ['external_invoice_number' => 'INV-1', 'total_amount' => '1500000.00', 'currency' => 'IDR'],
            ],
        ], $override);
    }

    public function test_the_seeder_registers_every_optifleet_event_once_and_is_repeatable(): void
    {
        $this->seed(OptiFleetEventCatalogSeeder::class);
        $this->seed(OptiFleetEventCatalogSeeder::class);

        $entries = EventCatalogEntry::query()->where('application_id', $this->fleet->id)->get();
        $this->assertEqualsCanonicalizing(array_keys(OptiFleetEventCatalogSeeder::EVENTS), $entries->pluck('event_key')->all());
        $this->assertTrue($entries->every(fn ($entry) => $entry->isActive()));
    }

    public function test_the_seeder_waits_politely_when_the_application_is_not_registered_yet(): void
    {
        $this->fleet->delete();

        $this->seed(OptiFleetEventCatalogSeeder::class);

        $this->assertSame(0, EventCatalogEntry::query()->count());
    }

    public function test_optifleet_can_report_a_registered_event_and_a_replay_is_idempotent(): void
    {
        $this->seed(OptiFleetEventCatalogSeeder::class);
        $token = $this->fleetToken($this->tenant);
        $envelope = $this->envelope('optifleet.workshop_invoice.recorded');

        $this->withToken($token)->postJson('/api/v1/events', $envelope)->assertStatus(201);
        $this->withToken($token)->postJson('/api/v1/events', $envelope)->assertStatus(200);

        $event = Event::query()->sole();
        $this->assertSame($this->fleet->id, $event->source_application_id);
        $this->assertSame($this->tenant->id, $event->tenant_id);
        $this->assertSame('1500000.00', $event->data['payload']['total_amount']);
    }

    public function test_an_unregistered_event_key_is_refused(): void
    {
        $token = $this->fleetToken($this->tenant);

        $this->withToken($token)->postJson('/api/v1/events', $this->envelope('optifleet.workshop_invoice.recorded'))
            ->assertStatus(422)->assertJsonPath('error.code', 'EVENT_INVALID');
    }

    public function test_a_payload_that_breaks_the_registered_shape_is_refused(): void
    {
        $this->seed(OptiFleetEventCatalogSeeder::class);
        $token = $this->fleetToken($this->tenant);
        $bad = $this->envelope('optifleet.workshop_invoice.recorded', ['data' => ['aggregate_type' => 'workshop_invoice', 'payload' => 'not an object']]);

        $this->withToken($token)->postJson('/api/v1/events', $bad)->assertStatus(422)->assertJsonPath('error.code', 'EVENT_SCHEMA_INVALID');
    }

    public function test_a_tenant_that_is_not_subscribed_to_optifleet_is_refused(): void
    {
        $this->seed(OptiFleetEventCatalogSeeder::class);
        $stranger = $this->makeTenant();
        $token = $this->serviceToken($this->fleet, ['event.write']);

        $this->withToken($token)->postJson('/api/v1/events', $this->envelope('optifleet.workshop_invoice.recorded', ['tenant_id' => $stranger->id]))
            ->assertStatus(403)->assertJsonPath('error.code', 'EVENT_SOURCE_DENIED');
    }
}
