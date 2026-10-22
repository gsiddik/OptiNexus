<?php

namespace Tests\Feature\Commercial;

use App\Models\OidcSession;
use App\Models\Subscription;
use App\Services\AuthorizationService;
use App\Services\Commercial\SubscriptionLifecycleService;
use App\Services\Oidc\SsoAccessResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesIntegrationFixtures;
use Tests\TestCase;

class SubscriptionDeadlinesTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesIntegrationFixtures, RefreshDatabase;

    public function test_grace_days_are_validated_and_calculated_from_period_end(): void
    {
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $tenant = $this->makeTenant();
        $plan = $this->makePlan($this->makeProduct());
        $data = ['customer_id' => $tenant->customer_id, 'tenant_id' => $tenant->id, 'plan_id' => $plan->id];
        foreach ([-1, 1.5, 3651] as $invalid) {
            $this->postJson('/api/v1/subscriptions', $data + ['grace_period_days' => $invalid])->assertUnprocessable();
        }
        $id = $this->postJson('/api/v1/subscriptions', $data + ['grace_period_days' => 7])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/subscriptions/{$id}/activate")->assertOk()->assertJsonPath('data.grace_period_days', 7);
        $sub = Subscription::findOrFail($id);
        $this->assertTrue($sub->grace_end_at->equalTo($sub->current_period_end->copy()->addDays(7)));
    }

    public function test_exact_deadlines_expire_then_suspend_once_and_renew_from_today(): void
    {
        $this->freezeTime();
        $tenant = $this->makeTenant();
        $fleet = $this->makeApplication(['application_code' => 'optifleet']);
        $sub = $this->makeActiveApplicationSubscription($tenant, $fleet);
        $end = $sub->current_period_end;
        $this->travelTo($end->copy()->subSecond());
        $this->artisan('subscriptions:process-deadlines')->assertSuccessful();
        $this->assertSame('ACTIVE', $sub->fresh()->status);
        $this->travelTo($end);
        $this->artisan('subscriptions:process-deadlines')->assertSuccessful();
        $this->assertSame('EXPIRED', $sub->fresh()->status);
        $this->travelTo($end->copy()->addDays(2));
        $this->assertNull(app(SubscriptionLifecycleService::class)->renew($sub->fresh()));
        $renewed = $sub->fresh();
        $this->assertTrue($renewed->current_period_start->equalTo(now()));
        $this->assertTrue($renewed->grace_end_at->equalTo($renewed->current_period_end->copy()->addDays(7)));
        $this->travelTo($renewed->grace_end_at);
        $this->artisan('subscriptions:process-deadlines')->assertSuccessful();
        $this->assertSame('SUSPENDED', $sub->fresh()->status);
        $this->artisan('subscriptions:process-deadlines')->assertSuccessful();
        $this->assertSame('SUSPENDED', $sub->fresh()->status);
    }

    public function test_access_requires_active_subscription_for_the_same_application_and_tenant(): void
    {
        $tenant = $this->makeTenant();
        $fleet = $this->makeApplication(['application_code' => 'optifleet']);
        $entry = $this->makeApplication(['application_code' => 'optiaccounting']);
        $user = $this->makeSsoUser($tenant, $fleet);
        $this->grantApplicationAccess($user, $tenant, $entry);
        $access = app(SsoAccessResolver::class);
        $this->assertTrue($access->canAccess($user, $fleet, $tenant));
        $this->assertFalse($access->canAccess($user, $entry, $tenant));
        $entrySub = $this->makeActiveApplicationSubscription($tenant, $entry);
        $sub = $tenant->subscriptions()->where('id', '!=', $entrySub->id)->firstOrFail();
        foreach (['DRAFT', 'TRIAL', 'EXPIRED', 'SUSPENDED', 'CANCELLED', 'TERMINATED'] as $status) {
            $sub->update(['status' => $status]);
            $this->assertFalse($access->canAccess($user, $fleet, $tenant), $status);
            $this->assertTrue($access->canAccess($user, $entry, $tenant));
            $this->assertSame('SUBSCRIPTION_INACTIVE', app(AuthorizationService::class)->effectiveAccess($user, $tenant, $fleet)['reason']);
        }
        $sub->update(['status' => 'ACTIVE', 'current_period_end' => now()]);
        $this->assertFalse($access->canAccess($user, $fleet, $tenant));
        $other = $this->makeTenant();
        $this->makeActiveApplicationSubscription($other, $fleet);
        $this->assertFalse($access->canAccess($user, $fleet, $tenant));
    }

    public function test_expiry_revokes_only_the_affected_application_session(): void
    {
        Queue::fake();
        $tenant = $this->makeTenant();
        $fleet = $this->makeApplication(['application_code' => 'optifleet']);
        $entry = $this->makeApplication(['application_code' => 'optiaccounting']);
        $user = $this->makeSsoUser($tenant, $fleet);
        $this->grantApplicationAccess($user, $tenant, $entry);
        $this->makeActiveApplicationSubscription($tenant, $entry);
        [$fleetClient] = $this->makeOidcClient($fleet);
        [$entryClient] = $this->makeOidcClient($entry);
        $sessions = [];
        foreach ([$fleetClient, $entryClient] as $client) {
            $sessions[] = OidcSession::create(['user_id' => $user->id, 'tenant_id' => $tenant->id, 'oidc_client_id' => $client->id, 'expires_at' => now()->addDays(40)]);
        }
        $sub = $tenant->subscriptions()->whereHas('product.applications', fn ($q) => $q->where('applications.id', $fleet->id))->firstOrFail();
        $sub->update(['current_period_end' => now(), 'grace_end_at' => now()->addDays(7)]);
        $this->artisan('subscriptions:process-deadlines')->assertSuccessful();
        $this->assertNotNull($sessions[0]->fresh()->ended_at);
        $this->assertNull($sessions[1]->fresh()->ended_at);
        $this->assertSame('ACTIVE', $user->fresh()->status);
        $this->assertSame('subscription_inactive', $sessions[0]->fresh()->end_reason);
    }

    public function test_cancelling_one_of_two_active_subscriptions_keeps_access_until_the_last_one_ends(): void
    {
        $tenant = $this->makeTenant();
        $fleet = $this->makeApplication(['application_code' => 'optifleet']);
        $user = $this->makeSsoUser($tenant, $fleet);
        $first = $tenant->subscriptions()->firstOrFail();
        $second = $this->makeActiveApplicationSubscription($tenant, $fleet);
        $lifecycle = app(SubscriptionLifecycleService::class);
        $this->assertNull($lifecycle->cancel($first));
        $this->assertTrue(app(SsoAccessResolver::class)->canAccess($user, $fleet, $tenant));
        $this->assertNull($lifecycle->cancel($second));
        $this->assertFalse(app(SsoAccessResolver::class)->canAccess($user, $fleet, $tenant));
    }
}
