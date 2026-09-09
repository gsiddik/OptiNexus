<?php

namespace Tests\Feature\Commercial;

use App\Models\Billing;
use App\Models\Price;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    /**
     * Subscribes + activates a tenant, then reports 125 vehicles of usage
     * inside the subscription's current billing period.
     */
    private function activeSubscriptionWithUsage(int $vehicles = 125): array
    {
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $application = $this->makeApplication(['application_code' => 'optifleet']);
        $customer = $this->makeCustomer();
        $tenant = $this->makeTenant($customer);
        $this->grantApplicationAccess(User::factory()->create(), $tenant, $application);

        $product = $this->makeProduct();
        $product->applications()->attach($application->id, ['id' => (string) \Illuminate\Support\Str::uuid()]);
        $plan = $this->makePlan($product);
        $this->makeFlatPrice($plan, ['unit_amount' => '2000000.00']);
        $this->makeUsagePrice($plan, ['unit_amount' => '30000.00', 'included_quantity' => '100.0000', 'meter_key' => 'vehicles', 'unit_name' => 'vehicle']);

        $subscriptionId = $this->postJson('/api/v1/subscriptions', [
            'customer_id' => $customer->id, 'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
        ])->json('data.id');
        $this->postJson("/api/v1/subscriptions/{$subscriptionId}/activate")->assertStatus(200);

        $subscription = \App\Models\Subscription::findOrFail($subscriptionId);

        $token = $this->issueServiceAccountToken($application->id, ['usage.write']);
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/usage-events', [
            'tenant_id' => $tenant->id,
            'application_code' => 'optifleet',
            'subscription_id' => $subscription->id,
            'meter_key' => 'vehicles',
            'quantity' => $vehicles,
            'usage_timestamp' => $subscription->current_period_start->copy()->addDay()->toIso8601String(),
        ])->assertStatus(201);

        return [$tenant, $subscription];
    }

    public function test_billing_run_charges_flat_fee_plus_overage_and_leaves_usage_within_the_included_quantity_unbilled(): void
    {
        [, $subscription] = $this->activeSubscriptionWithUsage(125);
        $this->actingAsCommercialRole('BILLING_ADMIN');

        $response = $this->postJson('/api/v1/billing-runs', ['subscription_id' => $subscription->id])->assertStatus(201);
        $billing = $response->json('data.results.0');

        // 2,000,000 flat + (125-100=25 overage * 30,000) = 2,750,000
        $this->assertSame('2750000.00', $billing['total']);
        $this->assertSame('CALCULATED', $billing['status']);
    }

    public function test_billing_run_produces_no_charge_when_usage_is_within_the_included_quantity(): void
    {
        [, $subscription] = $this->activeSubscriptionWithUsage(80);
        $this->actingAsCommercialRole('BILLING_ADMIN');

        $response = $this->postJson('/api/v1/billing-runs', ['subscription_id' => $subscription->id])->assertStatus(201);

        $this->assertSame('2000000.00', $response->json('data.results.0.total'));
    }

    public function test_billing_editor_cannot_finalize_but_billing_approver_can(): void
    {
        [, $subscription] = $this->activeSubscriptionWithUsage(125);
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $billingId = $this->postJson('/api/v1/billing-runs', ['subscription_id' => $subscription->id])->json('data.results.0.id');

        // Segregation of duties: BILLING_ADMIN has no cgo.billing.finalize permission.
        $this->postJson("/api/v1/billings/{$billingId}/finalize")->assertStatus(403);

        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/billings/{$billingId}/finalize")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'FINALIZED');
    }

    public function test_finalized_billing_cannot_be_recalculated_or_cancelled(): void
    {
        [, $subscription] = $this->activeSubscriptionWithUsage(125);
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $billingId = $this->postJson('/api/v1/billing-runs', ['subscription_id' => $subscription->id])->json('data.results.0.id');

        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/billings/{$billingId}/finalize")->assertStatus(200);

        // recalculate requires cgo.billing.update, which BILLING_ADMIN (not
        // BILLING_APPROVER) holds - switch back to reach the business-logic
        // guard rather than a permission 403.
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $this->postJson("/api/v1/billings/{$billingId}/recalculate")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BILLING_ALREADY_FINALIZED');

        $this->actingAsCommercialRole('BILLING_APPROVER');

        $this->postJson("/api/v1/billings/{$billingId}/cancel")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'BILLING_ALREADY_FINALIZED');
    }

    public function test_billing_total_stays_correct_after_an_adjustment_is_applied(): void
    {
        [, $subscription] = $this->activeSubscriptionWithUsage(125);
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $billingId = $this->postJson('/api/v1/billing-runs', ['subscription_id' => $subscription->id])->json('data.results.0.id');

        $response = $this->postJson("/api/v1/billings/{$billingId}/adjustments", [
            'adjustment_type' => 'CREDIT',
            'reason' => 'Goodwill credit for a service incident.',
            'amount' => '-100000.00',
        ])->assertStatus(201);

        $this->assertSame('2650000.00', $response->json('data.total'));
    }

    public function test_a_second_billing_run_for_the_same_period_recalculates_the_same_draft_billing_not_a_duplicate(): void
    {
        [, $subscription] = $this->activeSubscriptionWithUsage(125);
        $this->actingAsCommercialRole('BILLING_ADMIN');

        $first = $this->postJson('/api/v1/billing-runs', ['subscription_id' => $subscription->id])->json('data.results.0.id');
        $second = $this->postJson('/api/v1/billing-runs', ['subscription_id' => $subscription->id])->json('data.results.0.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Billing::query()->where('subscription_id', $subscription->id)->count());
    }

    public function test_historical_price_change_does_not_alter_an_already_finalized_billing(): void
    {
        [, $subscription] = $this->activeSubscriptionWithUsage(125);
        $this->actingAsCommercialRole('BILLING_ADMIN');
        $billingId = $this->postJson('/api/v1/billing-runs', ['subscription_id' => $subscription->id])->json('data.results.0.id');

        $this->actingAsCommercialRole('BILLING_APPROVER');
        $this->postJson("/api/v1/billings/{$billingId}/finalize")->assertStatus(200);
        $totalBeforePriceChange = Billing::findOrFail($billingId)->total;

        // Raise the plan's flat price after finalization.
        $this->actingAsCommercialRole('COMMERCIAL_ADMIN');
        $plan = $subscription->plan;
        $this->postJson('/api/v1/prices', [
            'plan_id' => $plan->id, 'price_type' => Price::TYPE_FLAT, 'currency' => 'IDR',
            'unit_amount' => '9999999.00', 'billing_interval' => 'MONTHLY',
        ])->assertStatus(201);

        $billing = Billing::findOrFail($billingId);
        $this->assertSame($totalBeforePriceChange, $billing->total);
    }
}
