<?php

namespace Tests\Feature\Orchestration;

use App\Models\ServiceAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\ClientRepository;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\Concerns\CreatesOrchestrationFixtures;
use Tests\TestCase;

class EventManagementTest extends TestCase
{
    use CreatesGovernanceFixtures, CreatesOrchestrationFixtures, RefreshDatabase;

    private function registerCatalogEntry(string $key): void
    {
        $this->actingAsOrchestrationRole('EVENT_ADMIN');
        $this->postJson('/api/v1/event-catalog', [
            'event_key' => $key,
            'name' => 'Test Event',
            'schema_version' => '1',
            'payload_schema' => ['required' => ['amount'], 'properties' => ['amount' => ['type' => 'number']]],
        ])->assertStatus(201);
    }

    private function producerToken(array $scopes = ['event.write']): string
    {
        $client = app(ClientRepository::class)->createClientCredentialsGrantClient('test-event-producer');
        ServiceAccount::create(['oauth_client_id' => $client->id, 'name' => 'test-producer', 'status' => ServiceAccount::STATUS_ACTIVE]);

        $response = $this->postJson('/api/v1/oauth/token', [
            'grant_type' => 'client_credentials', 'client_id' => $client->id, 'client_secret' => $client->plainSecret,
            'scope' => implode(' ', $scopes),
        ])->assertStatus(200);

        return $response->json('access_token');
    }

    public function test_valid_event_is_ingested(): void
    {
        $this->registerCatalogEntry('et.valid.event');
        $token = $this->producerToken();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/events', ['event_key' => 'et.valid.event', 'data' => ['amount' => 10]])
            ->assertStatus(201);
    }

    public function test_invalid_schema_is_rejected(): void
    {
        $this->registerCatalogEntry('et.schema.event');
        $token = $this->producerToken();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/events', ['event_key' => 'et.schema.event', 'data' => ['amount' => 'not-a-number']])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EVENT_SCHEMA_INVALID');
    }

    public function test_duplicate_event_id_with_same_data_is_idempotent(): void
    {
        $this->registerCatalogEntry('et.dup.event');
        $token = $this->producerToken();
        $eventId = (string) \Illuminate\Support\Str::uuid();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/events', ['event_id' => $eventId, 'event_key' => 'et.dup.event', 'data' => ['amount' => 5]])
            ->assertStatus(201);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/events', ['event_id' => $eventId, 'event_key' => 'et.dup.event', 'data' => ['amount' => 5]])
            ->assertStatus(200);

        $this->assertSame(1, \App\Models\Event::where('event_key', 'et.dup.event')->count());
    }

    public function test_duplicate_event_id_with_different_data_is_rejected(): void
    {
        $this->registerCatalogEntry('et.conflict.event');
        $token = $this->producerToken();
        $eventId = (string) \Illuminate\Support\Str::uuid();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/events', ['event_id' => $eventId, 'event_key' => 'et.conflict.event', 'data' => ['amount' => 5]])
            ->assertStatus(201);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/events', ['event_id' => $eventId, 'event_key' => 'et.conflict.event', 'data' => ['amount' => 999]])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'EVENT_DUPLICATE');
    }

    public function test_unknown_event_key_is_rejected(): void
    {
        $token = $this->producerToken();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/events', ['event_key' => 'et.never.registered', 'data' => []])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EVENT_INVALID');
    }

    public function test_ingestion_requires_the_event_write_scope(): void
    {
        $this->registerCatalogEntry('et.scoped.event');
        $token = $this->producerToken(['governance.read']); // wrong scope

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/events', ['event_key' => 'et.scoped.event', 'data' => ['amount' => 1]])
            ->assertStatus(403);
    }

    public function test_human_session_cannot_ingest_events(): void
    {
        $this->registerCatalogEntry('et.human.event');

        // Sanctum-authenticated human session, not a service account token.
        $this->postJson('/api/v1/events', ['event_key' => 'et.human.event', 'data' => ['amount' => 1]])
            ->assertStatus(401);
    }
}
