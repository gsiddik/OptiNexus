<?php

namespace Tests\Feature\Integration;

use App\Models\Application;
use App\Models\EventCatalogEntry;
use App\Models\EventDelivery;
use App\Models\EventSubscription;
use App\Models\Tenant;
use App\Services\Event\WebhookSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\Concerns\CreatesOrchestrationFixtures;
use Tests\TestCase;

class EventBrokerTest extends TestCase
{
    use CreatesIntegrationFixtures, CreatesOrchestrationFixtures, RefreshDatabase;

    private Application $fleet;

    private Application $deli;

    private Tenant $tenant;

    private string $fleetToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fleet = $this->makeApplication(['application_code' => 'optifleet', 'name' => 'OptiFleet', 'subscription_required' => false]);
        $this->deli = $this->makeApplication(['application_code' => 'optideli', 'name' => 'OptiDeli', 'subscription_required' => false]);
        $this->tenant = $this->makeTenant();
        foreach ([$this->fleet, $this->deli] as $application) {
            $this->tenant->applications()->attach($application->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);
        }
        EventCatalogEntry::create(['event_key' => 'optifleet.vehicle.status_changed', 'application_id' => $this->fleet->id, 'name' => 'Vehicle status changed', 'schema_version' => '1', 'status' => 'ACTIVE']);
        $this->fleetToken = $this->serviceToken($this->fleet, ['event.write']);
    }

    private function subscribe(string $pattern = 'optifleet.vehicle.*', ?string $tenantId = null, ?Application $application = null): array
    {
        $this->actingAsOrchestrationRole('EVENT_ADMIN');

        return $this->postJson('/api/v1/event-subscriptions', [
            'application_id' => ($application ?? $this->deli)->id, 'event_key_pattern' => $pattern,
            'tenant_id' => $tenantId, 'endpoint_url' => 'https://deli.test/api/v1/integration/optinexus/events',
        ])->assertStatus(201)->json('data');
    }

    private function publish(?Tenant $tenant = null): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->withToken($this->fleetToken)->postJson('/api/v1/events', [
            'event_key' => 'optifleet.vehicle.status_changed', 'event_id' => (string) Str::uuid(),
            'tenant_id' => ($tenant ?? $this->tenant)->id, 'data' => ['vehicle_id' => 'V-1', 'status' => 'UNAVAILABLE'],
        ]);
    }

    public function test_a_subscribed_application_receives_a_signed_webhook_for_its_tenant(): void
    {
        $subscription = $this->subscribe();
        Http::fake(['deli.test/*' => Http::response('', 204)]);

        $this->publish()->assertStatus(201);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) use ($subscription) {
            $body = json_decode($request->body(), true);
            $expected = app(WebhookSigner::class)->sign($subscription['signing_secret'], $request->header('Opti-Webhook-Id')[0], (int) $request->header('Opti-Webhook-Timestamp')[0], $request->body());

            return $request->url() === 'https://deli.test/api/v1/integration/optinexus/events'
                && $request->header('Opti-Webhook-Signature')[0] === 'v1='.$expected
                && $body['event_type'] === 'optifleet.vehicle.status_changed'
                && $body['source_system'] === 'optifleet'
                && $body['tenant_id'] === $this->tenant->id
                && $body['data']['status'] === 'UNAVAILABLE';
        });
        $this->assertSame(EventDelivery::STATUS_DELIVERED, EventDelivery::query()->where('consumer_type', 'WEBHOOK')->sole()->status);
    }

    public function test_nothing_is_sent_for_a_tenant_the_subscriber_is_not_assigned_to_or_another_tenant_scope(): void
    {
        $this->subscribe('optifleet.vehicle.status_changed', $this->tenant->id);
        Http::fake();
        $other = $this->makeTenant();
        $other->applications()->attach($this->fleet->id, ['id' => (string) Str::uuid(), 'status' => 'ACTIVE']);

        $this->publish($other)->assertStatus(201);

        Http::assertNothingSent();
        $this->assertSame(0, EventDelivery::query()->where('consumer_type', 'WEBHOOK')->count());
    }

    public function test_the_producer_never_receives_its_own_event_and_other_patterns_do_not_match(): void
    {
        $this->subscribe('optifleet.*', null, $this->fleet);
        $this->subscribe('optifleet.trip.*');
        Http::fake();

        $this->publish()->assertStatus(201);

        Http::assertNothingSent();
    }

    public function test_a_required_subscription_that_is_not_active_stops_delivery(): void
    {
        $this->deli->update(['subscription_required' => true]);
        $this->subscribe();
        Http::fake();

        $this->publish()->assertStatus(201);

        Http::assertNothingSent();
    }

    public function test_a_failing_endpoint_never_fails_the_producer_and_the_dead_letter_can_be_retried(): void
    {
        $this->subscribe();
        Http::fakeSequence('deli.test/*')->push('down', 503)->push('', 200);

        $this->publish()->assertStatus(201);
        $delivery = EventDelivery::query()->where('consumer_type', 'WEBHOOK')->sole();
        $this->assertSame(EventDelivery::STATUS_FAILED, $delivery->status); // the sync queue ends after one attempt
        $this->assertSame('HTTP_503', $delivery->last_error_code);

        $this->actingAsOrchestrationRole('EVENT_ADMIN');
        $this->postJson("/api/v1/event-deliveries/{$delivery->id}/retry")->assertOk();
        $this->assertSame(EventDelivery::STATUS_DELIVERED, $delivery->fresh()->status);
    }

    public function test_a_refusal_fails_at_once_and_a_disabled_subscription_is_skipped(): void
    {
        $subscription = $this->subscribe();
        Http::fake(['deli.test/*' => Http::response('gone', 410)]);
        $this->publish()->assertStatus(201);
        $this->assertSame('HTTP_410', EventDelivery::query()->where('consumer_type', 'WEBHOOK')->sole()->last_error_code);

        EventSubscription::query()->whereKey($subscription['id'])->update(['status' => 'DISABLED']);
        $this->publish()->assertStatus(201);
        $this->assertSame(1, EventDelivery::query()->where('consumer_type', 'WEBHOOK')->count());
    }

    public function test_the_api_requires_https_hides_the_secret_and_needs_the_manage_permission(): void
    {
        $this->actingAsOrchestrationRole('EVENT_ADMIN');
        $this->postJson('/api/v1/event-subscriptions', ['application_id' => $this->deli->id, 'event_key_pattern' => 'optifleet.vehicle.*', 'endpoint_url' => 'http://deli.test/hook'])
            ->assertStatus(422);
        $this->postJson('/api/v1/event-subscriptions', ['application_id' => $this->deli->id, 'event_key_pattern' => '*', 'endpoint_url' => 'https://deli.test/hook'])
            ->assertStatus(422);

        $created = $this->subscribe();
        $this->assertStringStartsWith('whsec_', $created['signing_secret']);
        $listed = $this->getJson('/api/v1/event-subscriptions')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('signing_secret', $listed);
        $rotated = $this->postJson("/api/v1/event-subscriptions/{$created['id']}/rotate-secret")->assertOk()->json('data.signing_secret');
        $this->assertNotSame($created['signing_secret'], $rotated);

        $this->actingAsOrchestrationRole('ORCHESTRATION_AUDITOR');
        $this->getJson('/api/v1/event-subscriptions')->assertOk();
        $this->postJson('/api/v1/event-subscriptions', ['application_id' => $this->deli->id, 'event_key_pattern' => 'optifleet.trip.*', 'endpoint_url' => 'https://deli.test/hook'])
            ->assertForbidden();
    }

    public function test_the_operator_command_writes_the_secret_only_to_a_private_file(): void
    {
        $file = sys_get_temp_dir().'/broker-'.Str::random(8).'.env';
        $this->artisan('ecosystem:register-event-subscription', ['application_code' => 'optideli', 'event_key_pattern' => 'optifleet.vehicle.*', '--url' => 'https://deli.test/hook', '--secret-file' => $file])
            ->assertSuccessful();
        $this->assertSame('0600', substr(sprintf('%o', fileperms($file)), -4));
        $this->assertStringStartsWith('EVENT_WEBHOOK_SECRET=whsec_', (string) file_get_contents($file));
        unlink($file);

        $this->artisan('ecosystem:register-event-subscription', ['application_code' => 'optideli', 'event_key_pattern' => 'optifleet.vehicle.*', '--url' => 'https://deli.test/v2'])
            ->assertSuccessful();
        $this->assertSame('https://deli.test/v2', EventSubscription::query()->sole()->endpoint_url);
        $this->artisan('ecosystem:register-event-subscription', ['application_code' => 'cgo', 'event_key_pattern' => 'optifleet.*', '--url' => 'https://x.test', '--secret-file' => $file])
            ->assertFailed();
    }
}
