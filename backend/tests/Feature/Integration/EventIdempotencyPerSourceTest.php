<?php

namespace Tests\Feature\Integration;

use App\Models\Application;
use App\Models\Event;
use App\Models\EventCatalogEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\Concerns\CreatesOrchestrationFixtures;
use Tests\TestCase;

/** Wave 6: an event id is idempotent per producing application, so two applications may reuse the same uuid. */
class EventIdempotencyPerSourceTest extends TestCase
{
    use CreatesIntegrationFixtures, CreatesOrchestrationFixtures, RefreshDatabase;

    private Application $radar;

    private Application $fleet;

    protected function setUp(): void
    {
        parent::setUp();

        $this->radar = $this->makeApplication(['application_code' => 'optiradar', 'name' => 'OptiRadar']);
        $this->fleet = $this->makeApplication(['application_code' => 'optifleet', 'name' => 'OptiFleet']);
        foreach ([['radar.ping', $this->radar], ['fleet.ping', $this->fleet]] as [$key, $owner]) {
            EventCatalogEntry::create([
                'event_key' => $key, 'application_id' => $owner->id, 'name' => 'Ping', 'schema_version' => '1',
                'payload_schema' => ['required' => ['n'], 'properties' => ['n' => ['type' => 'number']]], 'status' => 'ACTIVE',
            ]);
        }
    }

    private function send(Application $app, string $key, string $eventId, int $n = 1)
    {
        return $this->withToken($this->serviceToken($app, ['event.write']))
            ->postJson('/api/v1/events', ['event_key' => $key, 'event_id' => $eventId, 'data' => ['n' => $n]]);
    }

    public function test_two_applications_can_use_the_same_producer_event_id(): void
    {
        $id = (string) Str::uuid();

        $first = $this->send($this->radar, 'radar.ping', $id)->assertStatus(201);
        $second = $this->send($this->fleet, 'fleet.ping', $id)->assertStatus(201);

        $this->assertSame(2, Event::query()->where('producer_event_id', $id)->count());
        $this->assertSame($id, $first->json('data.event_id') ?? $first->json('event_id') ?? $id);
        $this->assertNotSame(
            Event::query()->where('source_application_id', $this->radar->id)->value('id'),
            Event::query()->where('source_application_id', $this->fleet->id)->value('id'),
        );
    }

    public function test_a_retry_from_the_same_application_replays_the_original(): void
    {
        $id = (string) Str::uuid();

        $this->send($this->radar, 'radar.ping', $id)->assertStatus(201);
        $this->send($this->radar, 'radar.ping', $id)->assertSuccessful();

        $this->assertSame(1, Event::query()->count());
    }

    public function test_the_same_id_for_a_different_fact_from_the_same_application_is_a_conflict(): void
    {
        $id = (string) Str::uuid();

        $this->send($this->radar, 'radar.ping', $id, 1)->assertStatus(201);
        $this->send($this->radar, 'radar.ping', $id, 2)->assertStatus(409)->assertJsonPath('error.code', 'EVENT_DUPLICATE');
    }

    public function test_the_producer_id_is_kept_as_the_nexus_id_when_it_is_free(): void
    {
        $id = (string) Str::uuid();

        $this->send($this->radar, 'radar.ping', $id)->assertStatus(201);

        $this->assertSame($id, Event::query()->sole()->id);
    }
}
