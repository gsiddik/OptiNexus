<?php

namespace Tests\Feature\Integration;

use App\Models\Application;
use App\Models\Event;
use App\Models\EventCatalogEntry;
use App\Models\EventDelivery;
use App\Models\ServiceAccount;
use Database\Seeders\OptiFleetEventCatalogSeeder;
use Database\Seeders\OptiRadarEventCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\Concerns\CreatesOrchestrationFixtures;
use Tests\TestCase;

class EventKeyOwnershipTest extends TestCase
{
    use CreatesIntegrationFixtures, CreatesOrchestrationFixtures, RefreshDatabase;

    private Application $radar;

    private Application $fleet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->radar = $this->makeApplication(['application_code' => 'optiradar', 'name' => 'OptiRadar']);
        $this->fleet = $this->makeApplication(['application_code' => 'optifleet', 'name' => 'OptiFleet']);
    }

    private function entry(string $key, ?Application $owner, string $status = 'ACTIVE'): EventCatalogEntry
    {
        return EventCatalogEntry::create([
            'event_key' => $key,
            'application_id' => $owner?->id,
            'name' => 'Test event',
            'schema_version' => '1',
            'payload_schema' => ['required' => ['amount'], 'properties' => ['amount' => ['type' => 'number']]],
            'status' => $status,
        ]);
    }

    /** A service account that belongs to no application: a platform account. */
    private function platformToken(): string
    {
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient('platform-producer');
        ServiceAccount::create(['oauth_client_id' => $client->id, 'name' => 'platform-producer', 'status' => ServiceAccount::STATUS_ACTIVE]);

        return $this->postJson('/api/v1/oauth/token', [
            'grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => $client->plainSecret,
            'scope' => 'event.write',
        ])->assertStatus(200)->json('access_token');
    }

    private function send(string $token, string $key, array $extra = []): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/events', array_merge(['event_key' => $key, 'data' => ['amount' => 5]], $extra));
    }

    public function test_an_application_can_send_the_keys_registered_to_it(): void
    {
        $this->entry('own.thing.happened', $this->radar);

        $this->send($this->serviceToken($this->radar, ['event.write']), 'own.thing.happened')->assertStatus(201);

        $this->assertSame($this->radar->id, Event::query()->sole()->source_application_id);
    }

    public function test_an_application_cannot_send_a_key_registered_to_another_one(): void
    {
        $this->entry('fleet.thing.happened', $this->fleet);

        $this->send($this->serviceToken($this->radar, ['event.write']), 'fleet.thing.happened')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'EVENT_SOURCE_DENIED');

        $this->assertSame(0, Event::query()->count());
        $this->assertSame(0, EventDelivery::query()->count());
    }

    public function test_the_refusal_comes_before_anything_is_revealed_about_the_key(): void
    {
        $this->entry('fleet.parked.event', $this->fleet, EventCatalogEntry::STATUS_DISABLED);
        $radarToken = $this->serviceToken($this->radar, ['event.write']);
        $fleetToken = $this->serviceToken($this->fleet, ['event.write']);

        // A stranger is told it is not theirs, not that the key is switched off or that its payload is wrong.
        $this->send($radarToken, 'fleet.parked.event', ['data' => ['amount' => 'not a number']])
            ->assertStatus(403)->assertJsonPath('error.code', 'EVENT_SOURCE_DENIED');

        // The owner still gets the real reason.
        $this->send($fleetToken, 'fleet.parked.event')
            ->assertStatus(422)->assertJsonPath('error.code', 'EVENT_INVALID');
    }

    public function test_a_key_without_an_application_is_only_for_platform_accounts(): void
    {
        $this->entry('platform.thing.happened', null);

        $this->send($this->serviceToken($this->radar, ['event.write']), 'platform.thing.happened')
            ->assertStatus(403)->assertJsonPath('error.code', 'EVENT_SOURCE_DENIED');

        $this->send($this->platformToken(), 'platform.thing.happened')->assertStatus(201);
        $this->assertSame(1, Event::query()->count());
    }

    public function test_a_platform_account_cannot_send_a_key_registered_to_an_application(): void
    {
        $this->entry('radar.thing.happened', $this->radar);

        $this->send($this->platformToken(), 'radar.thing.happened')
            ->assertStatus(403)->assertJsonPath('error.code', 'EVENT_SOURCE_DENIED');

        $this->assertSame(0, Event::query()->count());
    }

    public function test_another_application_cannot_replay_an_event_id_to_read_or_touch_the_original(): void
    {
        $this->entry('radar.replay.event', $this->radar);
        $eventId = (string) Str::uuid();

        $this->send($this->serviceToken($this->radar, ['event.write']), 'radar.replay.event', ['event_id' => $eventId])->assertStatus(201);
        $this->send($this->serviceToken($this->fleet, ['event.write']), 'radar.replay.event', ['event_id' => $eventId])
            ->assertStatus(403)->assertJsonPath('error.code', 'EVENT_SOURCE_DENIED');

        $this->assertSame($this->radar->id, Event::query()->sole()->source_application_id);
    }

    public function test_the_shipped_catalogs_keep_optiradar_and_optifleet_apart(): void
    {
        $this->seed(OptiRadarEventCatalogSeeder::class);
        $this->seed(OptiFleetEventCatalogSeeder::class);
        $radarToken = $this->serviceToken($this->radar, ['event.write']);
        $fleetToken = $this->serviceToken($this->fleet, ['event.write']);
        $data = ['aggregate_type' => 'thing', 'aggregate_id' => '1', 'payload' => ['x' => 1]];
        $radarKey = array_key_first(OptiRadarEventCatalogSeeder::EVENTS);
        $fleetKey = array_key_first(OptiFleetEventCatalogSeeder::EVENTS);

        $this->send($radarToken, $fleetKey, ['data' => $data])->assertStatus(403)->assertJsonPath('error.code', 'EVENT_SOURCE_DENIED');
        $this->send($fleetToken, $radarKey, ['data' => $data])->assertStatus(403)->assertJsonPath('error.code', 'EVENT_SOURCE_DENIED');
        $this->assertSame(0, Event::query()->count());

        $this->send($radarToken, $radarKey, ['data' => $data])->assertStatus(201);
        $this->send($fleetToken, $fleetKey, ['data' => $data])->assertStatus(201);
        $this->assertSame(2, Event::query()->count());
    }

    public function test_a_key_without_an_application_can_be_handed_to_one(): void
    {
        $entry = $this->entry('platform.claimed.event', null);
        $this->actingAsOrchestrationRole('EVENT_ADMIN');

        $this->putJson("/api/v1/event-catalog/{$entry->id}", ['application_id' => $this->radar->id])
            ->assertOk()->assertJsonPath('data.application_id', $this->radar->id);

        $this->assertSame($this->radar->id, $entry->fresh()->application_id);
    }

    public function test_a_claimed_key_is_then_only_sent_by_its_new_owner(): void
    {
        $entry = $this->entry('platform.claimed.event', null);
        $entry->update(['application_id' => $this->radar->id]);

        $this->send($this->serviceToken($this->radar, ['event.write']), 'platform.claimed.event')->assertStatus(201);
        $this->send($this->platformToken(), 'platform.claimed.event')->assertStatus(403);
    }

    public function test_an_owned_key_cannot_be_given_to_another_application(): void
    {
        $entry = $this->entry('radar.owned.event', $this->radar);
        $this->actingAsOrchestrationRole('EVENT_ADMIN');

        $this->putJson("/api/v1/event-catalog/{$entry->id}", ['application_id' => $this->fleet->id])
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
        $this->assertSame($this->radar->id, $entry->fresh()->application_id);

        // Naming the same owner again is harmless, and other fields still update.
        $this->putJson("/api/v1/event-catalog/{$entry->id}", ['application_id' => $this->radar->id, 'name' => 'Renamed'])
            ->assertOk()->assertJsonPath('data.name', 'Renamed');
    }
}
