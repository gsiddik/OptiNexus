<?php

namespace Tests\Feature\Commercial;

use App\Models\Subscription;
use App\Services\Commercial\BillingCalculationService;
use App\Services\Commercial\SubscriptionPriceSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class SelectedSubscriptionPricingTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    public static function supportedTypes(): array
    {
        return array_map(fn ($type) => [$type], SubscriptionPriceSelection::TYPES);
    }

    #[DataProvider('supportedTypes')]
    public function test_creation_snapshots_the_selected_price_quantity_and_total(string $type): void
    {
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $customer = $this->makeCustomer();
        $tenant = $this->makeTenant($customer);
        $plan = $this->makePlan($this->makeProduct());
        $price = $this->makeFlatPrice($plan, ['price_type' => $type, 'unit_amount' => '12.35']);
        $this->makeFlatPrice($plan, ['price_type' => 'TIERED']);

        $response = $this->postJson('/api/v1/subscriptions', [
            'customer_id' => $customer->id, 'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
            'price_items' => [['price_id' => $price->id, 'quantity' => 3]],
            'unit_price' => '0.01',
        ])->assertCreated()->assertJsonPath('data.items.0.price_type', $type)
            ->assertJsonPath('data.items.0.quantity', $type === 'FLAT' ? '1.0000' : '3.0000')
            ->assertJsonPath('data.items.0.unit_price', '12.35')
            ->assertJsonPath('data.items.0.total_price', $type === 'FLAT' ? '12.35' : '37.05');

        $subscription = Subscription::findOrFail($response->json('data.id'));
        [$billing, $error] = app(BillingCalculationService::class)->calculate($subscription, now()->startOfMonth(), now()->endOfMonth());
        $this->assertNull($error);
        $this->assertCount(1, $billing->items);
        $this->assertSame($type === 'FLAT' ? '12.35' : '37.05', $billing->subtotal);
    }

    public function test_options_use_effective_tenant_override_and_exclude_unapproved_or_deferred_prices(): void
    {
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $tenant = $this->makeTenant();
        $plan = $this->makePlan($this->makeProduct());
        $standard = $this->makeFlatPrice($plan);
        $override = $this->makeFlatPrice($plan, ['tenant_id' => $tenant->id]);
        $this->makeFlatPrice($plan, ['price_type' => 'USAGE']);
        $this->makeFlatPrice($plan, ['price_type' => 'PER_DEVICE', 'approval_status' => 'DRAFT']);

        $this->getJson('/api/v1/subscriptions/options/prices?tenant_id='.$tenant->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $override->id)
            ->assertJsonMissing(['id' => $standard->id]);
    }

    public function test_other_tenants_price_and_missing_quantity_are_rejected_without_creating_subscription(): void
    {
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');
        $customer = $this->makeCustomer();
        $tenant = $this->makeTenant($customer);
        $other = $this->makeTenant();
        $plan = $this->makePlan($this->makeProduct());
        $price = $this->makeFlatPrice($plan, ['tenant_id' => $other->id, 'price_type' => 'PER_USER']);
        $payload = ['customer_id' => $customer->id, 'tenant_id' => $tenant->id, 'plan_id' => $plan->id,
            'price_items' => [['price_id' => $price->id]]];
        $this->postJson('/api/v1/subscriptions', $payload)->assertUnprocessable();
        $price->update(['tenant_id' => null]);
        $this->postJson('/api/v1/subscriptions', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('subscriptions', 0);
    }
}
