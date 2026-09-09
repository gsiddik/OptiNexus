<?php

namespace Tests\Feature\Commercial;

use App\Models\Entitlement;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class SubscriptionLifecycleTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    private function subscribeTenant(): array
    {
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $customer = $this->makeCustomer();
        $tenant = $this->makeTenant($customer);
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $plan->limits()->create(['limit_key' => 'seats', 'limit_value' => '10.0000', 'is_unlimited' => false, 'unit' => 'seat']);
        $this->makeFlatPrice($plan);

        $response = $this->postJson('/api/v1/subscriptions', [
            'customer_id' => $customer->id,
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
        ])->assertStatus(201);

        return [$customer, $tenant, $product, $plan, $response->json('data.id')];
    }

    public function test_subscription_creation_is_rejected_when_tenant_does_not_belong_to_customer(): void
    {
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $customer = $this->makeCustomer();
        $otherTenant = $this->makeTenant($this->makeCustomer());
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);

        $this->postJson('/api/v1/subscriptions', [
            'customer_id' => $customer->id,
            'tenant_id' => $otherTenant->id,
            'plan_id' => $plan->id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'TENANT_CUSTOMER_MISMATCH');
    }

    public function test_subscription_creation_is_rejected_for_an_inactive_plan(): void
    {
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $customer = $this->makeCustomer();
        $tenant = $this->makeTenant($customer);
        $product = $this->makeProduct();
        $plan = $this->makePlan($product, ['status' => \App\Models\Plan::STATUS_DRAFT]);

        $this->postJson('/api/v1/subscriptions', [
            'customer_id' => $customer->id,
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
        ])->assertStatus(422)->assertJsonPath('error.code', 'PLAN_NOT_ACTIVE');
    }

    public function test_idempotency_key_prevents_duplicate_subscriptions(): void
    {
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $customer = $this->makeCustomer();
        $tenant = $this->makeTenant($customer);
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);

        $payload = [
            'customer_id' => $customer->id,
            'tenant_id' => $tenant->id,
            'plan_id' => $plan->id,
            'idempotency_key' => 'req-abc-123',
        ];

        $first = $this->postJson('/api/v1/subscriptions', $payload)->assertStatus(201);
        $second = $this->postJson('/api/v1/subscriptions', $payload)->assertStatus(200);

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Subscription::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_activating_a_subscription_generates_entitlements(): void
    {
        [, $tenant, , , $subscriptionId] = $this->subscribeTenant();

        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/activate")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'ACTIVE');

        $this->assertGreaterThan(0, Entitlement::query()->where('tenant_id', $tenant->id)->where('status', Entitlement::STATUS_ACTIVE)->count());
    }

    public function test_cannot_activate_an_already_active_subscription(): void
    {
        [, , , , $subscriptionId] = $this->subscribeTenant();
        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/activate")->assertStatus(200);

        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/activate")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_SUBSCRIPTION_TRANSITION');
    }

    public function test_suspending_a_subscription_suspends_its_entitlements_and_reactivating_restores_them(): void
    {
        [, $tenant, , , $subscriptionId] = $this->subscribeTenant();
        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/activate")->assertStatus(200);

        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/suspend")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'SUSPENDED');

        $this->assertSame(0, Entitlement::query()->where('tenant_id', $tenant->id)->where('status', Entitlement::STATUS_ACTIVE)->count());
        $this->assertGreaterThan(0, Entitlement::query()->where('tenant_id', $tenant->id)->where('status', Entitlement::STATUS_SUSPENDED)->count());

        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/reactivate")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'ACTIVE');

        $this->assertGreaterThan(0, Entitlement::query()->where('tenant_id', $tenant->id)->where('status', Entitlement::STATUS_ACTIVE)->count());
    }

    public function test_cancelling_a_subscription_revokes_entitlements_and_is_terminal(): void
    {
        [, $tenant, , , $subscriptionId] = $this->subscribeTenant();
        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/activate")->assertStatus(200);

        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->assertSame(0, Entitlement::query()->where('tenant_id', $tenant->id)->where('status', Entitlement::STATUS_ACTIVE)->count());

        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_SUBSCRIPTION_TRANSITION');
    }

    public function test_upgrade_rejects_a_plan_from_a_different_product(): void
    {
        [, , , , $subscriptionId] = $this->subscribeTenant();
        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/activate")->assertStatus(200);

        $otherProduct = $this->makeProduct();
        $otherPlan = $this->makePlan($otherProduct);

        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/upgrade", ['plan_id' => $otherPlan->id])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_SUBSCRIPTION_TRANSITION');
    }

    public function test_subscription_update_cannot_set_status_or_plan_directly(): void
    {
        [, , , , $subscriptionId] = $this->subscribeTenant();

        $response = $this->putJson("/api/v1/subscriptions/{$subscriptionId}", [
            'status' => 'ACTIVE',
            'auto_renew' => false,
        ])->assertStatus(200);

        // status is not a mass-assignable field on this endpoint - it stays DRAFT.
        $this->assertSame('DRAFT', $response->json('data.status'));
        $this->assertFalse($response->json('data.auto_renew'));
    }
}
