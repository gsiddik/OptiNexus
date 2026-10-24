<?php

namespace Tests\Feature\Commercial;

use App\Models\Capability;
use App\Models\Plan;
use App\Models\Product;
use App\Services\Commercial\SubscriptionAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

/** Wave 0 (audit 2026-10-24): whether an application needs a subscription comes from catalog data, not from its code. */
class SubscriptionRequirementTest extends TestCase
{
    use CreatesGovernanceFixtures, RefreshDatabase;

    private function access(): SubscriptionAccess
    {
        return app(SubscriptionAccess::class);
    }

    public function test_an_application_that_is_not_sold_needs_no_subscription(): void
    {
        $this->assertFalse($this->access()->required($this->makeApplication()));
    }

    public function test_an_application_sold_through_a_product_requires_a_subscription_whatever_its_code(): void
    {
        $application = $this->makeApplication(['application_code' => 'optideli']);
        $product = Product::create(['product_code' => 'P-'.Str::random(8), 'name' => 'Deli', 'status' => 'ACTIVE', 'currency' => 'IDR']);
        $product->applications()->attach($application->id, ['id' => (string) Str::uuid()]);

        $this->assertTrue($this->access()->required($application));

        $tenant = $this->makeTenant();
        $this->assertFalse($this->access()->allowed($tenant, $application));
        $this->makeActiveApplicationSubscription($tenant, $application);
        $this->assertTrue($this->access()->allowed($tenant, $application));
    }

    public function test_an_application_whose_capability_is_in_a_plan_requires_a_subscription(): void
    {
        $application = $this->makeApplication();
        $capability = Capability::create(['application_id' => $application->id, 'code' => 'mod', 'name' => 'Module', 'type' => 'MODULE', 'status' => Capability::STATUS_ACTIVE]);
        $product = Product::create(['product_code' => 'P-'.Str::random(8), 'name' => 'Other', 'status' => 'ACTIVE', 'currency' => 'IDR']);
        $plan = Plan::create(['product_id' => $product->id, 'plan_code' => 'PL-'.Str::random(8), 'name' => 'Plan', 'status' => 'ACTIVE', 'currency' => 'IDR', 'billing_interval' => 'MONTHLY']);
        $plan->capabilities()->attach($capability->id, ['id' => (string) Str::uuid()]);

        $this->assertTrue($this->access()->required($application));
    }

    public function test_an_explicit_flag_overrides_the_catalog(): void
    {
        $free = $this->makeApplication(['application_code' => 'optifleet', 'subscription_required' => false]);
        $paid = $this->makeApplication(['subscription_required' => true]);

        $this->assertFalse($this->access()->required($free));
        $this->assertTrue($this->access()->required($paid));
        $this->assertTrue($this->access()->allowed($this->makeTenant(), $free));
        $this->assertFalse($this->access()->allowed($this->makeTenant(), $paid));
    }
}
