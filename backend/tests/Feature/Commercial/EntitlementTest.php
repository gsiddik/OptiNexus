<?php

namespace Tests\Feature\Commercial;

use App\Models\Capability;
use App\Models\Entitlement;
use App\Models\User;
use App\Services\Commercial\EntitlementService;
use App\Services\Commercial\SubscriptionLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class EntitlementTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    private function activeSubscribedTenant(): array
    {
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $application = $this->makeApplication(['application_code' => 'optifleet']);
        $vehicleCapability = Capability::create(['application_id' => $application->id, 'type' => Capability::TYPE_FEATURE, 'code' => 'vehicle_feature', 'name' => 'Vehicle Feature', 'status' => 'ACTIVE']);
        $customer = $this->makeCustomer();
        $tenant = $this->makeTenant($customer);
        $this->grantApplicationAccess(User::factory()->create(), $tenant, $application);
        $product = $this->makeProduct();
        $product->applications()->attach($application->id, ['id' => (string) Str::uuid()]);
        $plan = $this->makePlan($product);
        $plan->capabilities()->attach($vehicleCapability->id, ['id' => (string) Str::uuid()]);
        $plan->limits()->create(['limit_key' => 'vehicle_limit', 'limit_value' => '100.0000', 'is_unlimited' => false, 'unit' => 'vehicle']);
        $this->makeFlatPrice($plan);

        $subscriptionId = $this->postJson('/api/v1/subscriptions', [
            'customer_id' => $customer->id, 'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        ])->json('data.id');

        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/activate")->assertStatus(200);

        return [$tenant, $application, $product, $plan];
    }

    public function test_effective_entitlements_merge_plan_and_addon_limits(): void
    {
        [$tenant, , $product, $plan] = $this->activeSubscribedTenant();
        $addon = $this->makeAddon($product);
        $addon->limits()->create(['limit_key' => 'vehicle_limit', 'limit_delta' => '50.0000', 'unit' => 'vehicle']);

        // Manually attach the addon item + regenerate, mirroring what a
        // subscription upgrade-with-addon flow would produce.
        $subscription = $tenant->subscriptions()->first();
        $subscription->items()->create(['item_type' => 'ADDON', 'addon_id' => $addon->id, 'quantity' => 1, 'currency' => 'IDR', 'start_at' => now()]);
        app(EntitlementService::class)->generateFromSubscription($subscription->refresh());

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/effective-entitlements")->assertStatus(200);

        $limit = collect($response->json('data.entitlements'))->firstWhere('entitlement_key', 'vehicle_limit');
        $this->assertEquals(150, $limit['value']);
    }

    public function test_manual_override_wins_over_plan_and_addon_sourced_entitlements(): void
    {
        [$tenant] = $this->activeSubscribedTenant();
        // COMMERCIAL_ADMIN holds cgo.entitlement.override; SUBSCRIPTION_ADMIN (the
        // acting role from activeSubscribedTenant()) deliberately does not.
        $this->actingAsCommercialRole('COMMERCIAL_ADMIN');

        $this->postJson("/api/v1/tenants/{$tenant->id}/entitlement-overrides", [
            'entitlement_type' => Entitlement::TYPE_LIMIT,
            'entitlement_key' => 'vehicle_limit',
            'value' => 9999,
            'reason' => 'Contractual exception for this tenant.',
        ])->assertStatus(201);

        $response = $this->getJson("/api/v1/tenants/{$tenant->id}/effective-entitlements")->assertStatus(200);
        $limit = collect($response->json('data.entitlements'))->firstWhere('entitlement_key', 'vehicle_limit');

        $this->assertSame('MANUAL_OVERRIDE', $limit['source']);
        $this->assertEquals(9999, $limit['value']);
    }

    public function test_suspended_subscription_denies_entitlement_checks(): void
    {
        [$tenant, $application] = $this->activeSubscribedTenant();
        $subscription = $tenant->subscriptions()->first();
        $this->postJson("/api/v1/subscriptions/{$subscription->id}/suspend")->assertStatus(200);

        $token = $this->issueServiceAccountToken($application->id, ['entitlement.check']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/entitlements/check', [
                'tenant_id' => $tenant->id,
                'application_code' => 'optifleet',
                'entitlement_key' => 'vehicle_limit',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.allowed', false);
    }

    public function test_active_subscription_grants_application_entitlement_via_machine_check(): void
    {
        [$tenant, $application] = $this->activeSubscribedTenant();
        $token = $this->issueServiceAccountToken($application->id, ['entitlement.check']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/entitlements/check', [
                'tenant_id' => $tenant->id,
                'application_code' => 'optifleet',
                'entitlement_key' => 'optifleet',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.allowed', true);
    }

    public function test_entitlement_check_without_service_account_token_is_rejected(): void
    {
        [$tenant] = $this->activeSubscribedTenant();

        $this->postJson('/api/v1/entitlements/check', [
            'tenant_id' => $tenant->id,
            'application_code' => 'optifleet',
            'entitlement_key' => 'vehicle_limit',
        ])->assertStatus(401);
    }

    public function test_human_bearer_token_cannot_call_the_machine_only_check_endpoint(): void
    {
        [$tenant] = $this->activeSubscribedTenant();
        $user = User::factory()->create(['status' => 'ACTIVE']);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/entitlements/check', [
            'tenant_id' => $tenant->id,
            'application_code' => 'optifleet',
            'entitlement_key' => 'vehicle_limit',
        ])->assertStatus(401);
    }

    public function test_resource_limits_are_scoped_to_the_calling_application(): void
    {
        [$tenant, $application, $product, $plan] = $this->activeSubscribedTenant();
        $entry = $this->makeApplication(['application_code' => 'optientry']);
        $entryProduct = $this->makeProduct();
        $entryProduct->applications()->attach($entry->id, ['id' => (string) Str::uuid()]);
        $entryPlan = $this->makePlan($entryProduct);
        $entryPlan->limits()->create(['limit_key' => 'vehicle_limit', 'limit_value' => 999, 'is_unlimited' => false]);
        [$entrySubscription] = app(SubscriptionLifecycleService::class)->create($tenant->customer, $tenant, $entryPlan);
        app(SubscriptionLifecycleService::class)->activate($entrySubscription);
        $token = $this->issueServiceAccountToken($application->id, ['commercial.read']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson("/api/v1/tenants/{$tenant->id}/commercial-context")->assertOk();
        $this->assertEquals(100, $response->json('data.resource_limits.vehicle_limit'));
    }

    public function test_commercial_context_requires_the_commercial_read_scope(): void
    {
        [$tenant, $application] = $this->activeSubscribedTenant();
        $token = $this->issueServiceAccountToken($application->id, ['entitlement.check']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/tenants/{$tenant->id}/commercial-context")
            ->assertStatus(403);
    }

    public function test_commercial_context_returns_active_subscription_and_entitlements(): void
    {
        [$tenant, $application] = $this->activeSubscribedTenant();
        $token = $this->issueServiceAccountToken($application->id, ['commercial.read']);

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/v1/tenants/{$tenant->id}/commercial-context")
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data.subscriptions'));
        $this->assertContains('optifleet', $response->json('data.applications_enabled'));
    }
}
