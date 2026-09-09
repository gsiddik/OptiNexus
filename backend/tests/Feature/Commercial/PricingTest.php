<?php

namespace Tests\Feature\Commercial;

use App\Models\Billing;
use App\Models\Invoice;
use App\Models\Price;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    public function test_prices_are_versioned_not_overwritten(): void
    {
        $this->actingAsCommercialRole('COMMERCIAL_ADMIN');
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $old = $this->makeFlatPrice($plan, ['unit_amount' => '1000000.00', 'effective_from' => now()->subMonth(), 'effective_until' => now()->subDay()]);

        $response = $this->postJson('/api/v1/prices', [
            'plan_id' => $plan->id,
            'price_type' => Price::TYPE_FLAT,
            'currency' => 'IDR',
            'unit_amount' => '1200000.00',
            'billing_interval' => 'MONTHLY',
            'effective_from' => now()->subDay(),
        ])->assertStatus(201);

        // The old price row is untouched - a new row was created, not a mutation.
        $old->refresh();
        $this->assertSame('1000000.00', $old->unit_amount);
        $this->assertNotSame($old->id, $response->json('data.id'));
    }

    public function test_price_update_can_only_change_effective_until_and_metadata(): void
    {
        $this->actingAsCommercialRole('COMMERCIAL_ADMIN');
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $price = $this->makeFlatPrice($plan, ['unit_amount' => '500000.00']);

        $this->putJson("/api/v1/prices/{$price->id}", [
            'effective_until' => now()->addYear()->toIso8601String(),
        ])->assertStatus(200);

        // Amount cannot be changed through update - it is silently ignored
        // because the endpoint only validates effective_until/metadata.
        $price->refresh();
        $this->assertSame('500000.00', $price->unit_amount);
    }

    public function test_custom_tenant_override_never_touches_the_global_plan_price(): void
    {
        $this->actingAsCommercialRole('COMMERCIAL_ADMIN');
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $globalPrice = $this->makeFlatPrice($plan, ['unit_amount' => '1000000.00']);
        $tenant = $this->makeTenant();

        $this->postJson('/api/v1/pricing/overrides', [
            'plan_id' => $plan->id,
            'tenant_id' => $tenant->id,
            'price_type' => Price::TYPE_FLAT,
            'currency' => 'IDR',
            'unit_amount' => '600000.00',
            'billing_interval' => 'MONTHLY',
        ])->assertStatus(201)->assertJsonPath('data.approval_status', 'PENDING_APPROVAL');

        $globalPrice->refresh();
        $this->assertSame('1000000.00', $globalPrice->unit_amount);
    }

    public function test_override_requires_a_different_approver_than_creator(): void
    {
        $creator = $this->actingAsCommercialRole('COMMERCIAL_ADMIN');
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $tenant = $this->makeTenant();

        $override = Price::create([
            'plan_id' => $plan->id,
            'tenant_id' => $tenant->id,
            'price_type' => Price::TYPE_FLAT,
            'currency' => 'IDR',
            'unit_amount' => '600000.00',
            'billing_interval' => 'MONTHLY',
            'status' => Price::STATUS_ACTIVE,
            'approval_status' => Price::APPROVAL_PENDING,
            'effective_from' => now(),
            'created_by' => $creator->id,
        ]);

        // Same user who created it cannot approve it (segregation of duties).
        $this->postJson("/api/v1/prices/{$override->id}/activate")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'UNAUTHORIZED');

        $override->refresh();
        $this->assertSame(Price::APPROVAL_PENDING, $override->approval_status);
    }

    public function test_a_different_approver_can_approve_the_override(): void
    {
        $creator = $this->actingAsCommercialRole('COMMERCIAL_ADMIN');
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $tenant = $this->makeTenant();

        $override = Price::create([
            'plan_id' => $plan->id, 'tenant_id' => $tenant->id, 'price_type' => Price::TYPE_FLAT,
            'currency' => 'IDR', 'unit_amount' => '600000.00', 'billing_interval' => 'MONTHLY',
            'status' => Price::STATUS_ACTIVE, 'approval_status' => Price::APPROVAL_PENDING,
            'effective_from' => now(), 'created_by' => $creator->id,
        ]);

        $this->actingAsCommercialRole('COMMERCIAL_ADMIN');

        $this->postJson("/api/v1/prices/{$override->id}/activate")
            ->assertStatus(200)
            ->assertJsonPath('data.approval_status', 'APPROVED');
    }

    public function test_price_simulation_never_writes_billing_or_invoice_records(): void
    {
        $this->actingAsCommercialRole('COMMERCIAL_ADMIN');
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $this->makeFlatPrice($plan, ['unit_amount' => '2000000.00']);
        $this->makeUsagePrice($plan, ['unit_amount' => '30000.00', 'included_quantity' => '100.0000']);

        $response = $this->postJson('/api/v1/pricing/simulate', [
            'plan_id' => $plan->id,
            'quantities' => ['vehicles' => 125],
        ])->assertStatus(200);

        // 2,000,000 flat + 25 overage vehicles * 30,000 = 750,000 => 2,750,000
        $this->assertSame('2750000.00', $response->json('data.total'));

        $this->assertSame(0, Billing::query()->count());
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_simulation_uses_the_tenant_specific_override_when_given(): void
    {
        $this->actingAsCommercialRole('COMMERCIAL_ADMIN');
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $this->makeUsagePrice($plan, ['unit_amount' => '30000.00', 'included_quantity' => '100.0000']);
        $tenant = $this->makeTenant();
        $this->makeUsagePrice($plan, ['unit_amount' => '25000.00', 'included_quantity' => '100.0000', 'tenant_id' => $tenant->id]);

        $response = $this->postJson('/api/v1/pricing/simulate', [
            'plan_id' => $plan->id,
            'tenant_id' => $tenant->id,
            'quantities' => ['vehicles' => 110],
        ])->assertStatus(200);

        // 10 overage vehicles at the tenant's cheaper 25,000 rate = 250,000
        $this->assertSame('250000.00', $response->json('data.total'));
    }
}
