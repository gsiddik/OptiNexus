<?php

namespace Tests\Feature\Integration;

use App\Models\Application;
use App\Models\Event;
use App\Models\EventCatalogEntry;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Services\Commercial\SubscriptionApplicationService;
use App\Services\Event\EventSubscriptionService;
use App\Services\Event\PlatformEvents;
use App\Services\Event\WebhookSigner;
use Database\Seeders\EcosystemCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class PlatformTenantEventsTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    private Application $radar;

    private Tenant $tenant;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCommercialBaseline();
        $this->radar = $this->makeApplication(['application_code' => 'optiradar', 'name' => 'OptiRadar', 'subscription_required' => false]);
        $this->tenant = $this->makeTenant(null, ['tenant_code' => 'ACME-ID', 'name' => 'Acme Indonesia']);
        [, $this->secret] = app(EventSubscriptionService::class)->create($this->radar, 'cgo.tenant.*', null, 'https://radar.test/api/optinexus/events', null);
        config(['event_broker.allow_http' => false]);
    }

    public function test_assigning_an_application_by_subscription_tells_that_application_with_a_signed_webhook(): void
    {
        Http::fake(['radar.test/*' => Http::response('', 202)]);

        $this->makeActiveApplicationSubscription($this->tenant, $this->radar);
        app(SubscriptionApplicationService::class)->sync($this->tenant);

        $event = Event::query()->where('event_key', PlatformEvents::TENANT_APPLICATION_ASSIGNED)->firstOrFail();
        $this->assertNull($event->source_application_id, 'a platform event has no producing application');
        $this->assertSame($this->tenant->id, $event->tenant_id);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) {
            $body = json_decode($request->body(), true);
            $expected = app(WebhookSigner::class)->sign($this->secret, $request->header('Opti-Webhook-Id')[0], (int) $request->header('Opti-Webhook-Timestamp')[0], $request->body());

            return $request->url() === 'https://radar.test/api/optinexus/events'
                && $request->header('Opti-Webhook-Signature')[0] === "v1={$expected}"
                && $body['event_type'] === 'cgo.tenant.application_assigned'
                && $body['source_system'] === 'optinexus'
                && $body['tenant_id'] === $this->tenant->id
                && $body['data']['payload'] === [
                    'application_code' => 'optiradar', 'application_name' => 'OptiRadar',
                    'tenant_code' => 'ACME-ID', 'tenant_name' => 'Acme Indonesia',
                ];
        });
    }

    public function test_the_assignment_is_delivered_before_the_subscription_is_active_even_when_one_is_required(): void
    {
        Http::fake(['radar.test/*' => Http::response('', 202)]);
        $this->radar->update(['subscription_required' => true]);
        $subscription = $this->makeActiveApplicationSubscription($this->tenant, $this->radar);
        Subscription::query()->whereKey($subscription->id)->update(['status' => 'DRAFT']);

        app(SubscriptionApplicationService::class)->sync($this->tenant);

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request) => json_decode($request->body(), true)['event_type'] === 'cgo.tenant.application_assigned');
    }

    public function test_a_sync_that_changes_nothing_publishes_nothing(): void
    {
        Http::fake(['radar.test/*' => Http::response('', 202)]);
        $this->makeActiveApplicationSubscription($this->tenant, $this->radar);
        app(SubscriptionApplicationService::class)->sync($this->tenant);
        Http::assertSentCount(1);

        app(SubscriptionApplicationService::class)->sync($this->tenant);

        Http::assertSentCount(1);
        $this->assertSame(1, Event::query()->where('event_key', PlatformEvents::TENANT_APPLICATION_ASSIGNED)->count());
    }

    public function test_an_application_is_not_told_about_a_tenant_it_is_not_assigned_to(): void
    {
        Http::fake(['radar.test/*' => Http::response('', 202)]);
        $other = $this->makeApplication(['application_code' => 'optideli', 'name' => 'OptiDeli', 'subscription_required' => false]);

        $this->makeActiveApplicationSubscription($this->tenant, $other);
        app(SubscriptionApplicationService::class)->sync($this->tenant);

        $this->assertSame(1, Event::query()->where('event_key', PlatformEvents::TENANT_APPLICATION_ASSIGNED)->count());
        Http::assertNothingSent();
    }

    public function test_ending_the_subscription_publishes_a_removal(): void
    {
        Http::fake(['radar.test/*' => Http::response('', 202)]);
        $deli = $this->makeApplication(['application_code' => 'optideli', 'name' => 'OptiDeli', 'subscription_required' => false]);
        $this->makeActiveApplicationSubscription($this->tenant, $deli);
        $radarSubscription = $this->makeActiveApplicationSubscription($this->tenant, $this->radar);
        app(SubscriptionApplicationService::class)->sync($this->tenant);

        Subscription::query()->whereKey($radarSubscription->id)->update(['status' => 'TERMINATED']);
        app(SubscriptionApplicationService::class)->sync($this->tenant);

        $this->assertSame(1, Event::query()->where('event_key', PlatformEvents::TENANT_APPLICATION_REMOVED)->count());
    }

    public function test_the_seeder_registers_the_platform_events_without_an_application(): void
    {
        $this->seed(EcosystemCatalogSeeder::class);

        foreach (array_keys(PlatformEvents::catalog()) as $key) {
            $entry = EventCatalogEntry::query()->where('event_key', $key)->firstOrFail();
            $this->assertNull($entry->application_id);
            $this->assertSame(EventCatalogEntry::STATUS_ACTIVE, $entry->status);
        }
    }
}
