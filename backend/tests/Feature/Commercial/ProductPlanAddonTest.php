<?php

namespace Tests\Feature\Commercial;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesCommercialFixtures;
use Tests\Concerns\CreatesGovernanceFixtures;
use Tests\TestCase;

class ProductPlanAddonTest extends TestCase
{
    use CreatesCommercialFixtures, CreatesGovernanceFixtures, RefreshDatabase;

    public function test_product_manager_can_create_and_activate_a_product(): void
    {
        $this->actingAsCommercialRole('PRODUCT_MANAGER');

        $response = $this->postJson('/api/v1/products', [
            'product_code' => 'FLEET-X',
            'name' => 'Fleet X Suite',
            'currency' => 'IDR',
        ])->assertStatus(201);

        $response->assertJsonPath('data.status', 'DRAFT');
        $productId = $response->json('data.id');

        $this->postJson("/api/v1/products/{$productId}/activate")
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'ACTIVE');
    }

    public function test_product_cannot_skip_lifecycle_states(): void
    {
        $this->actingAsCommercialRole('PRODUCT_MANAGER');
        $product = $this->makeProduct(['status' => \App\Models\Product::STATUS_DRAFT]);

        // Cannot retire a DRAFT product - must go through ACTIVE/INACTIVE first.
        $this->postJson("/api/v1/products/{$product->id}/retire")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
    }

    public function test_user_without_product_permission_is_denied(): void
    {
        $this->seedCommercialBaseline();
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->postJson('/api/v1/products', ['product_code' => 'X', 'name' => 'X'])
            ->assertStatus(403);
    }

    public function test_subscription_admin_cannot_create_products(): void
    {
        // Segregation: catalog management is PRODUCT_MANAGER's job, not
        // SUBSCRIPTION_ADMIN's - the permission, not the role name, gates it.
        $this->actingAsCommercialRole('SUBSCRIPTION_ADMIN');

        $this->postJson('/api/v1/products', ['product_code' => 'X', 'name' => 'X'])
            ->assertStatus(403);
    }

    public function test_plan_can_be_cloned_with_capabilities_and_limits(): void
    {
        $this->actingAsCommercialRole('PRODUCT_MANAGER');
        $product = $this->makeProduct();
        $plan = $this->makePlan($product);
        $plan->limits()->create(['limit_key' => 'seats', 'limit_value' => '10.0000', 'is_unlimited' => false, 'unit' => 'seat']);

        $response = $this->postJson("/api/v1/plans/{$plan->id}/clone", [
            'name' => 'Cloned Plan',
            'plan_code' => 'CLONED-'.$plan->plan_code,
        ])->assertStatus(201);

        $cloneId = $response->json('data.id');
        $this->getJson("/api/v1/plans/{$cloneId}/limits")
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.limit_key', 'seats');
    }

    public function test_addon_limit_configure_accepts_negative_and_positive_deltas(): void
    {
        $this->actingAsCommercialRole('PRODUCT_MANAGER');
        $product = $this->makeProduct();
        $addon = $this->makeAddon($product);

        $this->postJson("/api/v1/addons/{$addon->id}/limits", [
            'limit_key' => 'vehicle_limit',
            'limit_delta' => 50,
            'unit' => 'vehicle',
        ])->assertStatus(200)->assertJsonPath('data.limit_delta', '50.0000');
    }

    public function test_plan_limit_remove_rejects_a_limit_belonging_to_another_plan(): void
    {
        $this->actingAsCommercialRole('PRODUCT_MANAGER');
        $product = $this->makeProduct();
        $planA = $this->makePlan($product);
        $planB = $this->makePlan($product);
        $limit = $planA->limits()->create(['limit_key' => 'seats', 'limit_value' => '5.0000', 'is_unlimited' => false]);

        $this->deleteJson("/api/v1/plans/{$planB->id}/limits/{$limit->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'RESOURCE_NOT_FOUND');
    }
}
